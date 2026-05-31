<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

final class AddUserInterfacePreferences extends Migration
{
    public function up(): void
    {
        $this->addColumn('users', 'locale_preference', "VARCHAR(16) NOT NULL DEFAULT 'system'");
        $this->addColumn('users', 'theme_preference', "VARCHAR(16) NOT NULL DEFAULT 'system'");
    }

    public function down(): void
    {
        if ($this->driver === 'pgsql') {
            $this->exec('ALTER TABLE users DROP COLUMN IF EXISTS theme_preference');
            $this->exec('ALTER TABLE users DROP COLUMN IF EXISTS locale_preference');
            return;
        }

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
            'nickname        VARCHAR(255)',
            'avatar_color    VARCHAR(32)',
            'avatar_path     VARCHAR(255)',
        ], [
            'UNIQUE (uuid)',
            'UNIQUE (email)',
        ]);

        $this->exec('INSERT INTO users (id, uuid, email, password_hash, totp_secret, totp_nonce, totp_enabled, is_active, email_verified, created_at, updated_at, last_login_at, last_login_ip, nickname, avatar_color, avatar_path) SELECT id, uuid, email, password_hash, totp_secret, totp_nonce, totp_enabled, is_active, email_verified, created_at, updated_at, last_login_at, last_login_ip, nickname, avatar_color, avatar_path FROM users_old');
        $this->dropTable('users_old');

        $this->createIndex('users', ['email']);
        $this->createIndex('users', ['uuid']);
        $this->createIndex('users', ['is_active']);
        $this->exec('PRAGMA foreign_keys = ON');
    }
}
