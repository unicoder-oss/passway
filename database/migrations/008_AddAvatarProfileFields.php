<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

final class AddAvatarProfileFields extends Migration
{
    public function up(): void
    {
        $this->addColumn('users', 'nickname', 'VARCHAR(255)');
        $this->addColumn('users', 'avatar_color', 'VARCHAR(32)');
        $this->addColumn('users', 'avatar_path', 'VARCHAR(255)');

        $this->addColumn('organizations', 'description', 'TEXT');
        $this->addColumn('organizations', 'avatar_path', 'VARCHAR(255)');
    }

    public function down(): void
    {
        if ($this->driver === 'pgsql') {
            $this->exec('ALTER TABLE organizations DROP COLUMN IF EXISTS avatar_path');
            $this->exec('ALTER TABLE organizations DROP COLUMN IF EXISTS description');
            $this->exec('ALTER TABLE users DROP COLUMN IF EXISTS avatar_path');
            $this->exec('ALTER TABLE users DROP COLUMN IF EXISTS avatar_color');
            $this->exec('ALTER TABLE users DROP COLUMN IF EXISTS nickname');
            return;
        }

        $this->recreateUsersTableWithoutAvatarFields();
        $this->recreateOrganizationsTableWithoutAvatarFields();
    }

    private function recreateUsersTableWithoutAvatarFields(): void
    {
        $this->exec('PRAGMA foreign_keys = OFF');
        $this->exec('ALTER TABLE users RENAME TO users_old');

        $this->createTable('users', [
            "id              {$this->pkType()}",
            'uuid            VARCHAR(36) NOT NULL',
            'email           VARCHAR(255) NOT NULL',
            'password_hash   VARCHAR(255)',
            'totp_secret     TEXT',
            'totp_nonce      VARCHAR(48)',
            "totp_enabled    {$this->boolType(false)}",
            "is_active       {$this->boolType(true)}",
            "email_verified  {$this->boolType(false)}",
            "created_at      {$this->nowDefault()}",
            "updated_at      {$this->nowDefault()}",
            "last_login_at   {$this->tsType()}",
            'last_login_ip   VARCHAR(45)',
        ], [
            'UNIQUE (uuid)',
            'UNIQUE (email)',
        ]);

        $this->exec('INSERT INTO users (id, uuid, email, password_hash, totp_secret, totp_nonce, totp_enabled, is_active, email_verified, created_at, updated_at, last_login_at, last_login_ip) SELECT id, uuid, email, password_hash, totp_secret, totp_nonce, totp_enabled, is_active, email_verified, created_at, updated_at, last_login_at, last_login_ip FROM users_old');
        $this->dropTable('users_old');

        $this->createIndex('users', ['email']);
        $this->createIndex('users', ['uuid']);
        $this->createIndex('users', ['is_active']);
        $this->exec('PRAGMA foreign_keys = ON');
    }

    private function recreateOrganizationsTableWithoutAvatarFields(): void
    {
        $this->exec('PRAGMA foreign_keys = OFF');
        $this->exec('ALTER TABLE organizations RENAME TO organizations_old');

        $this->createTable('organizations', [
            "id          {$this->pkType()}",
            'uuid        VARCHAR(36) NOT NULL',
            'name        VARCHAR(255) NOT NULL',
            'slug        VARCHAR(100) NOT NULL',
            'owner_id    BIGINT NOT NULL',
            "is_active   {$this->boolType(true)}",
            "created_at  {$this->nowDefault()}",
            "updated_at  {$this->nowDefault()}",
            "deleted_at  {$this->tsType()}",
            $this->foreignKey('owner_id', 'users', 'id', 'RESTRICT'),
        ], [
            'UNIQUE (uuid)',
            'UNIQUE (slug)',
        ]);

        $this->exec('INSERT INTO organizations (id, uuid, name, slug, owner_id, is_active, created_at, updated_at, deleted_at) SELECT id, uuid, name, slug, owner_id, is_active, created_at, updated_at, deleted_at FROM organizations_old');
        $this->dropTable('organizations_old');

        $this->createIndex('organizations', ['slug'], unique: true);
        $this->createIndex('organizations', ['owner_id']);
        $this->createIndex('organizations', ['deleted_at']);
        $this->exec('PRAGMA foreign_keys = ON');
    }
}
