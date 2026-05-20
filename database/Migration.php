<?php

declare(strict_types=1);

namespace Passway\Database;

use Passway\Core\Database;

/**
 * Base migration class.
 *
 * Each migration extends this class and implements up() and down().
 * Provides helper methods for generating cross-platform SQL
 * (PostgreSQL / SQLite).
 */
abstract class Migration
{
    protected Database $db;
    protected string   $driver;

    public function __construct(Database $db)
    {
        $this->db     = $db;
        $this->driver = $db->getDriver();
    }

    /**
     * Apply the migration (create tables/indexes).
     */
    abstract public function up(): void;

    /**
     * Roll back the migration (drop tables/indexes).
     */
    abstract public function down(): void;

    /**
     * Migration name (defaults to the class filename).
     */
    public function getName(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    // ------------------------------------------------------------------ //
    //  Cross-platform SQL generators                                      //
    // ------------------------------------------------------------------ //

    /**
     * SQL type for an integer auto-incrementing PK.
     */
    protected function pkType(): string
    {
        return $this->driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    /**
     * SQL type for a large auto-incrementing PK (audit log, etc.).
     * SQLite has no BIGSERIAL, so use the same INTEGER type.
     */
    protected function bigPkType(): string
    {
        return $this->pkType();
    }

    /**
     * SQL BOOLEAN type.
     * PostgreSQL: BOOLEAN; SQLite: INTEGER (0/1).
     * Validation is performed at the application level.
     */
    protected function boolType(bool $default = false): string
    {
        $def = $default ? '1' : '0';
        if ($this->driver === 'pgsql') {
            return 'BOOLEAN NOT NULL DEFAULT ' . ($default ? 'TRUE' : 'FALSE');
        }
        return "INTEGER NOT NULL DEFAULT {$def}";
    }

    /**
     * SQL for DEFAULT CURRENT_TIMESTAMP.
     */
    protected function nowDefault(): string
    {
        return $this->driver === 'pgsql'
            ? 'TIMESTAMPTZ NOT NULL DEFAULT NOW()'
            : "DATETIME NOT NULL DEFAULT (datetime('now'))";
    }

    /**
     * SQL TIMESTAMP type (nullable).
     */
    protected function tsType(): string
    {
        return $this->driver === 'pgsql' ? 'TIMESTAMPTZ' : 'DATETIME';
    }

    /**
     * TEXT type (identical for both drivers).
     */
    protected function textType(): string
    {
        return 'TEXT';
    }

    /**
     * Execute an SQL query directly.
     */
    protected function exec(string $sql): void
    {
        $this->db->getPdo()->exec($sql);
    }

    /**
     * Create a table if it does not exist.
     *
     * @param string[] $columns  Array of strings in the form "col_name DEFINITION"
     * @param string[] $constraints  Array of constraint strings (UNIQUE, FOREIGN KEY, etc.)
     */
    protected function createTable(string $name, array $columns, array $constraints = []): void
    {
        $all  = [...$columns, ...$constraints];
        $body = implode(",\n    ", $all);
        $this->exec("CREATE TABLE IF NOT EXISTS {$name} (\n    {$body}\n)");
    }

    /**
     * Drop a table if it exists.
     */
    protected function dropTable(string $name): void
    {
        $this->exec("DROP TABLE IF EXISTS {$name}");
    }

    /**
     * Create an index if it does not exist.
     *
     * @param string[] $columns
     */
    protected function createIndex(string $table, array $columns, bool $unique = false): void
    {
        $suffix = implode('_', $columns);
        $name   = "idx_{$table}_{$suffix}";
        $u      = $unique ? 'UNIQUE ' : '';
        $cols   = implode(', ', $columns);
        $this->exec("CREATE {$u}INDEX IF NOT EXISTS {$name} ON {$table} ({$cols})");
    }

    /**
     * Drop an index.
     */
    protected function dropIndex(string $table, array $columns): void
    {
        if ($this->driver === 'pgsql') {
            $suffix = implode('_', $columns);
            $name   = "idx_{$table}_{$suffix}";
            $this->exec("DROP INDEX IF EXISTS {$name}");
        } else {
            $suffix = implode('_', $columns);
            $name   = "idx_{$table}_{$suffix}";
            $this->exec("DROP INDEX IF EXISTS {$name}");
        }
    }

    /**
     * FOREIGN KEY with SQLite in mind (SQLite defines FKs inside CREATE TABLE).
     * For ALTER TABLE, only PostgreSQL is supported; SQLite requires table recreation.
     */
    protected function foreignKey(
        string $column,
        string $refTable,
        string $refColumn = 'id',
        string $onDelete  = 'RESTRICT',
        string $onUpdate  = 'CASCADE'
    ): string {
        return "FOREIGN KEY ({$column}) REFERENCES {$refTable}({$refColumn}) ON DELETE {$onDelete} ON UPDATE {$onUpdate}";
    }

    /**
     * Add a column to an existing table (only PG; SQLite needs recreation).
     */
    protected function addColumn(string $table, string $column, string $definition): void
    {
        if (!$this->driver === 'sqlite') {
            // SQLite supports ADD COLUMN since 3.20+, but without FOREIGN KEY
        }
        $this->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
