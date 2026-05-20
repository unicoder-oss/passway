<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Migration 004: Directories and secrets.
 *
 * Tables:
 *   - directories              — directory hierarchy (max. 20 levels)
 *   - secrets                  — encrypted secrets (XChaCha20-Poly1305)
 *   - secret_metadata          — non-secret metadata in plain text
 *   - secret_rotation_history  — rotation history (last 10 versions)
 */
final class CreateDirectoriesSecretsTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  directories                                                         //
        // ------------------------------------------------------------------ //
        // Hierarchy via parent_id + materialized path for fast lookup
        // depth: 0 = organization root directory
        // path: /org_uuid/dir_uuid/.../dir_uuid (for fast subtree lookup)
        $this->createTable('directories', [
            "id               {$this->pkType()}",
            'uuid             VARCHAR(36) NOT NULL',
            'organization_id  BIGINT NOT NULL',
            'parent_id        BIGINT',
            'name             VARCHAR(255) NOT NULL',
            'depth            INTEGER NOT NULL DEFAULT 0',
            // Materialized path — slash-separated UUIDs
            'path             TEXT NOT NULL',
            'created_by       BIGINT',
            "created_at       {$this->nowDefault()}",
            "updated_at       {$this->nowDefault()}",
            "deleted_at       {$this->tsType()}",
            $this->foreignKey('organization_id', 'organizations', 'id', 'CASCADE'),
            // Self-reference: ON DELETE CASCADE will delete the subtree
            $this->foreignKey('parent_id', 'directories', 'id', 'CASCADE'),
            $this->foreignKey('created_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (uuid)',
        ]);

        $this->createIndex('directories', ['organization_id']);
        $this->createIndex('directories', ['parent_id']);
        $this->createIndex('directories', ['path']);
        $this->createIndex('directories', ['deleted_at']);

        // ------------------------------------------------------------------ //
        //  secrets                                                             //
        // ------------------------------------------------------------------ //
        // The value is always stored encrypted (encrypted_value + nonce).
        // Types:
        //   static   — entered manually
        //   template — generated from a template
        //   dynamic  — managed by a rotation service
        $this->createTable('secrets', [
            "id                    {$this->pkType()}",
            'uuid                  VARCHAR(36) NOT NULL',
            'directory_id          BIGINT NOT NULL',
            'organization_id       BIGINT NOT NULL',
            'name                  VARCHAR(255) NOT NULL',
            "type                  VARCHAR(50) NOT NULL DEFAULT 'static'",
            // Encrypted value (XChaCha20-Poly1305, base64)
            'encrypted_value       TEXT NOT NULL',
            // Nonce (24 bytes, hex), unique for each value
            'nonce                 VARCHAR(48) NOT NULL',
            // Reference to the template (if type = template)
            'template_id           BIGINT',
            // Flag: access requires approval by an authorized user
            "requires_approval     {$this->boolType(false)}",
            // Reference to organization_integrations (rotation service)
            'rotation_integration_id BIGINT',
            // Cron expression for automatic rotation (for example: "0 2 * * 1")
            'rotation_schedule     VARCHAR(100)',
            "last_rotated_at       {$this->tsType()}",
            // Version for optimistic locking and rotation history
            'version               INTEGER NOT NULL DEFAULT 1',
            'created_by            BIGINT',
            "created_at            {$this->nowDefault()}",
            "updated_at            {$this->nowDefault()}",
            "deleted_at            {$this->tsType()}",
            $this->foreignKey('directory_id', 'directories', 'id', 'CASCADE'),
            $this->foreignKey('organization_id', 'organizations', 'id', 'CASCADE'),
            $this->foreignKey('created_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (uuid)',
            // Secret name uniqueness within a directory (among non-deleted records)
            // NULL deleted_at is checked at the application level
        ]);

        $this->createIndex('secrets', ['directory_id']);
        $this->createIndex('secrets', ['organization_id']);
        $this->createIndex('secrets', ['uuid']);
        $this->createIndex('secrets', ['type']);
        $this->createIndex('secrets', ['deleted_at']);
        $this->createIndex('secrets', ['last_rotated_at']);

        // ------------------------------------------------------------------ //
        //  secret_metadata                                                     //
        // ------------------------------------------------------------------ //
        // Non-secret metadata is stored in plain text.
        // Used for searching and organizing secrets.
        $this->createTable('secret_metadata', [
            "id          {$this->pkType()}",
            'secret_id   BIGINT NOT NULL',
            'key         VARCHAR(255) NOT NULL',
            'value       TEXT',
            "created_at  {$this->nowDefault()}",
            "updated_at  {$this->nowDefault()}",
            $this->foreignKey('secret_id', 'secrets', 'id', 'CASCADE'),
        ], [
            'UNIQUE (secret_id, key)',
        ]);

        $this->createIndex('secret_metadata', ['secret_id']);
        $this->createIndex('secret_metadata', ['key']);

        // ------------------------------------------------------------------ //
        //  secret_rotation_history                                             //
        // ------------------------------------------------------------------ //
        // Stores the last 10 secret versions to allow rollback.
        // Old versions are deleted automatically (cron or during rotation).
        $this->createTable('secret_rotation_history', [
            "id               {$this->pkType()}",
            'secret_id        BIGINT NOT NULL',
            // Previous encrypted value
            'encrypted_value  TEXT NOT NULL',
            'nonce            VARCHAR(48) NOT NULL',
            // Version number at rotation time
            'version          INTEGER NOT NULL',
            'rotated_by       BIGINT',
            // manual | scheduled | api
            "rotation_type    VARCHAR(50) NOT NULL DEFAULT 'manual'",
            // success | failed | rolled_back
            "status           VARCHAR(50) NOT NULL DEFAULT 'success'",
            'error_message    TEXT',
            "created_at       {$this->nowDefault()}",
            $this->foreignKey('secret_id', 'secrets', 'id', 'CASCADE'),
            $this->foreignKey('rotated_by', 'users', 'id', 'SET NULL'),
        ]);

        $this->createIndex('secret_rotation_history', ['secret_id']);
        $this->createIndex('secret_rotation_history', ['version']);
        $this->createIndex('secret_rotation_history', ['created_at']);
    }

    public function down(): void
    {
        $this->dropTable('secret_rotation_history');
        $this->dropTable('secret_metadata');
        $this->dropTable('secrets');
        $this->dropTable('directories');
    }
}
