<?php

declare(strict_types=1);

namespace Passway\Core;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Загрузчик конфигурации.
 *
 * Читает .env и файлы из config/, предоставляет единый
 * интерфейс get($key, $default) с точечной нотацией.
 */
final class Config
{
    private static ?Config $instance = null;

    /** @var array<string, mixed> Объединённые данные конфигурации */
    private array $data = [];

    private function __construct()
    {
        $this->loadEnv();
        $this->loadConfigFiles();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ------------------------------------------------------------------ //
    //  Публичный API                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Получить значение конфигурации.
     * Поддерживает точечную нотацию: 'database.host'
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Сначала ищем в env (uppercase)
        $envKey = strtoupper(str_replace('.', '_', $key));
        if (isset($_ENV[$envKey])) {
            return $this->castValue($_ENV[$envKey]);
        }

        // Затем в загруженных config-файлах
        return $this->getNestedValue($this->data, $key, $default);
    }

    /**
     * Установить значение (только в runtime, не сохраняется в файл).
     */
    public function set(string $key, mixed $value): void
    {
        $this->setNestedValue($this->data, $key, $value);
    }

    /**
     * Проверить наличие ключа.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Получить все данные конфигурации.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    // ------------------------------------------------------------------ //
    //  Приватные методы                                                    //
    // ------------------------------------------------------------------ //

    private function loadEnv(): void
    {
        $root = PASSWAY_ROOT;

        if (!file_exists($root . '/.env')) {
            // В production .env может отсутствовать — переменные передаются через окружение ОС
            if (file_exists($root . '/.env.example')) {
                // Ничего не делаем — используем системные переменные окружения
                return;
            }
            throw new RuntimeException(
                'Configuration file .env not found. Copy .env.example to .env and fill in the values.'
            );
        }

        $dotenv = Dotenv::createImmutable($root);
        $dotenv->load();

        // Обязательные переменные
        $dotenv->required(['APP_ENV', 'APP_URL'])->notEmpty();
        $dotenv->required(['DB_DRIVER'])->allowedValues(['pgsql', 'sqlite']);
    }

    private function loadConfigFiles(): void
    {
        $configDir = PASSWAY_ROOT . '/config';
        if (!is_dir($configDir)) {
            return;
        }

        foreach (glob($configDir . '/*.php') as $file) {
            $key          = basename($file, '.php');
            $values       = require $file;
            if (is_array($values)) {
                $this->data[$key] = $values;
            }
        }
    }

    /**
     * Получить вложенное значение через точечную нотацию.
     */
    private function getNestedValue(array $data, string $key, mixed $default): mixed
    {
        $keys = explode('.', $key);
        $value = $data;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Установить вложенное значение через точечную нотацию.
     */
    private function setNestedValue(array &$data, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $ref  = &$data;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $ref[$segment] = $value;
            } else {
                if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                    $ref[$segment] = [];
                }
                $ref = &$ref[$segment];
            }
        }
    }

    /**
     * Приводим строковые значения из .env к нативным типам PHP.
     */
    private function castValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => is_numeric($value) ? (
                str_contains($value, '.') ? (float) $value : (int) $value
            ) : $value,
        };
    }
}
