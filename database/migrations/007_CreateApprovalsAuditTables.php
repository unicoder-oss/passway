<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Миграция 007: Система одобрений и журнал аудита.
 *
 * Таблицы:
 *   - approval_requests   — запросы на доступ к секретам с флагом requires_approval
 *   - approval_reviewers  — уполномоченные ревьюверы запроса
 *   - audit_log           — полный журнал всех операций (хранение ≥ 90 дней)
 *
 * Rate limiting:
 *   - rate_limit_log      — счётчики запросов для rate limiting (очищается cron)
 */
final class CreateApprovalsAuditTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  approval_requests                                                   //
        // ------------------------------------------------------------------ //
        // Создаётся когда пользователь обращается к секрету с requires_approval=true.
        // После одобрения генерируется одноразовый токен (действует expires_at).
        $this->createTable('approval_requests', [
            "id              {$this->pkType()}",
            'uuid            VARCHAR(36) NOT NULL',
            'secret_id       BIGINT NOT NULL',
            'requested_by    BIGINT NOT NULL',
            // read | write | delete
            'request_type    VARCHAR(50) NOT NULL',
            'reason          TEXT',
            // pending | approved | rejected | expired | revoked
            "status          VARCHAR(50) NOT NULL DEFAULT 'pending'",
            'approved_by     BIGINT',
            'rejection_reason TEXT',
            // Срок действия одобренного доступа
            "expires_at      {$this->tsType()} NOT NULL",
            // Одноразовый токен доступа (показывается ОДИН раз после одобрения)
            // В БД хранится SHA-256 хэш
            'access_token_hash VARCHAR(64)',
            "created_at      {$this->nowDefault()}",
            "resolved_at     {$this->tsType()}",
            $this->foreignKey('secret_id', 'secrets', 'id', 'CASCADE'),
            $this->foreignKey('requested_by', 'users', 'id', 'CASCADE'),
            $this->foreignKey('approved_by', 'users', 'id', 'SET NULL'),
        ], [
            'UNIQUE (uuid)',
        ]);

        $this->createIndex('approval_requests', ['secret_id']);
        $this->createIndex('approval_requests', ['requested_by']);
        $this->createIndex('approval_requests', ['status']);
        $this->createIndex('approval_requests', ['expires_at']);

        // ------------------------------------------------------------------ //
        //  approval_reviewers                                                  //
        // ------------------------------------------------------------------ //
        // Список пользователей, уполномоченных одобрять/отклонять конкретный запрос.
        // Заполняется автоматически при создании запроса на основе прав.
        $this->createTable('approval_reviewers', [
            "id                    {$this->pkType()}",
            'approval_request_id   BIGINT NOT NULL',
            'reviewer_id           BIGINT NOT NULL',
            // Когда было отправлено уведомление
            "notified_at           {$this->tsType()}",
            "created_at            {$this->nowDefault()}",
            $this->foreignKey('approval_request_id', 'approval_requests', 'id', 'CASCADE'),
            $this->foreignKey('reviewer_id', 'users', 'id', 'CASCADE'),
        ], [
            'UNIQUE (approval_request_id, reviewer_id)',
        ]);

        $this->createIndex('approval_reviewers', ['approval_request_id']);
        $this->createIndex('approval_reviewers', ['reviewer_id']);

        // ------------------------------------------------------------------ //
        //  audit_log                                                           //
        // ------------------------------------------------------------------ //
        // Иммутабельный журнал: записи НИКОГДА не изменяются.
        // Удаляются только через cron после истечения срока хранения (90 дней).
        //
        // Категории событий (action):
        //   auth.*        — аутентификация (login, logout, fail, 2fa, passkey)
        //   user.*        — управление пользователями
        //   org.*         — управление организациями
        //   invite.*      — инвайт-ссылки
        //   dir.*         — операции с каталогами
        //   secret.*      — доступ к секретам (read, write, delete, rotate)
        //   approval.*    — система одобрений
        //   apikey.*      — управление API-ключами
        //   rotation.*    — ротация секретов
        //   permission.*  — изменение прав доступа
        //   system.*      — системные события
        $this->createTable('audit_log', [
            "id              {$this->bigPkType()}",
            'organization_id BIGINT',
            'user_id         BIGINT',
            'api_key_id      BIGINT',
            'session_id      BIGINT',
            // Категория и действие (например: "secret.read", "auth.login_fail")
            'action          VARCHAR(100) NOT NULL',
            // directory | secret | user | organization | api_key | system
            'resource_type   VARCHAR(50)',
            'resource_id     BIGINT',
            'resource_uuid   VARCHAR(36)',
            'ip_address      VARCHAR(45)',
            'user_agent      TEXT',
            // JSON с дополнительными деталями события (не содержит секретных данных)
            'details_json    TEXT',
            "success         {$this->boolType(true)}",
            "created_at      {$this->nowDefault()}",
            // FK без CASCADE — лог должен сохраняться даже после удаления пользователя/орг
            $this->foreignKey('organization_id', 'organizations', 'id', 'SET NULL'),
            $this->foreignKey('user_id', 'users', 'id', 'SET NULL'),
            $this->foreignKey('api_key_id', 'api_keys', 'id', 'SET NULL'),
        ]);

        // Индексы для эффективной фильтрации в журнале
        $this->createIndex('audit_log', ['organization_id']);
        $this->createIndex('audit_log', ['user_id']);
        $this->createIndex('audit_log', ['action']);
        $this->createIndex('audit_log', ['resource_type', 'resource_id']);
        $this->createIndex('audit_log', ['created_at']);
        $this->createIndex('audit_log', ['ip_address']);
        $this->createIndex('audit_log', ['success']);

        // ------------------------------------------------------------------ //
        //  rate_limit_log                                                      //
        // ------------------------------------------------------------------ //
        // Скользящее окно для rate limiting (100 req/min API, 20 req/min auth).
        // Записи автоматически очищаются cron-задачей каждую минуту.
        $this->createTable('rate_limit_log', [
            "id          {$this->pkType()}",
            // IP-адрес клиента
            'ip_address  VARCHAR(45) NOT NULL',
            // api | auth
            'bucket      VARCHAR(20) NOT NULL',
            // Количество запросов в текущем окне
            'count       INTEGER NOT NULL DEFAULT 1',
            // Начало текущего окна (для скользящего окна)
            "window_start {$this->tsType()} NOT NULL",
            "updated_at   {$this->nowDefault()}",
        ], [
            'UNIQUE (ip_address, bucket)',
        ]);

        $this->createIndex('rate_limit_log', ['ip_address', 'bucket']);
        $this->createIndex('rate_limit_log', ['window_start']);
    }

    public function down(): void
    {
        $this->dropTable('rate_limit_log');
        $this->dropTable('audit_log');
        $this->dropTable('approval_reviewers');
        $this->dropTable('approval_requests');
    }
}
