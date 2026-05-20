<?php

declare(strict_types=1);

namespace Passway\Tests;

use Passway\Core\Database;
use Passway\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Базовый TestCase для тестов, требующих БД (SQLite :memory:).
 *
 * Запускает все миграции один раз для класса (setUpBeforeClass).
 * Очищает тестовые данные между тестами (setUp).
 *
 * @requires extension pdo_sqlite
 */
abstract class DatabaseTestCase extends TestCase
{
    // ------------------------------------------------------------------ //
    //  Bootstrap                                                           //
    // ------------------------------------------------------------------ //

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Убедиться, что env настроен на :memory:
        $_ENV['DB_DRIVER']      = 'sqlite';
        $_ENV['DB_SQLITE_PATH'] = ':memory:';
        $_ENV['MASTER_KEY']     = str_repeat('ab', 32);

        // Сбросить синглтон и создать свежее соединение
        static::resetDbSingleton();

        // Прогнать все миграции
        $runner = new MigrationRunner(Database::getInstance());
        $runner->up();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables();
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Сбросить синглтон Database (для теста создаётся новый :memory: экземпляр).
     */
    protected static function resetDbSingleton(): void
    {
        $ref  = new ReflectionClass(Database::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);
    }

    /**
     * Очистить тестовые данные, сохраняя структуру таблиц.
     * Порядок соответствует FK-зависимостям (потомки первыми).
     */
    protected function truncateTables(): void
    {
        $db  = Database::getInstance();
        $pdo = $db->getPdo();

        // Отключить FK-проверки в SQLite на время очистки
        $pdo->exec('PRAGMA foreign_keys = OFF');

        $tables = [
            'audit_log', 'rate_limit_log',
            'approval_reviewers', 'approval_requests',
            'sessions', 'passkeys',
            'api_key_permissions', 'api_keys',
            'user_permissions',
            'group_members', 'groups',
            'organization_members', 'invite_links',
            'secret_rotation_history', 'secret_metadata', 'secrets',
            'directories',
            'rotation_services', 'organization_integrations',
            'organizations',
            'users',
        ];

        foreach ($tables as $table) {
            try {
                $pdo->exec("DELETE FROM {$table}");
            } catch (\Throwable) {
                // Таблица может не существовать в частичных конфигурациях — ок
            }
        }

        // Восстановить FK-проверки
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Сбросить system_config до дефолтных значений
        try {
            $pdo->exec("UPDATE system_config SET value = '1' WHERE key = 'setup_complete'");
            $pdo->exec("UPDATE system_config SET value = '' WHERE key = 'deploy_mode'");
        } catch (\Throwable) {}
    }

    /**
     * Вставить тестового пользователя и вернуть его User-объект.
     */
    protected function createTestUser(
        string $email        = 'test@example.com',
        string $passwordHash = '',
        bool   $isActive     = true,
    ): \Passway\Models\User {
        if ($passwordHash === '') {
            $hashingService = new \Passway\Services\HashingService();
            $passwordHash   = $hashingService->hashPassword('Test1234!');
        }

        // Нормализуем email как это делают findByEmail() и AuthService
        $email = \strtolower(\trim($email));

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        Database::getInstance()->insert('users', [
            'uuid'           => generate_uuid(),
            'email'          => $email,
            'password_hash'  => $passwordHash,
            'totp_enabled'   => 0,
            'is_active'      => $isActive ? 1 : 0,
            'email_verified' => 0,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $user = \Passway\Models\User::findByEmail($email);
        self::assertNotNull($user, 'Failed to create test user');
        return $user;
    }
}
