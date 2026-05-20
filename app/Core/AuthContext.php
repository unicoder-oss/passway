<?php

declare(strict_types=1);

namespace Passway\Core;

use Passway\Exceptions\AuthException;
use Passway\Models\User;

/**
 * Статический контекст аутентификации для текущего запроса.
 *
 * Заполняется AuthMiddleware до вызова контроллера.
 * Контроллеры читают текущего пользователя через AuthContext::getUser().
 *
 * Намеренно статический (request-scoped singleton), т.к. PHP живёт один запрос.
 */
final class AuthContext
{
    private static ?User $user = null;

    /**
     * Установить аутентифицированного пользователя (вызывается из AuthMiddleware).
     */
    public static function setUser(?User $user): void
    {
        self::$user = $user;
    }

    /**
     * Получить текущего пользователя или null если не аутентифицирован.
     */
    public static function getUser(): ?User
    {
        return self::$user;
    }

    /**
     * Проверить, аутентифицирован ли пользователь.
     */
    public static function isAuthenticated(): bool
    {
        return self::$user !== null;
    }

    /**
     * Получить текущего пользователя или бросить AuthException.
     * Используется в контроллерах, требующих аутентификации.
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
     * Сброс (используется в тестах).
     */
    public static function reset(): void
    {
        self::$user = null;
    }
}
