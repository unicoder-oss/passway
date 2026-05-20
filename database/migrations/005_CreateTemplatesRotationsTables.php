<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Миграция 005: Шаблоны секретов и сервисы ротации.
 *
 * Таблицы:
 *   - templates                — встроенные и пользовательские шаблоны
 *   - rotation_services        — внешние сервисы ротации (регистрируются sysadmin)
 *   - organization_integrations — конфигурация сервисов ротации для организаций
 */
final class CreateTemplatesRotationsTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  templates                                                           //
        // ------------------------------------------------------------------ //
        // organization_id = NULL → системный шаблон (встроенный, недоступен для изменения)
        // Встроенные шаблоны: password, ssh_key
        $this->createTable('templates', [
            "id               {$this->pkType()}",
            'uuid             VARCHAR(36) NOT NULL',
            // NULL = системный шаблон
            'organization_id  BIGINT',
            'name             VARCHAR(255) NOT NULL',
            'system_key       VARCHAR(120)',
            // password | ssh_key
            'type             VARCHAR(50) NOT NULL',
            'description      TEXT',
            // JSON с параметрами генерации:
            // password: {"min_length":8,"max_length":256,"use_upper":true,"use_lower":true,"use_digits":true,"use_special":true}
            // ssh_key:  {"algorithm":"rsa","bits":4096} или {"algorithm":"ed25519"}
            'config_json      TEXT NOT NULL',
            "is_system        {$this->boolType(false)}",
            'created_by       BIGINT',
            "created_at       {$this->nowDefault()}",
            "updated_at       {$this->nowDefault()}",
            $this->foreignKey('organization_id', 'organizations', 'id', 'CASCADE'),
            $this->foreignKey('created_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (uuid)',
        ]);

        $this->createIndex('templates', ['organization_id']);
        $this->createIndex('templates', ['type']);
        $this->createIndex('templates', ['is_system']);

        // Вставляем встроенные системные шаблоны
        $this->insertSystemTemplates();

        // ------------------------------------------------------------------ //
        //  rotation_services                                                   //
        // ------------------------------------------------------------------ //
        // Сервисы ротации регистрируются системным администратором.
        // Протокол:
        //   GET  /health  — проверка доступности (возвращает {"status":"ok"})
        //   GET  /spec    — спецификация (список поддерживаемых типов секретов и параметров)
        //   POST /validate — проверка текущего значения
        //   POST /rotate   — ротация секрета
        $this->createTable('rotation_services', [
            "id            {$this->pkType()}",
            'uuid          VARCHAR(36) NOT NULL',
            'name          VARCHAR(255) NOT NULL',
            // Базовый URL сервиса (без завершающего /)
            'url           VARCHAR(500) NOT NULL',
            'health_url    VARCHAR(500)',
            // JSON-спецификация, загруженная с /spec
            'spec_json     TEXT',
            "is_active     {$this->boolType(true)}",
            // Прошёл ли последний health check
            "is_verified   {$this->boolType(false)}",
            "last_check_at {$this->tsType()}",
            'created_by    BIGINT',
            "created_at    {$this->nowDefault()}",
            "updated_at    {$this->nowDefault()}",
            $this->foreignKey('created_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (uuid)',
        ]);

        $this->createIndex('rotation_services', ['is_active']);

        // ------------------------------------------------------------------ //
        //  organization_integrations                                           //
        // ------------------------------------------------------------------ //
        // Привязка сервиса ротации к организации с зашифрованными credentials.
        // Одна организация может иметь несколько интеграций с одним сервисом
        // (например, разные credentials для разных окружений).
        $this->createTable('organization_integrations', [
            "id                   {$this->pkType()}",
            'uuid                 VARCHAR(36) NOT NULL',
            'organization_id      BIGINT NOT NULL',
            'rotation_service_id  BIGINT NOT NULL',
            'name                 VARCHAR(255) NOT NULL',
            // Credentials зашифрованы тем же master key
            'encrypted_credentials TEXT',
            'credentials_nonce    VARCHAR(48)',
            "is_active            {$this->boolType(true)}",
            'created_by           BIGINT',
            "created_at           {$this->nowDefault()}",
            "updated_at           {$this->nowDefault()}",
            $this->foreignKey('organization_id', 'organizations', 'id', 'CASCADE'),
            $this->foreignKey('rotation_service_id', 'rotation_services', 'id', 'RESTRICT'),
            $this->foreignKey('created_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (uuid)',
        ]);

        $this->createIndex('organization_integrations', ['organization_id']);
        $this->createIndex('organization_integrations', ['rotation_service_id']);

        // Теперь добавляем FK в secrets на organization_integrations
        // (в migration 004 это поле было BIGINT без FK, т.к. таблица не существовала)
        if ($this->driver === 'pgsql') {
            $this->exec("
                ALTER TABLE secrets
                ADD CONSTRAINT fk_secrets_rotation_integration
                FOREIGN KEY (rotation_integration_id)
                REFERENCES organization_integrations(id)
                ON DELETE SET NULL
            ");
        }
        // В SQLite FK проверяется только при INSERT/UPDATE, не при ALTER TABLE
    }

    public function down(): void
    {
        if ($this->driver === 'pgsql') {
            $this->exec('ALTER TABLE secrets DROP CONSTRAINT IF EXISTS fk_secrets_rotation_integration');
        }
        $this->dropTable('organization_integrations');
        $this->dropTable('rotation_services');
        $this->dropTable('templates');
    }

    // ------------------------------------------------------------------ //
    //  Приватные методы                                                    //
    // ------------------------------------------------------------------ //

    private function insertSystemTemplates(): void
    {
        $uuid1 = $this->generateUuid();
        $uuid2 = $this->generateUuid();
        $uuid3 = $this->generateUuid();
        $uuid4 = $this->generateUuid();

        $templates = [
            [
                'uuid'        => $uuid1,
                'name'        => 'Password',
                'system_key'  => 'password.default',
                'type'        => 'password',
                'description' => 'Generate a secure random password',
                'config_json' => json_encode([
                    'min_length'   => 16,
                    'max_length'   => 256,
                    'use_upper'    => true,
                    'use_lower'    => true,
                    'use_digits'   => true,
                    'use_special'  => true,
                    'special_chars' => '!@#$%^&*()-_=+[]{}|;:,.<>?',
                ]),
                'is_system' => 1,
            ],
            [
                'uuid'        => $uuid2,
                'name'        => 'Strong Password (No Special)',
                'system_key'  => 'password.strong_no_special',
                'type'        => 'password',
                'description' => 'Password with letters and digits only',
                'config_json' => json_encode([
                    'min_length'  => 20,
                    'max_length'  => 64,
                    'use_upper'   => true,
                    'use_lower'   => true,
                    'use_digits'  => true,
                    'use_special' => false,
                ]),
                'is_system' => 1,
            ],
            [
                'uuid'        => $uuid3,
                'name'        => 'SSH Key RSA-4096',
                'system_key'  => 'ssh_key.rsa_4096',
                'type'        => 'ssh_key',
                'description' => 'Generate RSA 4096-bit SSH key pair',
                'config_json' => json_encode([
                    'algorithm' => 'rsa',
                    'bits'      => 4096,
                    'comment'   => 'passway-generated',
                ]),
                'is_system' => 1,
            ],
            [
                'uuid'        => $uuid4,
                'name'        => 'SSH Key Ed25519',
                'system_key'  => 'ssh_key.ed25519',
                'type'        => 'ssh_key',
                'description' => 'Generate Ed25519 SSH key pair (recommended)',
                'config_json' => json_encode([
                    'algorithm' => 'ed25519',
                    'comment'   => 'passway-generated',
                ]),
                'is_system' => 1,
            ],
        ];

        foreach ($templates as $tpl) {
            if ($this->driver === 'pgsql') {
                $this->db->getPdo()->prepare("
                    INSERT INTO templates (uuid, organization_id, name, system_key, type, description, config_json, is_system)
                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT (uuid) DO NOTHING
                ")->execute([
                    $tpl['uuid'], $tpl['name'], $tpl['system_key'], $tpl['type'],
                    $tpl['description'], $tpl['config_json'], $tpl['is_system'],
                ]);
            } else {
                $this->db->getPdo()->prepare("
                    INSERT OR IGNORE INTO templates (uuid, organization_id, name, system_key, type, description, config_json, is_system)
                    VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $tpl['uuid'], $tpl['name'], $tpl['system_key'], $tpl['type'],
                    $tpl['description'], $tpl['config_json'], $tpl['is_system'],
                ]);
            }
        }
    }

    private function generateUuid(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
