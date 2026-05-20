#!/usr/bin/env php
<?php

/**
 * CLI-скрипт запуска миграций.
 *
 * Использование:
 *   php database/migrate.php up       — применить все новые миграции
 *   php database/migrate.php down     — откатить последний batch
 *   php database/migrate.php down 2   — откатить 2 последних batch
 *   php database/migrate.php reset    — откатить все миграции
 *   php database/migrate.php status   — показать статус
 *   php database/migrate.php fresh    — reset + up (ТОЛЬКО для разработки!)
 */

declare(strict_types=1);

// Определяем корень проекта
define('PASSWAY_ROOT', dirname(__DIR__));
define('PASSWAY_START', microtime(true));

// Composer автозагрузка
$autoloader = PASSWAY_ROOT . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run: composer install\n");
    exit(1);
}
require_once $autoloader;

// Загружаем .env
if (file_exists(PASSWAY_ROOT . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(PASSWAY_ROOT);
    $dotenv->safeLoad();
}

use Passway\Core\Database;
use Passway\Database\MigrationRunner;

// ------------------------------------------------------------------ //
//  Парсинг аргументов                                                  //
// ------------------------------------------------------------------ //

$command = $argv[1] ?? 'status';
$steps   = isset($argv[2]) ? (int) $argv[2] : 1;

// ------------------------------------------------------------------ //
//  Утилиты вывода                                                      //
// ------------------------------------------------------------------ //

function info(string $msg): void  { echo "\033[32m{$msg}\033[0m\n"; }
function warn(string $msg): void  { echo "\033[33m{$msg}\033[0m\n"; }
function error(string $msg): void { fwrite(STDERR, "\033[31m{$msg}\033[0m\n"); }
function line(string $msg): void  { echo $msg . "\n"; }

// ------------------------------------------------------------------ //
//  Выполнение команды                                                  //
// ------------------------------------------------------------------ //

try {
    $db     = Database::getInstance();
    $runner = new MigrationRunner($db);

    switch ($command) {

        case 'up':
        case 'migrate':
            line("Running migrations...");
            $applied = $runner->up();
            if (empty($applied)) {
                info("Nothing to migrate. Database is up to date.");
            } else {
                foreach ($applied as $name) {
                    info("  ✓ Applied: {$name}");
                }
                info(sprintf("Done. %d migration(s) applied.", count($applied)));
            }
            break;

        case 'down':
        case 'rollback':
            warn("Rolling back {$steps} batch(es)...");
            $rolledBack = $runner->down($steps);
            if (empty($rolledBack)) {
                warn("Nothing to rollback.");
            } else {
                foreach ($rolledBack as $name) {
                    warn("  ✗ Rolled back: {$name}");
                }
                warn(sprintf("Done. %d migration(s) rolled back.", count($rolledBack)));
            }
            break;

        case 'reset':
            warn("Resetting ALL migrations...");
            $confirm = readline("Type 'yes' to confirm: ");
            if (trim($confirm) !== 'yes') {
                line("Aborted.");
                exit(0);
            }
            $rolledBack = $runner->reset();
            if (empty($rolledBack)) {
                warn("Nothing to reset.");
            } else {
                foreach ($rolledBack as $name) {
                    warn("  ✗ Rolled back: {$name}");
                }
                warn(sprintf("Done. %d migration(s) reset.", count($rolledBack)));
            }
            break;

        case 'fresh':
            if (($_ENV['APP_ENV'] ?? 'production') === 'production') {
                error("'fresh' command is NOT allowed in production environment!");
                exit(1);
            }
            warn("Resetting and re-running all migrations (DEVELOPMENT ONLY)...");
            $runner->reset();
            $applied = $runner->up();
            foreach ($applied as $name) {
                info("  ✓ Applied: {$name}");
            }
            info(sprintf("Done. %d migration(s) applied.", count($applied)));
            break;

        case 'status':
        default:
            $statuses = $runner->status();
            if (empty($statuses)) {
                line("No migration files found.");
                break;
            }

            // Заголовок таблицы
            line(str_repeat('-', 80));
            line(sprintf("  %-45s  %-8s  %s", 'Migration', 'Status', 'Applied At'));
            line(str_repeat('-', 80));

            foreach ($statuses as $row) {
                $status    = $row['status'];
                $color     = $status === 'applied' ? "\033[32m" : "\033[33m";
                $appliedAt = $row['executed_at'] ?? '—';
                line(sprintf(
                    "  %-45s  {$color}%-8s\033[0m  %s",
                    $row['name'],
                    $status,
                    $appliedAt
                ));
            }
            line(str_repeat('-', 80));
            break;
    }

    exit(0);

} catch (\Throwable $e) {
    error("Migration error: " . $e->getMessage());
    if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
        error($e->getTraceAsString());
    }
    exit(1);
}
