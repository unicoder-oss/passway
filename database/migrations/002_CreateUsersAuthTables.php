<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Migration 002: Users, sessions, and passkeys (WebAuthn).
 *
 * Tables:
 *   - users       — user accounts
 *   - sessions    — session tokens (stored as SHA-256)
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
            // password_hash can be NULL if the user only uses a passkey
            'password_hash   VARCHAR(255)',
            // TOTP secret is stored encrypted (XChaCha20-Poly1305)
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
        // token_hash = SHA-256(raw 64-hex token), the token itself is NEVER stored
        $this->createTable('sessions', [
            "id                {$this->pkType()}",
            'uuid              VARCHAR(36) NOT NULL',
            'user_id           BIGINT NOT NULL',
            // SHA-256 of the raw token (64 hex chars)
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
            // credential_id — base64url-encoded ID from the authenticator
            'credential_id  TEXT NOT NULL',
            // Public key in COSE format (CBOR-encoded), stored as base64
            'public_key     TEXT NOT NULL',
            'sign_count     BIGINT NOT NULL DEFAULT 0',
            // Human-readable name (for example, "MacBook Touch ID")
            'name           VARCHAR(255)',
            // AAGUID — authenticator model identifier
            'aaguid         VARCHAR(36)',
            // JSON array of transports: ["usb", "ble", "nfc", "internal"]
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
        // Drop in reverse dependency order
        $this->dropTable('passkeys');
        $this->dropTable('sessions');
        $this->dropTable('users');
    }
}
