<?php

declare(strict_types=1);

namespace Passway\Database;

use Passway\Core\Database;

/**
 * Базовый класс миграции.
 *
 * Каждая миграция наследует этот класс и реализует up() и down().
 * Предоставляет вспомогательные методы для генерации кросс-платформенного SQL
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
     * Применить миграцию (создать таблицы/индексы).
     */
    abstract public function up(): void;

    /**
     * Откатить миграцию (удалить таблицы/индексы).
     */
    abstract public function down(): void;

    /**
     * Имя миграции (по умолчанию — имя файла класса).
     */
    public function getName(): string
    {
        return (new \ReflectionClass($this))->getShortName();
    }

    // ------------------------------------------------------------------ //
    //  Генераторы кросс-платформенного SQL                                 //
    // ------------------------------------------------------------------ //

    /**
     * SQL-тип для целочисленного автоинкрементного PK.
     */
    protected function pkType(): string
    {
        return $this->driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    /**
     * SQL-тип для большого автоинкрементного PK (audit log и т.п.).
     * В SQLite нет BIGSERIAL — используем тот же INTEGER.
     */
    protected function bigPkType(): string
    {
        return $this->pkType();
    }

    /**
     * SQL-тип BOOLEAN.
     * PostgreSQL: BOOLEAN; SQLite: INTEGER (0/1).
     * Validation выполняется на уровне приложения.
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
     * SQL для DEFAULT CURRENT_TIMESTAMP.
     */
    protected function nowDefault(): string
    {
        return $this->driver === 'pgsql'
            ? 'TIMESTAMPTZ NOT NULL DEFAULT NOW()'
            : "DATETIME NOT NULL DEFAULT (datetime('now'))";
    }

    /**
     * SQL-тип TIMESTAMP (nullable).
     */
    protected function tsType(): string
    {
        return $this->driver === 'pgsql' ? 'TIMESTAMPTZ' : 'DATETIME';
    }

    /**
     * TEXT тип (идентично для обоих драйверов).
     */
    protected function textType(): string
    {
        return 'TEXT';
    }

    /**
     * Выполнить SQL-запрос напрямую.
     */
    protected function exec(string $sql): void
    {
        $this->db->getPdo()->exec($sql);
    }

    /**
     * Создать таблицу если не существует.
     *
     * @param string[] $columns  Массив строк вида "col_name DEFINITION"
     * @param string[] $constraints  Массив строк-ограничений (UNIQUE, FOREIGN KEY и т.п.)
     */
    protected function createTable(string $name, array $columns, array $constraints = []): void
    {
        $all  = [...$columns, ...$constraints];
        $body = implode(",\n    ", $all);
        $this->exec("CREATE TABLE IF NOT EXISTS {$name} (\n    {$body}\n)");
    }

    /**
     * Удалить таблицу если существует.
     */
    protected function dropTable(string $name): void
    {
        $this->exec("DROP TABLE IF EXISTS {$name}");
    }

    /**
     * Создать индекс если не существует.
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
     * Удалить индекс.
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
     * FOREIGN KEY с учётом SQLite (в SQLite FK описываются внутри CREATE TABLE).
     * Для ALTER TABLE — только в PostgreSQL; в SQLite возможно лишь при пересоздании таблицы.
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
     * Добавить столбец к существующей таблице (only PG; SQLite needs recreation).
     */
    protected function addColumn(string $table, string $column, string $definition): void
    {
        if (!$this->driver === 'sqlite') {
            // SQLite поддерживает ADD COLUMN с 3.20+, но без FOREIGN KEY
        }
        $this->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
