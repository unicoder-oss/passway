<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Миграция 004: Каталоги и секреты.
 *
 * Таблицы:
 *   - directories              — иерархия каталогов (макс. 20 уровней)
 *   - secrets                  — зашифрованные секреты (XChaCha20-Poly1305)
 *   - secret_metadata          — несекретные метаданные в открытом виде
 *   - secret_rotation_history  — история ротации (последние 10 версий)
 */
final class CreateDirectoriesSecretsTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  directories                                                         //
        // ------------------------------------------------------------------ //
        // Иерархия через parent_id + materialized path для быстрого поиска
        // depth: 0 = корневой каталог организации
        // path: /org_uuid/dir_uuid/.../dir_uuid (для быстрого поиска поддерева)
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
            // Самореференция: ON DELETE CASCADE удалит поддерево
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
        // Значение всегда хранится зашифрованным (encrypted_value + nonce).
        // Типы:
        //   static   — введено вручную
        //   template — сгенерировано по шаблону
        //   dynamic  — управляется сервисом ротации
        $this->createTable('secrets', [
            "id                    {$this->pkType()}",
            'uuid                  VARCHAR(36) NOT NULL',
            'directory_id          BIGINT NOT NULL',
            'organization_id       BIGINT NOT NULL',
            'name                  VARCHAR(255) NOT NULL',
            "type                  VARCHAR(50) NOT NULL DEFAULT 'static'",
            // Зашифрованное значение (XChaCha20-Poly1305, base64)
            'encrypted_value       TEXT NOT NULL',
            // Nonce (24 байта, hex), уникален для каждого значения
            'nonce                 VARCHAR(48) NOT NULL',
            // Ссылка на шаблон (если type = template)
            'template_id           BIGINT',
            // Флаг: доступ требует одобрения уполномоченного пользователя
            "requires_approval     {$this->boolType(false)}",
            // Ссылка на organization_integrations (сервис ротации)
            'rotation_integration_id BIGINT',
            // Cron-выражение для автоматической ротации (например: "0 2 * * 1")
            'rotation_schedule     VARCHAR(100)',
            "last_rotated_at       {$this->tsType()}",
            // Версия для optimistic locking и истории ротации
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
            // Уникальность имени секрета в каталоге (среди неудалённых)
            // NULL deleted_at — проверяется на уровне приложения
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
        // Несекретные метаданные — хранятся в открытом виде.
        // Используются для поиска и организации секретов.
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
        // Хранит последние 10 версий секрета для возможности отката.
        // Старые версии удаляются автоматически (cron или при ротации).
        $this->createTable('secret_rotation_history', [
            "id               {$this->pkType()}",
            'secret_id        BIGINT NOT NULL',
            // Предыдущее зашифрованное значение
            'encrypted_value  TEXT NOT NULL',
            'nonce            VARCHAR(48) NOT NULL',
            // Номер версии на момент ротации
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
