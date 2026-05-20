<?php

declare(strict_types=1);

namespace Passway\Services;

/**
 * Сервис хеширования паролей и токенов.
 *
 * Пароли: Argon2id — современный алгоритм, устойчивый к GPU-брутфорсу.
 *   - memory_cost: 64 MB (достаточно для защиты, не перегружает сервер)
 *   - time_cost:   4 итерации
 *   - threads:     1 (совместимо с однопоточными окружениями)
 *
 * Токены (сессии, API-ключи, инвайты): SHA-256 (hex).
 *   - Быстрый поиск по индексу в БД
 *   - Защищён тем, что исходный токен — криптографически случайный (32 байта)
 *
 * ВАЖНО: для паролей НИКОГДА не использовать SHA-256 напрямую.
 *        Только Argon2id через password_hash().
 */
final class HashingService
{
    // Argon2id параметры (OWASP рекомендация минимум 19 MB / 2 итерации)
    private const ARGON2_MEMORY_COST  = 65536;  // 64 MB (KiB)
    private const ARGON2_TIME_COST    = 4;       // итерации
    private const ARGON2_THREADS      = 1;

    // ------------------------------------------------------------------ //
    //  Пароли (Argon2id)                                                  //
    // ------------------------------------------------------------------ //

    /**
     * Захешировать пароль пользователя.
     *
     * @param string $password Открытый пароль (будет затёрт в памяти)
     * @return string          Hash для хранения в БД
     */
    public function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => self::ARGON2_MEMORY_COST,
            'time_cost'   => self::ARGON2_TIME_COST,
            'threads'     => self::ARGON2_THREADS,
        ]);

        if ($hash === false) {
            throw new \RuntimeException('password_hash() failed: Argon2id not available.');
        }

        if (\function_exists('sodium_memzero')) { \sodium_memzero($password); }

        return $hash;
    }

    /**
     * Проверить пароль против сохранённого хеша.
     *
     * Использует timing-safe сравнение (password_verify делает это внутренне).
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        $result = password_verify($password, $hash);
        if (\function_exists('sodium_memzero')) { \sodium_memzero($password); }
        return $result;
    }

    /**
     * Проверить, нужен ли ре-хеш (если изменились параметры алгоритма).
     * Вызывать после успешного verifyPassword — обновить хеш в БД.
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
    //  Токены (SHA-256)                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Захешировать сессионный токен для хранения в БД.
     * Входной токен: 64 hex-символа (32 байта случайных данных).
     *
     * @return string SHA-256 hex (64 символа)
     */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Захешировать API-ключ для хранения в БД.
     * Формат ключа: sv_{env}_{64 hex} — суффикс 64 hex = 32 байта.
     *
     * @return string SHA-256 hex (64 символа)
     */
    public function hashApiKey(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /**
     * Захешировать инвайт-токен для хранения в БД.
     *
     * @return string SHA-256 hex (64 символа)
     */
    public function hashInviteToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Timing-safe сравнение двух строк (защита от timing attacks).
     * Использовать для сравнения хешей, не самих токенов.
     */
    public function timingSafeEquals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
