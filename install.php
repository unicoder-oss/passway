#!/usr/bin/env php
<?php

declare(strict_types=1);

define('PASSWAY_ROOT', __DIR__);
define('PASSWAY_START', microtime(true));

$autoloader = PASSWAY_ROOT . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run: composer install\n");
    exit(1);
}

require_once $autoloader;

use Dotenv\Dotenv;
use Passway\Core\Database;
use Passway\Database\MigrationRunner;

function install_info(string $message): void
{
    fwrite(STDOUT, "[Passway install] {$message}\n");
}

function install_warn(string $message): void
{
    fwrite(STDOUT, "[Passway install] Warning: {$message}\n");
}

function install_error(string $message): void
{
    fwrite(STDERR, "[Passway install] Error: {$message}\n");
}

function install_update_env_value(string $path, string $key, string $value): void
{
    $contents = file_exists($path) ? (string) file_get_contents($path) : '';
    $escaped = str_replace('\\', '\\\\', $value);

    if (preg_match('/^' . preg_quote($key, '/') . '=.*/m', $contents) === 1) {
        $contents = (string) preg_replace(
            '/^' . preg_quote($key, '/') . '=.*/m',
            $key . '=' . $escaped,
            $contents
        );
    } else {
        $contents .= (str_ends_with($contents, "\n") || $contents === '' ? '' : "\n") . $key . '=' . $escaped . "\n";
    }

    file_put_contents($path, $contents, LOCK_EX);
    $_ENV[$key] = $value;
    putenv($key . '=' . $value);
}

function install_ensure_storage_paths(): void
{
    $paths = [
        storage_path(),
        dirname((string) ($_ENV['DB_SQLITE_PATH'] ?? storage_path('passway.db'))),
        dirname((string) ($_ENV['LOG_PATH'] ?? storage_path('logs/passway.log'))),
        dirname((string) ($_ENV['SETUP_TOKEN_PATH'] ?? storage_path('setup_token.txt'))),
    ];

    foreach (array_unique($paths) as $path) {
        if ($path !== '' && !is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}

function install_check_extensions(): void
{
    $required = ['pdo', 'mbstring', 'json', 'sodium'];
    $missing = [];

    foreach ($required as $extension) {
        if (!extension_loaded($extension)) {
            $missing[] = $extension;
        }
    }

    $driver = $_ENV['DB_DRIVER'] ?? 'sqlite';
    if ($driver === 'pgsql' && !extension_loaded('pdo_pgsql')) {
        $missing[] = 'pdo_pgsql';
    }
    if ($driver === 'sqlite' && !extension_loaded('pdo_sqlite')) {
        $missing[] = 'pdo_sqlite';
    }

    if ($missing !== []) {
        throw new RuntimeException('Missing PHP extensions: ' . implode(', ', $missing));
    }
}

$envPath = PASSWAY_ROOT . '/.env';
$examplePath = PASSWAY_ROOT . '/.env.example';

try {
    if (!file_exists($envPath)) {
        if (!file_exists($examplePath)) {
            throw new RuntimeException('.env.example not found.');
        }

        copy($examplePath, $envPath);
        install_info('Created .env from .env.example');
    }

    Dotenv::createImmutable(PASSWAY_ROOT)->safeLoad();

    install_check_extensions();

    if (($masterKey = trim((string) ($_ENV['MASTER_KEY'] ?? ''))) === '') {
        $masterKey = bin2hex(random_bytes(32));
        install_update_env_value($envPath, 'MASTER_KEY', $masterKey);
        install_info('Generated MASTER_KEY and wrote it to .env');
    }

    $appUrl = trim((string) ($_ENV['APP_URL'] ?? 'http://localhost:8000'));
    $urlParts = parse_url($appUrl);
    $host = is_array($urlParts) ? (string) ($urlParts['host'] ?? 'localhost') : 'localhost';

    if (trim((string) ($_ENV['WEBAUTHN_RP_ID'] ?? '')) === '' || ($_ENV['WEBAUTHN_RP_ID'] ?? '') === 'example.com') {
        install_update_env_value($envPath, 'WEBAUTHN_RP_ID', $host);
        install_info('Aligned WEBAUTHN_RP_ID with APP_URL host');
    }
    if (trim((string) ($_ENV['WEBAUTHN_ORIGIN'] ?? '')) === '' || ($_ENV['WEBAUTHN_ORIGIN'] ?? '') === 'https://example.com') {
        install_update_env_value($envPath, 'WEBAUTHN_ORIGIN', $appUrl);
        install_info('Aligned WEBAUTHN_ORIGIN with APP_URL');
    }

    install_ensure_storage_paths();

    $db = Database::getInstance();
    $runner = new MigrationRunner($db);
    $applied = $runner->up();

    if ($applied === []) {
        install_info('Database is already up to date');
    } else {
        install_info('Applied migrations: ' . implode(', ', $applied));
    }

    install_info('Install bootstrap complete');
    install_info('Next steps:');
    install_info('1. Start the app: php -S 0.0.0.0:8000 -t public public/index.php');
    install_info('2. Open /setup and use the setup token from storage/setup_token.txt or container logs');

    if (($_ENV['APP_ENV'] ?? 'development') !== 'production' && filter_var($_ENV['SESSION_COOKIE_SECURE'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        install_warn('SESSION_COOKIE_SECURE=true may block login over plain HTTP during local development.');
    }

    exit(0);
} catch (Throwable $e) {
    install_error($e->getMessage());
    exit(1);
}
