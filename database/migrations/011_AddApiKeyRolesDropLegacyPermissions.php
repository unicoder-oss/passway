<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

final class AddApiKeyRolesDropLegacyPermissions extends Migration
{
    public function up(): void
    {
        $this->addColumn('api_keys', 'role', "VARCHAR(32) NOT NULL DEFAULT 'reader'");
        $this->dropTable('api_key_permissions');
    }

    public function down(): void
    {
        $this->createTable('api_key_permissions', [
            "id             {$this->pkType()}",
            'api_key_id     BIGINT NOT NULL',
            'resource_type  VARCHAR(50) NOT NULL',
            'resource_id    BIGINT',
            'permission     VARCHAR(50) NOT NULL',
            "created_at     {$this->nowDefault()}",
            $this->foreignKey('api_key_id', 'api_keys', 'id', 'CASCADE'),
        ], [
            'UNIQUE (api_key_id, resource_type, resource_id, permission)',
        ]);

        $this->createIndex('api_key_permissions', ['api_key_id']);
        $this->createIndex('api_key_permissions', ['resource_type', 'resource_id']);

        $this->exec('ALTER TABLE api_keys DROP COLUMN role');
    }
}
