<?php

declare(strict_types=1);

/**
 * Глобальные вспомогательные функции.
 * Загружаются автоматически через Composer (files autoload).
 */

use Passway\Core\Config;

if (!function_exists('config')) {
    /**
     * Получить значение конфигурации.
     */
    function config(string $key, mixed $default = null): mixed
    {
        return Config::getInstance()->get($key, $default);
    }
}

if (!function_exists('env')) {
    /**
     * Получить переменную окружения с fallback.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('base_path')) {
    /**
     * Получить абсолютный путь относительно корня проекта.
     */
    function base_path(string $path = ''): string
    {
        return PASSWAY_ROOT . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''));
    }
}

if (!function_exists('generate_uuid')) {
    /**
     * Генерировать UUID v4.
     */
    function generate_uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

if (!function_exists('now')) {
    /**
     * Текущее время в UTC.
     */
    function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}

if (!function_exists('e')) {
    /**
     * Экранировать HTML-спецсимволы (защита от XSS в шаблонах).
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
