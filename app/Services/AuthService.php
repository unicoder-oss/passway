<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\User;

/**
 * Authentication service: email+password, TOTP pending, logs.
 *
 * Returned statuses loginWithPassword():
 *   'success'        → ['status', 'user', 'raw_token']
 *   'totp_required'  -> ['status']  (pending user_id saved in PHP session)
 */
final class AuthService
{
    /** Maximum failed attempts per window before blocking */
    private const RATE_LIMIT_MAX     = 5;
    /** Rate limiting window in seconds (15 minutes) */
    private const RATE_LIMIT_WINDOW  = 900;
    /** TTL pending-TOTP session in seconds (5 minutes) */
    private const TOTP_PENDING_TTL   = 300;

    public function __construct(
        private readonly HashingService $hashingService,
        private readonly SessionService $sessionService,
        private readonly ?AuditService  $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Email + password                                                     //
    // ------------------------------------------------------------------ //

    /**
     * Login attempt by email and password.
     *
     * @return array{status: string, user?: User, raw_token?: string}
     * @throws AuthException on invalid credentials / rate limit / inactive
     */
    public function loginWithPassword(
        string  $email,
        string  $password,
        ?string $ip,
        ?string $userAgent,
    ): array {
        // 1. Check setup_complete
        $this->assertSetupComplete();

        // 2. Rate limiting by IP
        $this->assertRateLimit($ip);

        // 3. Find user
        $email = \strtolower(\trim($email));
        $user  = User::findByEmail($email);

        if ($user === null || $user->passwordHash === null) {
            // Do not reveal that the user does not exist
            $this->recordFailedAttempt($ip);
            $this->writeAuditLog(null, 'auth.login_fail', $ip, $userAgent, false, [
                'email'  => $email,
                'reason' => 'user_not_found',
            ]);
            throw new AuthException(__('ui.backend.auth.invalid_credentials'));
        }

        // 4. Check account activity
        if (!$user->isActive) {
            $this->recordFailedAttempt($ip);
            $this->writeAuditLog($user->id, 'auth.login_fail', $ip, $userAgent, false, [
                'reason' => 'account_inactive',
            ]);
            throw new AuthException(__('ui.backend.auth.account_inactive'));
        }

        // 5. Check password
        if (!$this->hashingService->verifyPassword($password, $user->passwordHash)) {
            $this->recordFailedAttempt($ip);
            $this->writeAuditLog($user->id, 'auth.login_fail', $ip, $userAgent, false, [
                'reason' => 'wrong_password',
            ]);
            throw new AuthException(__('ui.backend.auth.invalid_credentials'));
        }

        // 6. Rehash the password if parameters changed
        if ($this->hashingService->needsRehash($user->passwordHash)) {
            $newHash = $this->hashingService->hashPassword($password);
            $user->update(['password_hash' => $newHash]);
        }

        // 7. TOTP: if enabled, defer session creation until code entry
        if ($user->totpEnabled) {
            $this->storeTotpPending($user->id, $ip);
            $this->writeAuditLog($user->id, 'auth.totp_pending', $ip, $userAgent, true);
            return ['status' => 'totp_required'];
        }

        // 8. Create a session
        $rawToken = $this->sessionService->create($user->id, $ip, $userAgent);

        // 9. Update last_login
        $user->update([
            'last_login_at' => now()->format('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);

        $this->writeAuditLog($user->id, 'auth.login_success', $ip, $userAgent, true, [
            'method' => 'password',
        ]);

        return [
            'status'    => 'success',
            'user'      => $user,
            'raw_token' => $rawToken,
        ];
    }

    // ------------------------------------------------------------------ //
    //  TOTP-pending                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Save "TOTP pending" in the PHP session after successful password verification.
     */
    private function storeTotpPending(string $userId, ?string $ip): void
    {
        $this->ensureSessionStarted();
        $_SESSION['totp_pending'] = [
            'user_id'  => $userId,
            'ip'       => $ip,
            'expires'  => \time() + self::TOTP_PENDING_TTL,
        ];
    }

    /**
     * Complete login after TOTP verification.
     * Called from TotpController::verify().
     *
     * @return array{status: string, user: User, raw_token: string}
     * @throws AuthException
     */
    public function completeTotpLogin(string $userAgent): array
    {
        $this->ensureSessionStarted();

        $pending = $_SESSION['totp_pending'] ?? null;

        if ($pending === null || $pending['expires'] < \time()) {
            unset($_SESSION['totp_pending']);
            throw new AuthException(__('ui.backend.auth.totp_session_expired'));
        }

        $user = User::findById((int) $pending['user_id']);
        if ($user === null || !$user->isActive) {
            unset($_SESSION['totp_pending']);
            throw new AuthException(__('ui.backend.auth.user_not_found_or_inactive'));
        }

        unset($_SESSION['totp_pending']);

        $ip       = $pending['ip'] ?? null;
        $rawToken = $this->sessionService->create($user->id, $ip, $userAgent);

        $user->update([
            'last_login_at' => now()->format('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);

        $this->writeAuditLog($user->id, 'auth.login_success', $ip, $userAgent, true, [
            'method' => 'password+totp',
        ]);

        return [
            'status'    => 'success',
            'user'      => $user,
            'raw_token' => $rawToken,
        ];
    }

    /**
     * Get user_id from pending TOTP (without completion - for TotpService).
     * @throws AuthException if missing pending-session
     */
    public function getPendingTotpUserId(): string
    {
        $this->ensureSessionStarted();
        $pending = $_SESSION['totp_pending'] ?? null;

        if ($pending === null || $pending['expires'] < \time()) {
            unset($_SESSION['totp_pending']);
            throw new AuthException(__('ui.backend.auth.totp_session_expired'));
        }

        return (string) $pending['user_id'];
    }

    // ------------------------------------------------------------------ //
    //  Setup-check                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Check that setup is complete (setup_complete = '1').
     * @throws AuthException with code 503 if missing
     */
    public function assertSetupComplete(): void
    {
        $value = Database::getInstance()->fetchColumn(
            "SELECT value FROM system_config WHERE key = 'setup_complete'"
        );

        if ($value !== '1') {
            throw new AuthException(__('ui.backend.auth.setup_incomplete'), 503);
        }
    }

    // ------------------------------------------------------------------ //
    //  Rate limiting                                                      //
    // ------------------------------------------------------------------ //

    /**
     * Check rate limit for this IP.
     * @throws AuthException 429 if the limit is exceeded
     */
    private function assertRateLimit(?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $windowStart = \date('Y-m-d H:i:s', \time() - self::RATE_LIMIT_WINDOW);

        $db  = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT count, window_start FROM rate_limit_log WHERE ip_address = ? AND bucket = ?',
            [$ip, 'auth']
        );

        if ($row === null) {
            return; // Нет записи — не заблокирован
        }

        // If the window expired - the record is stale, do not block
        if ($row['window_start'] < $windowStart) {
            return;
        }

        if ((int) $row['count'] >= self::RATE_LIMIT_MAX) {
            throw new AuthException(
                __('ui.backend.auth.rate_limited'),
                429
            );
        }
    }

    /**
     * Record a failed attempt (increment or reset the window).
     */
    private function recordFailedAttempt(?string $ip): void
    {
        if ($ip === null) {
            return;
        }

        $db          = Database::getInstance();
        $now         = now()->format('Y-m-d H:i:s');
        $windowStart = \date('Y-m-d H:i:s', \time() - self::RATE_LIMIT_WINDOW);

        $row = $db->fetchOne(
            'SELECT id, count, window_start FROM rate_limit_log WHERE ip_address = ? AND bucket = ?',
            [$ip, 'auth']
        );

        if ($row === null) {
            // First failed request - insert
            try {
                $db->insert('rate_limit_log', [
                    'ip_address'   => $ip,
                    'bucket'       => 'auth',
                    'count'        => 1,
                    'window_start' => $now,
                    'updated_at'   => $now,
                ]);
            } catch (\Exception) {
                // Race - another request has already inserted, increment count
                $db->query(
                    'UPDATE rate_limit_log SET count = count + 1, updated_at = ? WHERE ip_address = ? AND bucket = ?',
                    [$now, $ip, 'auth']
                );
            }
        } elseif ($row['window_start'] < $windowStart) {
            // Window expired - reset the counter
            $db->update(
                'rate_limit_log',
                ['count' => 1, 'window_start' => $now, 'updated_at' => $now],
                ['ip_address' => $ip, 'bucket' => 'auth']
            );
        } else {
            // Within the window - increment
            $db->query(
                'UPDATE rate_limit_log SET count = count + 1, updated_at = ? WHERE ip_address = ? AND bucket = ?',
                [$now, $ip, 'auth']
            );
        }
    }

    // ------------------------------------------------------------------ //
    //  Audit log                                                          //
    // ------------------------------------------------------------------ //

    /**
     * @param array<string, mixed> $details
     */
    public function writeAuditLog(
        ?string $userId,
        string  $action,
        ?string $ip,
        ?string $userAgent = null,
        bool    $success = true,
        array   $details = [],
    ): void {
        $this->getAuditService()->record(
            action: $action,
            userId: $userId,
            ipAddress: $ip,
            userAgent: $userAgent,
            details: $details,
            success: $success,
        );
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    private function ensureSessionStarted(): void
    {
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService());
    }
}
