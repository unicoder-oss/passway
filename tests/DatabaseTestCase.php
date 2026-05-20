<?php

declare(strict_types=1);

namespace Passway\Tests;

use Passway\Core\Database;
use Passway\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Base TestCase for tests that require a database (SQLite :memory:).
 *
 * Runs all migrations once per class (setUpBeforeClass).
 * Clears test data between tests (setUp).
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

        // Ensure the env is configured for :memory:
        $_ENV['DB_DRIVER']      = 'sqlite';
        $_ENV['DB_SQLITE_PATH'] = ':memory:';
        $_ENV['MASTER_KEY']     = str_repeat('ab', 32);

        // Reset the singleton and create a fresh connection
        static::resetDbSingleton();

        // Run all migrations
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
     * Reset the Database singleton (the test gets a new :memory: instance).
     */
    protected static function resetDbSingleton(): void
    {
        $ref  = new ReflectionClass(Database::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, null);
    }

    /**
     * Clear test data while preserving the table structure.
     * Order matches FK dependencies (children first).
     */
    protected function truncateTables(): void
    {
        $db  = Database::getInstance();
        $pdo = $db->getPdo();

        // Disable SQLite FK checks during cleanup
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
                // The table may not exist in partial configurations; that is ok
            }
        }

        // Restore FK checks
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Reset system_config to default values
        try {
            $pdo->exec("UPDATE system_config SET value = '1' WHERE key = 'setup_complete'");
            $pdo->exec("UPDATE system_config SET value = '' WHERE key = 'deploy_mode'");
        } catch (\Throwable) {}
    }

    /**
     * Insert a test user and return its User object.
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

        // Normalize email like findByEmail() and AuthService do
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
