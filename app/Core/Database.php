<?php

declare(strict_types=1);

namespace Passway\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Database connection manager (Singleton).
 *
 * Supports PostgreSQL and SQLite through PDO.
 * Provides convenient helper methods over prepared statements.
 * All queries use prepared statements to protect against SQL injection.
 */
final class Database
{
    private static ?Database $instance = null;

    private PDO $pdo;
    private string $driver;

    private function __construct()
    {
        $this->connect();
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
     * Get the native PDO object (for complex queries).
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Driver DB: 'pgsql' or 'sqlite'.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Execute arbitrary SQL with parameters.
     * Returns PDOStatement for iteration.
     *
     * @param array<string|int, mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Get one row or null.
     *
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    /**
     * Get all rows.
     *
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the first column value from the first row.
     *
     * @param array<string|int, mixed> $params
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * Insert a record. Returns the inserted row ID.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): string
    {
        $table   = $this->quoteIdentifier($table);
        $columns = array_map([$this, 'quoteIdentifier'], array_keys($data));
        $placeholders = array_fill(0, count($data), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * Update records. Returns the number of affected rows.
     *
     * @param array<string, mixed> $data  Fields to update
     * @param array<string, mixed> $where WHERE conditions (AND)
     */
    public function update(string $table, array $data, array $where): int
    {
        $table  = $this->quoteIdentifier($table);
        $setClauses   = [];
        $whereClauses = [];
        $params = [];

        foreach ($data as $col => $val) {
            $setClauses[] = $this->quoteIdentifier($col) . ' = ?';
            $params[] = $val;
        }

        foreach ($where as $col => $val) {
            $whereClauses[] = $this->quoteIdentifier($col) . ' = ?';
            $params[] = $val;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses)
        );

        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Delete records. Returns the number of affected rows.
     *
     * @param array<string, mixed> $where WHERE conditions (AND)
     */
    public function delete(string $table, array $where): int
    {
        $table        = $this->quoteIdentifier($table);
        $whereClauses = [];
        $params       = [];

        foreach ($where as $col => $val) {
            $whereClauses[] = $this->quoteIdentifier($col) . ' = ?';
            $params[] = $val;
        }

        if (empty($whereClauses)) {
            throw new RuntimeException('DELETE without WHERE clause is not allowed.');
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $table,
            implode(' AND ', $whereClauses)
        );

        return $this->query($sql, $params)->rowCount();
    }

    // ------------------------------------------------------------------ //
    //  Transactions                                                          //
    // ------------------------------------------------------------------ //

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Run a callback in a transaction with automatic rollback on exception.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // ------------------------------------------------------------------ //
    //  Utilities                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Escape a table/column name (SQL injection protection in identifiers).
     */
    public function quoteIdentifier(string $name): string
    {
        // Allow only letters, digits, underscores, and a dot (schema.table)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $name)) {
            throw new RuntimeException("Invalid identifier: {$name}");
        }
        $quote = $this->driver === 'pgsql' ? '"' : '`';
        return $quote . str_replace('.', "{$quote}.{$quote}", $name) . $quote;
    }

    /**
     * Check whether a table exists.
     */
    public function tableExists(string $table): bool
    {
        if ($this->driver === 'pgsql') {
            $result = $this->fetchColumn(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
                [$table]
            );
        } else {
            $result = $this->fetchColumn(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?",
                [$table]
            );
        }
        return (int) $result > 0;
    }

    /**
     * Return the SQL expression for the current time (with dialect awareness).
     */
    public function nowExpr(): string
    {
        return $this->driver === 'pgsql' ? 'NOW()' : "datetime('now')";
    }

    // ------------------------------------------------------------------ //
    //  Private methods                                                    //
    // ------------------------------------------------------------------ //

    private function connect(): void
    {
        $this->driver = $_ENV['DB_DRIVER'] ?? 'pgsql';

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            if ($this->driver === 'sqlite') {
                $path = $_ENV['DB_SQLITE_PATH'] ?? PASSWAY_ROOT . '/storage/passway.db';
                // Create the directory if it does not exist
                $dir  = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $this->pdo = new PDO("sqlite:{$path}", null, null, $options);
                // Enable WAL and foreign keys for SQLite
                $this->pdo->exec('PRAGMA journal_mode=WAL');
                $this->pdo->exec('PRAGMA foreign_keys=ON');
            } else {
                $host    = $_ENV['DB_HOST']    ?? '127.0.0.1';
                $port    = $_ENV['DB_PORT']    ?? '5432';
                $dbname  = $_ENV['DB_NAME']    ?? 'passway';
                $user    = $_ENV['DB_USER']    ?? 'passway';
                $pass    = $_ENV['DB_PASS']    ?? '';
                $sslmode = $_ENV['DB_SSLMODE'] ?? 'require';

                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";
                $this->pdo = new PDO($dsn, $user, $pass, $options);

                // Set the timezone for the PostgreSQL session
                $this->pdo->exec("SET timezone = 'UTC'");
            }
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }
}
