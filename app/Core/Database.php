<?php

declare(strict_types=1);

namespace Passway\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Менеджер соединения с базой данных (Singleton).
 *
 * Поддерживает PostgreSQL и SQLite через PDO.
 * Предоставляет удобные методы-хелперы поверх подготовленных запросов.
 * Все запросы выполняются через prepared statements для защиты от SQL Injection.
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
    //  Публичный API                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Получить нативный PDO-объект (для сложных запросов).
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Драйвер БД: 'pgsql' или 'sqlite'.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Выполнить произвольный SQL с параметрами.
     * Возвращает PDOStatement для итерации.
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
     * Получить одну строку или null.
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
     * Получить все строки.
     *
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Получить значение первого столбца первой строки.
     *
     * @param array<string|int, mixed> $params
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * Вставить запись. Возвращает ID вставленной строки.
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
     * Обновить записи. Возвращает количество затронутых строк.
     *
     * @param array<string, mixed> $data  Поля для обновления
     * @param array<string, mixed> $where Условия WHERE (AND)
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
     * Удалить записи. Возвращает количество затронутых строк.
     *
     * @param array<string, mixed> $where Условия WHERE (AND)
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
    //  Транзакции                                                          //
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
     * Выполнить callback в транзакции с автоматическим rollback при исключении.
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
    //  Утилиты                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Экранировать имя таблицы/колонки (защита от SQL Injection в идентификаторах).
     */
    public function quoteIdentifier(string $name): string
    {
        // Разрешаем только буквы, цифры, подчёркивания и точку (schema.table)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $name)) {
            throw new RuntimeException("Invalid identifier: {$name}");
        }
        $quote = $this->driver === 'pgsql' ? '"' : '`';
        return $quote . str_replace('.', "{$quote}.{$quote}", $name) . $quote;
    }

    /**
     * Проверить, существует ли таблица.
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
     * Вернуть SQL-выражение для текущего времени (с учётом диалекта).
     */
    public function nowExpr(): string
    {
        return $this->driver === 'pgsql' ? 'NOW()' : "datetime('now')";
    }

    // ------------------------------------------------------------------ //
    //  Приватные методы                                                    //
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
                // Создаём директорию если не существует
                $dir  = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $this->pdo = new PDO("sqlite:{$path}", null, null, $options);
                // Включаем WAL и foreign keys для SQLite
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

                // Устанавливаем timezone для сессии PostgreSQL
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
