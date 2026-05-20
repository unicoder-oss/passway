<?php

declare(strict_types=1);

namespace Passway\Core;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Configuration loader.
 *
 * Reads .env and files from config/, provides a unified
 * interface get($key, $default) with dot notation.
 */
final class Config
{
    private static ?Config $instance = null;

    /** @var array<string, mixed> Merged configuration data */
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
    //  Public API                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Get a configuration value.
     * Supports dot notation: 'database.host'
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // First look in env (uppercase)
        $envKey = strtoupper(str_replace('.', '_', $key));
        if (isset($_ENV[$envKey])) {
            return $this->castValue($_ENV[$envKey]);
        }

        // Then in the loaded config files
        return $this->getNestedValue($this->data, $key, $default);
    }

    /**
     * Set a value (runtime only, not saved to a file).
     */
    public function set(string $key, mixed $value): void
    {
        $this->setNestedValue($this->data, $key, $value);
    }

    /**
     * Check whether a key exists.
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Get all configuration data.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    // ------------------------------------------------------------------ //
    //  Private methods                                                    //
    // ------------------------------------------------------------------ //

    private function loadEnv(): void
    {
        $root = PASSWAY_ROOT;

        if (!file_exists($root . '/.env')) {
            // In production .env may be absent; variables are passed through the OS environment
            if (file_exists($root . '/.env.example')) {
                // Do nothing - use system environment variables
                return;
            }
            throw new RuntimeException(
                'Configuration file .env not found. Copy .env.example to .env and fill in the values.'
            );
        }

        $dotenv = Dotenv::createImmutable($root);
        $dotenv->load();

        // Required variables
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
     * Get a nested value through dot notation.
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
     * Set a nested value through dot notation.
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
     * Cast string values from .env to native PHP types.
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
