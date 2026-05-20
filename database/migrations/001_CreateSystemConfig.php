<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Migration 001: System configuration.
 *
 * Creates the system_config table for storing system parameters
 * in key-value format (deployment mode, initial setup flags, etc.).
 */
final class CreateSystemConfig extends Migration
{
    public function up(): void
    {
        $this->createTable('system_config', [
            "id          {$this->pkType()}",
            'key         VARCHAR(255) NOT NULL',
            'value       TEXT',
            "created_at  {$this->nowDefault()}",
            "updated_at  {$this->nowDefault()}",
        ], [
            'UNIQUE (key)',
        ]);

        // Initial system values
        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO system_config (key, value) VALUES (?, ?) ON CONFLICT (key) DO NOTHING"
        );

        // Use INSERT OR IGNORE for SQLite
        if ($this->driver === 'sqlite') {
            $stmt = $this->db->getPdo()->prepare(
                "INSERT OR IGNORE INTO system_config (key, value) VALUES (?, ?)"
            );
        }

        $defaults = [
            ['setup_complete',    '0'],
            ['deploy_mode',       ''],
            ['setup_token_hash',  ''],
            ['app_version',       '1.0.0'],
            ['db_version',        '1'],
        ];

        foreach ($defaults as [$key, $value]) {
            $stmt->execute([$key, $value]);
        }
    }

    public function down(): void
    {
        $this->dropTable('system_config');
    }
}
