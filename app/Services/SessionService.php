<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Models\User;

/**
 * User session management.
 *
 * Storage:
 *   - cookie contains raw-token (64 hex characters)
 *   - DB stores SHA-256(raw-token); plaintext is NEVER stored
 *
 * TTL is taken from SESSION_TTL (.env), default 86400 seconds (1 day).
 */
final class SessionService
{
    private readonly int $ttl;

    public function __construct(
        private readonly TokenService   $tokenService,
        private readonly HashingService $hashingService,
    ) {
        $this->ttl = (int) ($_ENV['SESSION_TTL'] ?? 86400);
    }

    // ------------------------------------------------------------------ //
    //  Creation                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Create a new session. Returns the raw token to write to the cookie.
     */
    public function create(string $userId, ?string $ip, ?string $userAgent): string
    {
        $rawToken  = $this->tokenService->generateSessionToken();
        $tokenHash = $this->hashingService->hashToken($rawToken);
        $now       = now()->format('Y-m-d H:i:s');
        $expiresAt = \date('Y-m-d H:i:s', \time() + $this->ttl);

        Database::getInstance()->insert('sessions', [
            'uuid'             => generate_uuid(),
            'user_id'          => $userId,
            'token_hash'       => $tokenHash,
            'ip_address'       => $ip,
            'user_agent'       => $userAgent ? \substr($userAgent, 0, 512) : null,
            'expires_at'       => $expiresAt,
            'created_at'       => $now,
            'last_activity_at' => $now,
        ]);

        return $rawToken;
    }

    // ------------------------------------------------------------------ //
    //  Validation                                                          //
    // ------------------------------------------------------------------ //

    /**
     * Check a session by the raw token from the cookie.
     * Returns User or null if the session is invalid / expired.
     */
    public function validate(string $rawToken): ?User
    {
        $tokenHash = $this->hashingService->hashToken($rawToken);
        $now       = now()->format('Y-m-d H:i:s');

        $db = Database::getInstance();

        $session = $db->fetchOne(
            'SELECT * FROM sessions WHERE token_hash = ? AND expires_at > ?',
            [$tokenHash, $now]
        );

        if ($session === null) {
            return null;
        }

        // Update last activity time
        $db->update(
            'sessions',
            ['last_activity_at' => $now],
            ['token_hash' => $tokenHash]
        );

        return User::findById((int) $session['user_id']);
    }

    // ------------------------------------------------------------------ //
    //  Invalidation                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Invalidate one session (logout).
     */
    public function invalidate(string $rawToken): void
    {
        $tokenHash = $this->hashingService->hashToken($rawToken);
        Database::getInstance()->delete('sessions', ['token_hash' => $tokenHash]);
    }

    /**
     * Invalidate all user sessions (password change, suspicious activity).
     */
    public function invalidateAll(string $userId): void
    {
        // Use query() because delete() supports only equality,
        // and we need to delete by user_id (which is also equality, OK)
        Database::getInstance()->delete('sessions', ['user_id' => $userId]);
    }

    // ------------------------------------------------------------------ //
    //  Cleanup                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Delete all expired sessions. Called by a cron job.
     * Returns the number of deleted rows.
     */
    public function cleanup(): int
    {
        if (!Database::getInstance()->tableExists('sessions')) {
            return 0;
        }

        $stmt = Database::getInstance()->query(
            'DELETE FROM sessions WHERE expires_at < ?',
            [now()->format('Y-m-d H:i:s')]
        );
        return $stmt->rowCount();
    }

    // ------------------------------------------------------------------ //
    //  Cookie                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Write the session cookie to the response (call before sending headers).
     */
    public function setCookie(string $rawToken): void
    {
        $name     = $_ENV['SESSION_COOKIE_NAME'] ?? 'passway_session';
        $secure   = filter_var($_ENV['SESSION_COOKIE_SECURE'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $sameSite = 'Strict';

        \setcookie($name, $rawToken, [
            'expires'  => \time() + $this->ttl,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    /**
     * Delete the session cookie (logout).
     */
    public function clearCookie(): void
    {
        $name = $_ENV['SESSION_COOKIE_NAME'] ?? 'passway_session';
        $secure = filter_var($_ENV['SESSION_COOKIE_SECURE'] ?? false, FILTER_VALIDATE_BOOLEAN);
        \setcookie($name, '', [
            'expires'  => \time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => (string) ($_ENV['SESSION_COOKIE_SAMESITE'] ?? 'Strict'),
        ]);
    }

    /**
     * Read the raw token from the cookie.
     */
    public function getTokenFromCookie(): ?string
    {
        $name = $_ENV['SESSION_COOKIE_NAME'] ?? 'passway_session';
        $value = $_COOKIE[$name] ?? null;
        return \is_string($value) && \strlen($value) === 64 ? $value : null;
    }
}
