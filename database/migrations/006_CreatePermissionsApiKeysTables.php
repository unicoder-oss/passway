<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Migration 006: Access control and API keys.
 *
 * Tables:
 *   - api_keys            — API keys (format sv_{env}_{random64})
 *   - api_key_permissions — API key permissions for resources
 *   - user_permissions    — granular permissions for users and groups
 *
 * Permission model:
 *   subject_type: user | group (for a directory or secret)
 *   permission:   read | write | delete | create_subdirectories
 *   is_deny:      explicit deny (overrides allow)
 *   expires_at:   temporary permission (NULL = permanent)
 */
final class CreatePermissionsApiKeysTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  api_keys                                                            //
        // ------------------------------------------------------------------ //
        // Key format: sv_{env}_{64 random hex chars}
        // Only the SHA-256 hash is stored in the DB; the key itself is shown ONCE at creation.
        $this->createTable('api_keys', [
            "id               {$this->pkType()}",
            'uuid             VARCHAR(36) NOT NULL',
            'organization_id  BIGINT NOT NULL',
            // user_id — key owner (can be NULL for "system" keys)
            'user_id          BIGINT',
            'name             VARCHAR(255) NOT NULL',
            // SHA-256(raw_key) — for lookup during authentication
            'key_hash         VARCHAR(64) NOT NULL',
            // First key characters for identification in the UI (sv_prod_)
            'key_prefix       VARCHAR(20) NOT NULL',
            // production | staging | development
            "environment      VARCHAR(50) NOT NULL DEFAULT 'production'",
            "is_active        {$this->boolType(true)}",
            "last_used_at     {$this->tsType()}",
            // NULL = no expiration limit
            "expires_at       {$this->tsType()}",
            "created_at       {$this->nowDefault()}",
            $this->foreignKey('organization_id', 'organizations', 'id', 'CASCADE'),
            $this->foreignKey('user_id', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (uuid)',
            'UNIQUE (key_hash)',
        ]);

        $this->createIndex('api_keys', ['key_hash'], unique: true);
        $this->createIndex('api_keys', ['organization_id']);
        $this->createIndex('api_keys', ['user_id']);
        $this->createIndex('api_keys', ['is_active']);
        $this->createIndex('api_keys', ['expires_at']);

        // ------------------------------------------------------------------ //
        //  api_key_permissions                                                 //
        // ------------------------------------------------------------------ //
        // resource_id = NULL means permissions for all resources of the specified type.
        $this->createTable('api_key_permissions', [
            "id             {$this->pkType()}",
            'api_key_id     BIGINT NOT NULL',
            // directory | secret | organization
            'resource_type  VARCHAR(50) NOT NULL',
            // NULL = all resources of this type
            'resource_id    BIGINT',
            // read | write | delete | create_subdirectories
            'permission     VARCHAR(50) NOT NULL',
            "created_at     {$this->nowDefault()}",
            $this->foreignKey('api_key_id', 'api_keys', 'id', 'CASCADE'),
        ], [
            'UNIQUE (api_key_id, resource_type, resource_id, permission)',
        ]);

        $this->createIndex('api_key_permissions', ['api_key_id']);
        $this->createIndex('api_key_permissions', ['resource_type', 'resource_id']);

        // ------------------------------------------------------------------ //
        //  user_permissions                                                    //
        // ------------------------------------------------------------------ //
        // Granular permissions for subjects (user/group) on resources (directory/secret).
        //
        // Permission check priority:
        //  1. Explicit deny (is_deny=true) -> access denied
        //  2. Explicit allow -> access allowed
        //  3. Inherited permission from the parent directory -> applied
        //  4. No permission -> access denied
        $this->createTable('user_permissions', [
            "id             {$this->pkType()}",
            // user | group
            'subject_type   VARCHAR(50) NOT NULL',
            'subject_id     BIGINT NOT NULL',
            // directory | secret
            'resource_type  VARCHAR(50) NOT NULL',
            'resource_id    BIGINT NOT NULL',
            // read | write | delete | create_subdirectories
            'permission     VARCHAR(50) NOT NULL',
            // true = explicit deny (overrides allow)
            "is_deny        {$this->boolType(false)}",
            // Temporary permission: NULL = permanent
            "expires_at     {$this->tsType()}",
            'granted_by     BIGINT',
            "created_at     {$this->nowDefault()}",
            $this->foreignKey('granted_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (subject_type, subject_id, resource_type, resource_id, permission)',
        ]);

        $this->createIndex('user_permissions', ['subject_type', 'subject_id']);
        $this->createIndex('user_permissions', ['resource_type', 'resource_id']);
        $this->createIndex('user_permissions', ['expires_at']);
        $this->createIndex('user_permissions', ['is_deny']);
    }

    public function down(): void
    {
        $this->dropTable('user_permissions');
        $this->dropTable('api_key_permissions');
        $this->dropTable('api_keys');
    }
}
