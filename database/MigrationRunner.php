<?php

declare(strict_types=1);

namespace Passway\Database;

use Passway\Core\Database;
use RuntimeException;

/**
 * Runs database migrations with versioning and rollback support.
 *
 * Migration files: database/migrations/NNN_ClassName.php
 * Application order: by numeric prefix NNN (001, 002, ...).
 *
 * Commands:
 *   php database/migrate.php up       — apply all new migrations
 *   php database/migrate.php down     — roll back the latest batch
 *   php database/migrate.php reset    — roll back ALL migrations
 *   php database/migrate.php status   — show status
 *   php database/migrate.php fresh    — drop all tables + up (development only!)
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
    //  Public API                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Apply all new migrations.
     *
     * @return string[] List of applied migrations
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
     * Roll back the latest batch.
     *
     * @param int $steps Number of batches to roll back (defaults to 1)
     * @return string[] List of rolled back migrations
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
     * Roll back all migrations.
     *
     * @return string[] List of rolled back migrations
     */
    public function reset(): array
    {
        $this->ensureMigrationsTable();
        $maxBatch = $this->getMaxBatch();
        return $this->down($maxBatch);
    }

    /**
     * Drop all tables in the current schema without calling migration down() methods.
     *
     * Used for fresh in development: this is more reliable than rolling back all migrations,
     * especially for SQLite with table recreation and foreign keys.
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
     * Show migration status.
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
    //  Private methods                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Create the migrations table if it does not exist.
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
     * Get the list of all migration files sorted by name.
     *
     * @return array<string, string>  name => filepath
     */
    private function getAllMigrationFiles(): array
    {
        $files  = glob($this->migrationsPath . '/*.php') ?: [];
        $result = [];

        sort($files); // Sort by filename (the numeric prefix guarantees order)

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $result[$name] = $file;
        }

        return $result;
    }

    /**
     * Get the list of applied migrations.
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
     * Get the list of pending migrations.
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
     * Get records to roll back (in reverse application order).
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

        // Derive the class name from the filename: "001_CreateSystemConfig" -> "CreateSystemConfig"
        $basename  = basename($file, '.php');
        $parts     = explode('_', $basename, 2); // ['001', 'CreateSystemConfig']
        $className = isset($parts[1]) ? $parts[1] : $basename;

        // Fully qualified class name
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
