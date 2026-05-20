<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Миграция 002: Пользователи, сессии и passkeys (WebAuthn).
 *
 * Таблицы:
 *   - users       — учётные записи пользователей
 *   - sessions    — сессионные токены (хранятся как SHA-256)
 *   - passkeys    — WebAuthn/FIDO2 credentials
 */
final class CreateUsersAuthTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  users                                                               //
        // ------------------------------------------------------------------ //
        $this->createTable('users', [
            "id              {$this->pkType()}",
            'uuid            VARCHAR(36) NOT NULL',
            'email           VARCHAR(255) NOT NULL',
            // password_hash может быть NULL если пользователь использует только Passkey
            'password_hash   VARCHAR(255)',
            // TOTP secret хранится зашифрованным (XChaCha20-Poly1305)
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

        $this->createIndex('users', ['email']);
        $this->createIndex('users', ['uuid']);
        $this->createIndex('users', ['is_active']);

        // ------------------------------------------------------------------ //
        //  sessions                                                            //
        // ------------------------------------------------------------------ //
        // token_hash = SHA-256(raw 64-hex token), сам токен НИКОГДА не хранится
        $this->createTable('sessions', [
            "id                {$this->pkType()}",
            'uuid              VARCHAR(36) NOT NULL',
            'user_id           BIGINT NOT NULL',
            // SHA-256 от raw-токена (64 hex символа)
            'token_hash        VARCHAR(64) NOT NULL',
            'ip_address        VARCHAR(45)',
            'user_agent        TEXT',
            "expires_at        {$this->tsType()} NOT NULL",
            "created_at        {$this->nowDefault()}",
            "last_activity_at  {$this->nowDefault()}",
            $this->foreignKey('user_id', 'users', 'id', 'CASCADE'),
        ], [
            'UNIQUE (uuid)',
            'UNIQUE (token_hash)',
        ]);

        $this->createIndex('sessions', ['token_hash'], unique: true);
        $this->createIndex('sessions', ['user_id']);
        $this->createIndex('sessions', ['expires_at']);

        // ------------------------------------------------------------------ //
        //  passkeys (WebAuthn / FIDO2)                                        //
        // ------------------------------------------------------------------ //
        $this->createTable('passkeys', [
            "id             {$this->pkType()}",
            'uuid           VARCHAR(36) NOT NULL',
            'user_id        BIGINT NOT NULL',
            // credential_id — base64url-encoded ID от аутентификатора
            'credential_id  TEXT NOT NULL',
            // Публичный ключ в формате COSE (CBOR-encoded), stored as base64
            'public_key     TEXT NOT NULL',
            'sign_count     BIGINT NOT NULL DEFAULT 0',
            // Понятное имя (например, "MacBook Touch ID")
            'name           VARCHAR(255)',
            // AAGUID — идентификатор модели аутентификатора
            'aaguid         VARCHAR(36)',
            // JSON-массив транспортов: ["usb", "ble", "nfc", "internal"]
            'transports     TEXT',
            "created_at     {$this->nowDefault()}",
            "last_used_at   {$this->tsType()}",
            $this->foreignKey('user_id', 'users', 'id', 'CASCADE'),
        ], [
            'UNIQUE (uuid)',
            'UNIQUE (credential_id)',
        ]);

        $this->createIndex('passkeys', ['user_id']);
        $this->createIndex('passkeys', ['credential_id'], unique: true);
    }

    public function down(): void
    {
        // Удаляем в обратном порядке зависимостей
        $this->dropTable('passkeys');
        $this->dropTable('sessions');
        $this->dropTable('users');
    }
}
