<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Models\User;

/**
 * Управление сессиями пользователей.
 *
 * Хранение:
 *   - cookie содержит raw-токен (64 hex символа)
 *   - в БД хранится SHA-256(raw-токен) — plaintext НИКОГДА не хранится
 *
 * TTL берётся из SESSION_TTL (.env), по умолчанию 86400 секунд (1 сутки).
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
    //  Создание                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Создать новую сессию. Возвращает raw-токен для записи в cookie.
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
    //  Валидация                                                          //
    // ------------------------------------------------------------------ //

    /**
     * Проверить сессию по raw-токену из cookie.
     * Возвращает User или null если сессия невалидна / истекла.
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

        // Обновляем время последней активности
        $db->update(
            'sessions',
            ['last_activity_at' => $now],
            ['token_hash' => $tokenHash]
        );

        return User::findById((int) $session['user_id']);
    }

    // ------------------------------------------------------------------ //
    //  Инвалидация                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Инвалидировать одну сессию (logout).
     */
    public function invalidate(string $rawToken): void
    {
        $tokenHash = $this->hashingService->hashToken($rawToken);
        Database::getInstance()->delete('sessions', ['token_hash' => $tokenHash]);
    }

    /**
     * Инвалидировать все сессии пользователя (смена пароля, подозрительная активность).
     */
    public function invalidateAll(string $userId): void
    {
        // Используем query() т.к. delete() поддерживает только равенство,
        // а нам нужно удалить по user_id (что тоже равенство — ок)
        Database::getInstance()->delete('sessions', ['user_id' => $userId]);
    }

    // ------------------------------------------------------------------ //
    //  Очистка                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Удалить все истёкшие сессии. Вызывается cron-задачей.
     * Возвращает количество удалённых строк.
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
     * Записать session-cookie в ответ (вызывать до отправки headers).
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
     * Удалить session-cookie (logout).
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
     * Прочитать raw-токен из cookie.
     */
    public function getTokenFromCookie(): ?string
    {
        $name = $_ENV['SESSION_COOKIE_NAME'] ?? 'passway_session';
        $value = $_COOKIE[$name] ?? null;
        return \is_string($value) && \strlen($value) === 64 ? $value : null;
    }
}
