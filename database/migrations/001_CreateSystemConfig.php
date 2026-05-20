<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Миграция 001: Системная конфигурация.
 *
 * Создаёт таблицу system_config для хранения системных параметров
 * в формате ключ-значение (режим развёртывания, флаги первоначальной настройки и т.п.).
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

        // Начальные системные значения
        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO system_config (key, value) VALUES (?, ?) ON CONFLICT (key) DO NOTHING"
        );

        // Для SQLite используем INSERT OR IGNORE
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
