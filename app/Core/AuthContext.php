<?php

declare(strict_types=1);

namespace Passway\Core;

use Passway\Exceptions\AuthException;
use Passway\Models\ApiKey;
use Passway\Models\User;

/**
 * Static authentication context for the current request.
 *
 * Filled by AuthMiddleware before the controller is called.
 * Controllers read the current user through AuthContext::getUser().
 *
 * Intentionally static (request-scoped singleton), because PHP lives for one request.
 */
final class AuthContext
{
    private static ?User $user = null;
    private static ?ApiKey $apiKey = null;

    /**
     * Set the authenticated user (called from AuthMiddleware).
     */
    public static function setUser(?User $user): void
    {
        self::$user = $user;
    }

    public static function setApiKey(?ApiKey $apiKey): void
    {
        self::$apiKey = $apiKey;
    }

    /**
     * Get the current user or null if unauthenticated.
     */
    public static function getUser(): ?User
    {
        return self::$user;
    }

    public static function getApiKey(): ?ApiKey
    {
        return self::$apiKey;
    }

    /**
     * Check whether the user is authenticated.
     */
    public static function isAuthenticated(): bool
    {
        return self::$user !== null || self::$apiKey !== null;
    }

    public static function isApiKeyRequest(): bool
    {
        return self::$apiKey !== null;
    }

    /**
     * Get the current user or throw AuthException.
     * Used in controllers that require authentication.
     *
     * @throws AuthException
     */
    public static function requireUser(): User
    {
        if (self::$user === null) {
            throw new AuthException('Unauthenticated');
        }
        return self::$user;
    }

    /**
     * Reset (used in tests).
     */
    public static function reset(): void
    {
        self::$user = null;
        self::$apiKey = null;
    }
}
