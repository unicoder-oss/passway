<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

/**
 * Migration 007: Approval system and audit log.
 *
 * Tables:
 *   - approval_requests   — access requests for secrets with the requires_approval flag
 *   - approval_reviewers  — authorized reviewers for the request
 *   - audit_log           — complete log of all operations (retention >= 90 days)
 *
 * Rate limiting:
 *   - rate_limit_log      — request counters for rate limiting (cleaned by cron)
 */
final class CreateApprovalsAuditTables extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------ //
        //  approval_requests                                                   //
        // ------------------------------------------------------------------ //
        // Created when a user accesses a secret with requires_approval=true.
        // After approval, a one-time token is generated (valid until expires_at).
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
            // Approved access expiration time
            "expires_at      {$this->tsType()} NOT NULL",
            // One-time access token (shown ONCE after approval)
            // The DB stores the SHA-256 hash
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
        // List of users authorized to approve/reject a specific request.
        // Populated automatically when the request is created based on permissions.
        $this->createTable('approval_reviewers', [
            "id                    {$this->pkType()}",
            'approval_request_id   BIGINT NOT NULL',
            'reviewer_id           BIGINT NOT NULL',
            // When the notification was sent
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
        // Immutable log: records are NEVER changed.
        // Deleted only via cron after the retention period expires (90 days).
        //
        // Event categories (action):
        //   auth.*        — authentication (login, logout, fail, 2fa, passkey)
        //   user.*        — user management
        //   org.*         — organization management
        //   invite.*      — invite links
        //   dir.*         — directory operations
        //   secret.*      — secret access (read, write, delete, rotate)
        //   approval.*    — approval system
        //   apikey.*      — API key management
        //   rotation.*    — secret rotation
        //   permission.*  — access permission changes
        //   system.*      — system events
        $this->createTable('audit_log', [
            "id              {$this->bigPkType()}",
            'organization_id BIGINT',
            'user_id         BIGINT',
            'api_key_id      BIGINT',
            'session_id      BIGINT',
            // Category and action (for example: "secret.read", "auth.login_fail")
            'action          VARCHAR(100) NOT NULL',
            // directory | secret | user | organization | api_key | system
            'resource_type   VARCHAR(50)',
            'resource_id     BIGINT',
            'resource_uuid   VARCHAR(36)',
            'ip_address      VARCHAR(45)',
            'user_agent      TEXT',
            // JSON with additional event details (contains no secret data)
            'details_json    TEXT',
            "success         {$this->boolType(true)}",
            "created_at      {$this->nowDefault()}",
            // FK without CASCADE — the log must persist even after user/org deletion
            $this->foreignKey('organization_id', 'organizations', 'id', 'SET NULL'),
            $this->foreignKey('user_id', 'users', 'id', 'SET NULL'),
            $this->foreignKey('api_key_id', 'api_keys', 'id', 'SET NULL'),
        ]);

        // Indexes for efficient log filtering
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
        // Sliding window for rate limiting (100 req/min API, 20 req/min auth).
        // Records are automatically cleaned by a cron job every minute.
        $this->createTable('rate_limit_log', [
            "id          {$this->pkType()}",
            // Client IP address
            'ip_address  VARCHAR(45) NOT NULL',
            // api | auth
            'bucket      VARCHAR(20) NOT NULL',
            // Number of requests in the current window
            'count       INTEGER NOT NULL DEFAULT 1',
            // Start of the current window (for the sliding window)
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
