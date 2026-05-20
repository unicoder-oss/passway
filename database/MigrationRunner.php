<?php

declare(strict_types=1);

namespace Passway\Database;

use Passway\Core\Database;
use RuntimeException;

/**
 * Запускает миграции базы данных с версионированием и поддержкой rollback.
 *
 * Файлы миграций: database/migrations/NNN_ClassName.php
 * Порядок применения: по числовому префиксу NNN (001, 002, ...).
 *
 * Команды:
 *   php database/migrate.php up       — применить все новые миграции
 *   php database/migrate.php down     — откатить последний batch
 *   php database/migrate.php reset    — откатить ВСЕ миграции
 *   php database/migrate.php status   — показать статус
 *   php database/migrate.php fresh    — drop all tables + up (только для разработки!)
 */
final class MigrationRunner
{
    private Database $db;
    private string   $migrationsPath;

    public function __construct(Database $db, string $migrationsPath = null)
    {
        $this->db             = $db;
        $this->migrationsPath = $migrationsPath ?? dirname(__DIR__) . '/database/migrations';
    }

    // ------------------------------------------------------------------ //
    //  Публичный API                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Применить все новые миграции.
     *
     * @return string[] Список применённых миграций
     */
    public function up(): array
    {
        $this->ensureMigrationsTable();

        $pending = $this->getPendingMigrations();
        if (empty($pending)) {
            return [];
        }

        $batch   = $this->getNextBatch();
        $applied = [];

        $this->db->transaction(function () use ($pending, $batch, &$applied) {
            foreach ($pending as $name => $file) {
                $migration = $this->loadMigration($file);
                $migration->up();
                $this->recordMigration($name, $batch);
                $applied[] = $name;
            }
        });

        return $applied;
    }

    /**
     * Откатить последний batch.
     *
     * @param int $steps Количество батчей для отката (по умолчанию 1)
     * @return string[] Список откатанных миграций
     */
    public function down(int $steps = 1): array
    {
        $this->ensureMigrationsTable();

        $batches  = $this->getBatchesToRollback($steps);
        $rolledBack = [];

        if (empty($batches)) {
            return [];
        }

        $this->db->transaction(function () use ($batches, &$rolledBack) {
            foreach ($batches as $record) {
                $file = $this->findMigrationFile($record['migration']);
                if ($file === null) {
                    throw new RuntimeException(
                        "Migration file not found for: {$record['migration']}"
                    );
                }
                $migration = $this->loadMigration($file);
                $migration->down();
                $this->removeMigrationRecord($record['migration']);
                $rolledBack[] = $record['migration'];
            }
        });

        return $rolledBack;
    }

    /**
     * Откатить все миграции.
     *
     * @return string[] Список откатанных миграций
     */
    public function reset(): array
    {
        $this->ensureMigrationsTable();
        $maxBatch = $this->getMaxBatch();
        return $this->down($maxBatch);
    }

