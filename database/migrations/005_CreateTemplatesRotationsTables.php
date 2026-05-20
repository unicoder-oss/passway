<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Migration 005: Secret templates and rotation services.
 *
 * Tables:
 *   - templates                — built-in and user templates
 *   - rotation_services        — external rotation services (registered by sysadmin)
 *   - organization_integrations — rotation service configuration for organizations
 */
final class CreateTemplatesRotationsTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  templates                                                           //
        // ------------------------------------------------------------------ //
        // organization_id = NULL -> system template (built-in, not editable)
        // Built-in templates: password, ssh_key
        $this->createTable('templates', [
            "id               {$this->pkType()}",
            'uuid             VARCHAR(36) NOT NULL',
            // NULL = system template
            'organization_id  BIGINT',
            'name             VARCHAR(255) NOT NULL',
            'system_key       VARCHAR(120)',
            // password | ssh_key
            'type             VARCHAR(50) NOT NULL',
            'description      TEXT',
            // JSON with generation parameters:
            // password: {"min_length":8,"max_length":256,"use_upper":true,"use_lower":true,"use_digits":true,"use_special":true}
            // ssh_key:  {"algorithm":"rsa","bits":4096} or {"algorithm":"ed25519"}
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

        // Insert built-in system templates
        $this->insertSystemTemplates();

        // ------------------------------------------------------------------ //
        //  rotation_services                                                   //
        // ------------------------------------------------------------------ //
        // Rotation services are registered by a system administrator.
        // Protocol:
        //   GET  /health  — availability check (returns {"status":"ok"})
        //   GET  /spec    — specification (list of supported secret types and parameters)
        //   POST /validate — validate the current value
        //   POST /rotate   — rotate the secret
        $this->createTable('rotation_services', [
            "id            {$this->pkType()}",
            'uuid          VARCHAR(36) NOT NULL',
            'name          VARCHAR(255) NOT NULL',
            // Base service URL (without a trailing /)
            'url           VARCHAR(500) NOT NULL',
            'health_url    VARCHAR(500)',
            // JSON specification loaded from /spec
            'spec_json     TEXT',
            "is_active     {$this->boolType(true)}",
            // Whether the last health check passed
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
        // Bind a rotation service to an organization with encrypted credentials.
        // One organization can have multiple integrations with one service
        // (for example, different credentials for different environments).
        $this->createTable('organization_integrations', [
            "id                   {$this->pkType()}",
            'uuid                 VARCHAR(36) NOT NULL',
            'organization_id      BIGINT NOT NULL',
            'rotation_service_id  BIGINT NOT NULL',
            'name                 VARCHAR(255) NOT NULL',
            // Credentials are encrypted with the same master key
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

        // Now add an FK from secrets to organization_integrations
        // (in migration 004 this field was BIGINT without an FK because the table did not exist)
        if ($this->driver === 'pgsql') {
            $this->exec("
                ALTER TABLE secrets
                ADD CONSTRAINT fk_secrets_rotation_integration
                FOREIGN KEY (rotation_integration_id)
                REFERENCES organization_integrations(id)
                ON DELETE SET NULL
            ");
        }
        // SQLite checks FKs only on INSERT/UPDATE, not on ALTER TABLE
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
    //  Private methods                                                   //
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
