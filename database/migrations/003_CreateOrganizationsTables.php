<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Миграция 003: Организации, инвайты, роли, группы.
 *
 * Таблицы:
 *   - organizations        — организации
 *   - organization_members — участники с ролями
 *   - invite_links         — инвайт-ссылки (1 час, одноразовые)
 *   - groups               — группы пользователей внутри организации
 *   - group_members        — принадлежность пользователей к группам
 */
final class CreateOrganizationsTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  organizations                                                       //
        // ------------------------------------------------------------------ //
        $this->createTable('organizations', [
            "id          {$this->pkType()}",
            'uuid        VARCHAR(36) NOT NULL',
            'name        VARCHAR(255) NOT NULL',
            // slug для URL: только [a-z0-9-]
            'slug        VARCHAR(100) NOT NULL',
            // owner_id — текущий Владелец организации
            'owner_id    BIGINT NOT NULL',
            "is_active   {$this->boolType(true)}",
            "created_at  {$this->nowDefault()}",
            "updated_at  {$this->nowDefault()}",
            // Мягкое удаление
            "deleted_at  {$this->tsType()}",
            $this->foreignKey('owner_id', 'users', 'id', 'RESTRICT'),
        ], [
            'UNIQUE (uuid)',
            'UNIQUE (slug)',
        ]);

        $this->createIndex('organizations', ['slug'], unique: true);
        $this->createIndex('organizations', ['owner_id']);
        $this->createIndex('organizations', ['deleted_at']);

        // ------------------------------------------------------------------ //
        //  organization_members                                                //
        // ------------------------------------------------------------------ //
        // Роли: owner | admin | moderator | user | observer
        $this->createTable('organization_members', [
            "id               {$this->pkType()}",
            'organization_id  BIGINT NOT NULL',
            'user_id          BIGINT NOT NULL',
            // Валидные роли — проверяются на уровне приложения
            "role             VARCHAR(50) NOT NULL DEFAULT 'user'",
            'invited_by       BIGINT',
            "joined_at        {$this->nowDefault()}",
            $this->foreignKey('organization_id', 'organizations', 'id', 'CASCADE'),
            $this->foreignKey('user_id', 'users', 'id', 'CASCADE'),
            $this->foreignKey('invited_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (organization_id, user_id)',
        ]);

        $this->createIndex('organization_members', ['organization_id']);
        $this->createIndex('organization_members', ['user_id']);
        $this->createIndex('organization_members', ['role']);

        // ------------------------------------------------------------------ //
        //  invite_links                                                        //
        // ------------------------------------------------------------------ //
        // Типы:
        //   create_org — зарегистрироваться и создать организацию
        //   join_org   — зарегистрироваться и вступить в организацию
        $this->createTable('invite_links', [
            "id               {$this->pkType()}",
            'uuid             VARCHAR(36) NOT NULL',
            // 32 random bytes -> 64 hex chars
            'token            VARCHAR(64) NOT NULL',
            "type             VARCHAR(50) NOT NULL",
            'organization_id  BIGINT',
            "role             VARCHAR(50) NOT NULL DEFAULT 'user'",
            'created_by       BIGINT',
            'used_by          BIGINT',
            "expires_at       {$this->tsType()} NOT NULL",
            "used_at          {$this->tsType()}",
            "created_at       {$this->nowDefault()}",
            $this->foreignKey('organization_id', 'organizations', 'id', 'CASCADE'),
            $this->foreignKey('created_by', 'users', 'id', 'SET NULL'),
            $this->foreignKey('used_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (uuid)',
            'UNIQUE (token)',
        ]);

        $this->createIndex('invite_links', ['token'], unique: true);
        $this->createIndex('invite_links', ['expires_at']);
        $this->createIndex('invite_links', ['used_at']);

        // ------------------------------------------------------------------ //
        //  groups                                                              //
        // ------------------------------------------------------------------ //
        $this->createTable('groups', [
            "id               {$this->pkType()}",
            'uuid             VARCHAR(36) NOT NULL',
            'organization_id  BIGINT NOT NULL',
            'name             VARCHAR(255) NOT NULL',
            'description      TEXT',
            'created_by       BIGINT',
            "created_at       {$this->nowDefault()}",
            "updated_at       {$this->nowDefault()}",
            $this->foreignKey('organization_id', 'organizations', 'id', 'CASCADE'),
            $this->foreignKey('created_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (uuid)',
            'UNIQUE (organization_id, name)',
        ]);

        $this->createIndex('groups', ['organization_id']);

        // ------------------------------------------------------------------ //
        //  group_members                                                       //
        // ------------------------------------------------------------------ //
        $this->createTable('group_members', [
            "id        {$this->pkType()}",
            'group_id  BIGINT NOT NULL',
            'user_id   BIGINT NOT NULL',
            'added_by  BIGINT',
            "added_at  {$this->nowDefault()}",
            $this->foreignKey('group_id', 'groups', 'id', 'CASCADE'),
            $this->foreignKey('user_id', 'users', 'id', 'CASCADE'),
            $this->foreignKey('added_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (group_id, user_id)',
        ]);

        $this->createIndex('group_members', ['group_id']);
        $this->createIndex('group_members', ['user_id']);
    }

    public function down(): void
    {
        $this->dropTable('group_members');
        $this->dropTable('groups');
        $this->dropTable('invite_links');
        $this->dropTable('organization_members');
        $this->dropTable('organizations');
    }
}
