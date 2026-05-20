<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Миграция 006: Разграничение доступа и API-ключи.
 *
 * Таблицы:
 *   - api_keys            — API-ключи (формат sv_{env}_{random64})
 *   - api_key_permissions — права API-ключей на ресурсы
 *   - user_permissions    — гранулярные права пользователей и групп
 *
 * Модель прав:
 *   subject_type: user | group (на каталог или секрет)
 *   permission:   read | write | delete | create_subdirectories
 *   is_deny:      явный запрет (переопределяет разрешение)
 *   expires_at:   временное разрешение (NULL = постоянное)
 */
final class CreatePermissionsApiKeysTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  api_keys                                                            //
        // ------------------------------------------------------------------ //
        // Формат ключа: sv_{env}_{64 random hex chars}
        // В БД хранится только SHA-256 хэш — сам ключ показывается ОДИН раз при создании.
        $this->createTable('api_keys', [
            "id               {$this->pkType()}",
            'uuid             VARCHAR(36) NOT NULL',
            'organization_id  BIGINT NOT NULL',
            // user_id — владелец ключа (может быть NULL для "системных" ключей)
            'user_id          BIGINT',
            'name             VARCHAR(255) NOT NULL',
            // SHA-256(raw_key) — для поиска при аутентификации
            'key_hash         VARCHAR(64) NOT NULL',
            // Первые символы ключа для идентификации в UI (sv_prod_)
            'key_prefix       VARCHAR(20) NOT NULL',
            // production | staging | development
            "environment      VARCHAR(50) NOT NULL DEFAULT 'production'",
            "is_active        {$this->boolType(true)}",
            "last_used_at     {$this->tsType()}",
            // NULL = без ограничения срока
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
        // resource_id = NULL означает права на все ресурсы указанного типа.
        $this->createTable('api_key_permissions', [
            "id             {$this->pkType()}",
            'api_key_id     BIGINT NOT NULL',
            // directory | secret | organization
            'resource_type  VARCHAR(50) NOT NULL',
            // NULL = все ресурсы данного типа
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
        // Гранулярные права субъектов (user/group) на ресурсы (directory/secret).
        //
        // Приоритет проверки прав:
        //  1. Явный запрет (is_deny=true) → доступ запрещён
        //  2. Явное разрешение → доступ разрешён
        //  3. Наследованное право от родительского каталога → применяется
        //  4. Нет права → доступ запрещён
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
            // true = явный запрет (overrides allow)
            "is_deny        {$this->boolType(false)}",
            // Временное разрешение: NULL = постоянное
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
