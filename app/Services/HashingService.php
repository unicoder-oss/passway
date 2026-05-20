<?php

declare(strict_types=1);

namespace Passway\Services;

/**
 * Password and token hashing service.
 *
 * Passwords: Argon2id - modern algorithm, resistant to GPU brute force.
 *   - memory_cost: 64 MB (enough for protection without overloading the server)
 *   - time_cost:   4 iterations
 *   - threads:     1 (compatible with single-threaded environments)
 *
 * Tokens (sessions, API keys, invites): SHA-256 (hex).
 *   - Fast lookup by DB index
 *   - Protected because the source token is cryptographically random (32 bytes)
 *
 * IMPORTANT: for passwords NEVER do not use SHA-256 directly.
 *        Only Argon2id through password_hash().
 */
final class HashingService
{
    // Argon2id parameters (OWASP minimum recommendation 19 MB / 2 iterations)
    private const ARGON2_MEMORY_COST  = 65536;  // 64 MB (KiB)
    private const ARGON2_TIME_COST    = 4;       // iterations
    private const ARGON2_THREADS      = 1;

    // ------------------------------------------------------------------ //
    //  Passwords (Argon2id)                                                  //
    // ------------------------------------------------------------------ //

    /**
     * Hash the user password.
     *
     * @param string $password Plaintext password (will be wiped from memory)
     * @return string          Hash for storage in the DB
     */
    public function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => self::ARGON2_MEMORY_COST,
            'time_cost'   => self::ARGON2_TIME_COST,
            'threads'     => self::ARGON2_THREADS,
        ]);

        if ($hash === false) {
            throw new \RuntimeException(__('ui.backend.security.argon_unavailable'));
        }

        if (\function_exists('sodium_memzero')) { \sodium_memzero($password); }

        return $hash;
    }

    /**
     * Verify a password against the stored hash.
     *
     * Uses timing-safe comparison (password_verify does this internally).
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        $result = password_verify($password, $hash);
        if (\function_exists('sodium_memzero')) { \sodium_memzero($password); }
        return $result;
    }

    /**
     * Check whether rehashing is needed (if algorithm parameters changed).
     * Call after successful verifyPassword to update the hash in the DB.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => self::ARGON2_MEMORY_COST,
            'time_cost'   => self::ARGON2_TIME_COST,
            'threads'     => self::ARGON2_THREADS,
        ]);
    }

    // ------------------------------------------------------------------ //
    //  Tokens (SHA-256)                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Hash a session token for storage in the DB.
     * Input token: 64 hex characters (32 bytes of random data).
     *
     * @return string SHA-256 hex (64 characters)
     */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Hash an API key for storage in the DB.
     * Key format: sv_{64 hex}.
     *
     * @return string SHA-256 hex (64 characters)
     */
    public function hashApiKey(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /**
     * Hash an invite token for storage in the DB.
     *
     * @return string SHA-256 hex (64 characters)
     */
    public function hashInviteToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Timing-safe comparison of two strings (protection against timing attacks).
     * Use to compare hashes, not the tokens themselves.
     */
    public function timingSafeEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
