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

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''));
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $baseUrl = rtrim((string) config('app.url', env('APP_URL', '')), '/');
        if ($path === '') {
            return $baseUrl;
        }

        return $baseUrl . '/' . ltrim($path, '/');
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

if (!function_exists('avatar_fallback_color')) {
    function avatar_fallback_color(): string
    {
        return '#6b7280';
    }
}

if (!function_exists('generate_avatar_color')) {
    function generate_avatar_color(): string
    {
        $palette = [
            '#475569',
            '#0f766e',
            '#1d4ed8',
            '#7c3aed',
            '#b45309',
            '#be123c',
            '#166534',
            '#374151',
        ];

        return $palette[random_int(0, count($palette) - 1)] ?? avatar_fallback_color();
    }
}

if (!function_exists('display_name_for_user')) {
    function display_name_for_user(object $user): string
    {
        $nickname = isset($user->nickname) ? trim((string) $user->nickname) : '';
        if ($nickname !== '') {
            return $nickname;
        }

        return (string) ($user->email ?? 'user');
    }
}

if (!function_exists('avatar_initial')) {
    function avatar_initial(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '?';
        }

        $initial = mb_substr($value, 0, 1, 'UTF-8');
        return mb_strtoupper($initial, 'UTF-8');
    }
}

if (!function_exists('avatar_color_for_user')) {
    function avatar_color_for_user(object $user): string
    {
        $color = isset($user->avatarColor) ? trim((string) $user->avatarColor) : '';
        return $color !== '' ? $color : avatar_fallback_color();
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

if (!function_exists('app_fallback_locale')) {
    function app_fallback_locale(): string
    {
        return 'en';
    }
}

if (!function_exists('app_locale')) {
    function app_locale(): string
    {
        $locale = strtolower(trim((string) env('APP_LOCALE', app_fallback_locale())));
        $locale = preg_replace('/[^a-z0-9_-]/', '', $locale);

        return $locale !== '' ? $locale : app_fallback_locale();
    }
}

if (!function_exists('translation_lookup')) {
    /**
     * @param array<string, mixed> $translations
     * @param string[] $segments
     */
    function translation_lookup(array $translations, array $segments): mixed
    {
        $value = $translations;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('translation_file')) {
    /** @return array<string, mixed> */
    function translation_file(string $locale, string $file): array
    {
        static $cache = [];

        $cacheKey = $locale . ':' . $file;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $path = base_path('resources/lang/' . $locale . '/' . $file . '.php');
        if (!file_exists($path)) {
            return $cache[$cacheKey] = [];
        }

        $translations = require $path;

        return $cache[$cacheKey] = is_array($translations) ? $translations : [];
    }
}

if (!function_exists('trans')) {
    /** @param array<string, scalar|null> $replace */
    function trans(string $key, array $replace = []): string
    {
        $segments = explode('.', $key);
        $file = array_shift($segments);

        if ($file === null || $file === '') {
            return $key;
        }

        $value = translation_lookup(translation_file(app_locale(), $file), $segments);
        if ($value === null && app_locale() !== app_fallback_locale()) {
            $value = translation_lookup(translation_file(app_fallback_locale(), $file), $segments);
        }

        if (!is_string($value) && !is_numeric($value)) {
            return $key;
        }

        $translation = (string) $value;
        foreach ($replace as $name => $replacement) {
            $translation = str_replace(':' . $name, (string) ($replacement ?? ''), $translation);
        }

        return $translation;
    }
}

if (!function_exists('__')) {
    /** @param array<string, scalar|null> $replace */
    function __(string $key, array $replace = []): string
    {
        return trans($key, $replace);
    }
}
