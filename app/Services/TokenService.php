<?php

declare(strict_types=1);

namespace Passway\Services;

/**
 * Сервис генерации криптографически стойких токенов.
 *
 * Все токены генерируются через random_bytes() — CSPRNG.
 * Возвращаемые значения — hex-строки для безопасной передачи в URL/заголовках.
 *
 * Хранение в БД:
 *   - Токены НИКОГДА не хранятся в открытом виде
 *   - В БД хранится SHA-256 хеш (HashingService::hashToken())
 *   - Исключение: key_prefix API-ключа — несекретный идентификатор для UI
 */
final class TokenService
{
    // ------------------------------------------------------------------ //
    //  Сессионные токены                                                  //
    // ------------------------------------------------------------------ //

    /**
     * Сгенерировать сессионный токен.
     *
     * Формат: 64 hex-символа (32 байта случайных данных).
     * Передаётся клиенту в cookie. В БД хранится SHA-256 хеш.
     *
     * @return string 64 hex-символа
     */
    public function generateSessionToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------ //
    //  API-ключи                                                          //
    // ------------------------------------------------------------------ //

    /**
     * Сгенерировать API-ключ.
     *
     * Формат: sv_{env}_{64 hex}
     *   - sv_prod_  → production
     *   - sv_stg_   → staging
     *   - sv_dev_   → development
     *
     * Полный ключ передаётся пользователю ОДИН раз при создании.
     * В БД хранятся только: SHA-256 хеш (key_hash) и префикс (key_prefix).
     *
     * @param string $environment  production | staging | development
     * @return ApiKeyData          Полный ключ + метаданные
     */
    public function generateApiKey(string $environment = 'production'): ApiKeyData
    {
        $envCode = match ($environment) {
            'production'  => 'prod',
            'staging'     => 'stg',
            'development' => 'dev',
            default       => 'prod',
        };

        $random   = bin2hex(random_bytes(32)); // 64 hex
        $fullKey  = "sv_{$envCode}_{$random}";
        $prefix   = "sv_{$envCode}_";

        return new ApiKeyData(
            fullKey:     $fullKey,
            keyPrefix:   $prefix,
            environment: $environment,
        );
    }

    // ------------------------------------------------------------------ //
    //  Инвайт-токены                                                      //
    // ------------------------------------------------------------------ //

    /**
     * Сгенерировать инвайт-токен.
     *
     * Формат: 64 hex-символа.
     * Используется в ссылках-приглашениях. Действует 1 час, одноразовый.
     *
     * @return string 64 hex-символа
     */
    public function generateInviteToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------ //
    //  Setup-токен (первоначальная настройка)                             //
    // ------------------------------------------------------------------ //

    /**
     * Сгенерировать токен первоначальной настройки.
     *
     * Выводится в stdout и сохраняется в setup_token.txt при первом запуске.
     * Одноразовый — сгорает после использования.
     *
     * @return string 64 hex-символа
     */
    public function generateSetupToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------ //
    //  Токены одобрений                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Сгенерировать одноразовый токен доступа после одобрения запроса.
     *
     * @return string 64 hex-символа
     */
    public function generateApprovalAccessToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------ //
    //  Вспомогательные методы                                             //
    // ------------------------------------------------------------------ //

    /**
     * Извлечь окружение из API-ключа по префиксу.
     *
     * @param string $keyOrPrefix  Полный ключ или только префикс
     * @return string|null         production | staging | development | null
     */
    public function extractEnvironmentFromApiKey(string $keyOrPrefix): ?string
    {
        return match (true) {
            str_starts_with($keyOrPrefix, 'sv_prod_') => 'production',
            str_starts_with($keyOrPrefix, 'sv_stg_')  => 'staging',
            str_starts_with($keyOrPrefix, 'sv_dev_')  => 'development',
            default                                    => null,
        };
    }

    /**
     * Проверить, что строка имеет формат API-ключа (для быстрой валидации).
     */
    public function looksLikeApiKey(string $value): bool
    {
        return (bool) preg_match('/^sv_(prod|stg|dev)_[0-9a-f]{64}$/', $value);
    }
}
