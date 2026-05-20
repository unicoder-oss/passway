<?php

declare(strict_types=1);

namespace Passway\Services;

/**
 * Service for generating cryptographically strong tokens.
 *
 * All tokens are generated through random_bytes() - CSPRNG.
 * Return values - hex strings for safe transfer in URLs/headers.
 *
 * Storage in DB:
 *   - Tokens NEVER are not stored in plaintext
 *   - DB stores a SHA-256 hash (HashingService::hashToken())
 *   - Exception: key_prefix API key - non-secret identifier for the UI
 */
final class TokenService
{
    // ------------------------------------------------------------------ //
    //  Session tokens
    // ------------------------------------------------------------------ //

    /**
     * Generate a session token.
     *
     * Format: 64 hex-characters (32 bytes of random data).
     * Sent to the client in a cookie. DB stores a SHA-256 hash.
     *
     * @return string 64 hex-characters
     */
    public function generateSessionToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------ //
    //  API keys                                                          //
    // ------------------------------------------------------------------ //

    /**
     * Generate an API key.
     *
     * Format: sv_{64 hex}
     *
     * The full key is given to the user ONCE on creation.
     * Only these are stored in the DB: SHA-256 hash (key_hash) and prefix (key_prefix).
     *
     * @return ApiKeyData          Full key plus metadata
     */
    public function generateApiKey(): ApiKeyData
    {
        $random   = bin2hex(random_bytes(32)); // 64 hex
        $fullKey  = "sv_{$random}";
        $prefix   = substr($fullKey, 0, 12);

        return new ApiKeyData(
            fullKey:     $fullKey,
            keyPrefix:   $prefix,
        );
    }

    // ------------------------------------------------------------------ //
    //  Invite tokens
    // ------------------------------------------------------------------ //

    /**
     * Generate invite-token.
     *
     * Format: 64 hex-characters.
     * Used in invite links. Valid for 1 hour and single-use.
     *
     * @return string 64 hex-characters
     */
    public function generateInviteToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------ //
    //  Setup-token (initial setup)                             //
    // ------------------------------------------------------------------ //

    /**
     * Generate an initial setup token.
     *
     * Printed to stdout and saved in setup_token.txt on first startup.
     * Single-use - expires after use.
     *
     * @return string 64 hex-characters
     */
    public function generateSetupToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------ //
    //  Tokens approval                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Generate a one-time access token after request approval.
     *
     * @return string 64 hex-characters
     */
    public function generateApprovalAccessToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------ //
    //  Helper methods                                             //
    // ------------------------------------------------------------------ //

    /**
     * Check that a string has API key format (for quick validation).
     */
    public function looksLikeApiKey(string $value): bool
    {
        return (bool) preg_match('/^sv_[0-9a-f]{64}$/', $value);
    }
}