    /**
     * Удалить все таблицы текущей схемы без вызова down() миграций.
     *
     * Используется для fresh в разработке: это надёжнее rollback всех миграций,
     * особенно для SQLite с пересозданием таблиц и внешними ключами.
     */
    public function dropAllTables(): void
    {
        if ($this->db->getDriver() === 'pgsql') {
            $this->db->getPdo()->exec('DROP SCHEMA IF EXISTS public CASCADE');
            $this->db->getPdo()->exec('CREATE SCHEMA public');
            return;
        }

        $pdo = $this->db->getPdo();
        $pdo->exec('PRAGMA foreign_keys = OFF');

        try {
            $tables = $this->db->fetchAll(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            );

            foreach ($tables as $table) {
                $name = (string) $table['name'];
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->db->quoteIdentifier($name));
            }
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Показать статус миграций.
     *
     * @return array<int, array{name: string, status: string, batch: int|null, executed_at: string|null}>
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $allFiles = $this->getAllMigrationFiles();
        $applied  = $this->getAppliedMigrations();

        $result = [];
        foreach ($allFiles as $name => $file) {
            $rec = $applied[$name] ?? null;
            $result[] = [
                'name'        => $name,
                'status'      => $rec ? 'applied' : 'pending',
                'batch'       => $rec ? (int) $rec['batch'] : null,
                'executed_at' => $rec ? $rec['executed_at'] : null,
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------------ //
    //  Приватные методы                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Создать таблицу migrations если не существует.
     */
    private function ensureMigrationsTable(): void
    {
        if ($this->db->getDriver() === 'pgsql') {
            $this->db->getPdo()->exec("
                CREATE TABLE IF NOT EXISTS migrations (
                    id          BIGSERIAL PRIMARY KEY,
                    migration   VARCHAR(255) NOT NULL UNIQUE,
                    batch       INTEGER NOT NULL,
                    executed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
        } else {
            $this->db->getPdo()->exec("
                CREATE TABLE IF NOT EXISTS migrations (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    migration   TEXT NOT NULL UNIQUE,
                    batch       INTEGER NOT NULL,
                    executed_at DATETIME NOT NULL DEFAULT (datetime('now'))
                )
            ");
        }
    }

    /**
     * Получить список всех файлов миграций, отсортированных по имени.
     *
     * @return array<string, string>  name => filepath
     */
    private function getAllMigrationFiles(): array
    {
        $files  = glob($this->migrationsPath . '/*.php') ?: [];
        $result = [];

        sort($files); // Сортировка по имени файла (числовой префикс гарантирует порядок)

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $result[$name] = $file;
        }

        return $result;
    }

    /**
     * Получить список применённых миграций.
     *
     * @return array<string, array{batch: int, executed_at: string}>
     */
    private function getAppliedMigrations(): array
    {
        $rows   = $this->db->fetchAll('SELECT migration, batch, executed_at FROM migrations ORDER BY id');
        $result = [];
        foreach ($rows as $row) {
            $result[$row['migration']] = [
                'batch'       => (int) $row['batch'],
                'executed_at' => $row['executed_at'],
            ];
        }
        return $result;
    }

    /**
     * Получить список необработанных миграций.
     *
     * @return array<string, string>
     */
    private function getPendingMigrations(): array
    {
        $all     = $this->getAllMigrationFiles();
        $applied = $this->getAppliedMigrations();

        return array_filter($all, fn($name) => !isset($applied[$name]), ARRAY_FILTER_USE_KEY);
    }

    private function getNextBatch(): int
    {
        $max = $this->db->fetchColumn('SELECT MAX(batch) FROM migrations');
        return ((int) $max) + 1;
    }

    private function getMaxBatch(): int
    {
        $max = $this->db->fetchColumn('SELECT MAX(batch) FROM migrations');
        return (int) $max;
    }

    /**
     * Получить записи для отката (в обратном порядке применения).
     *
     * @return array<int, array{migration: string, batch: int}>
     */
    private function getBatchesToRollback(int $steps): array
    {
        $maxBatch = $this->getMaxBatch();
        $minBatch = max(1, $maxBatch - $steps + 1);

        return $this->db->fetchAll(
            'SELECT migration, batch FROM migrations WHERE batch >= ? ORDER BY id DESC',
            [$minBatch]
        );
    }

    private function recordMigration(string $name, int $batch): void
    {
        $this->db->insert('migrations', ['migration' => $name, 'batch' => $batch]);
    }

    private function removeMigrationRecord(string $name): void
    {
        $this->db->delete('migrations', ['migration' => $name]);
    }

    private function loadMigration(string $file): Migration
    {
        require_once $file;

        // Имя класса выводим из имени файла: "001_CreateSystemConfig" -> "CreateSystemConfig"
        $basename  = basename($file, '.php');
        $parts     = explode('_', $basename, 2); // ['001', 'CreateSystemConfig']
        $className = isset($parts[1]) ? $parts[1] : $basename;

        // Полное имя класса
        $fqcn = 'Passway\\Database\\Migrations\\' . $className;

        if (!class_exists($fqcn)) {
            throw new RuntimeException("Migration class {$fqcn} not found in {$file}");
        }

        return new $fqcn($this->db);
    }

    private function findMigrationFile(string $name): ?string
    {
        $all = $this->getAllMigrationFiles();
        return $all[$name] ?? null;
    }
}
