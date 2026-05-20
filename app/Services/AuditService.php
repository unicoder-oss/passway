<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\AuditLog;

final class AuditService
{
    public function __construct(
        private readonly LoggerService $logger,
        private readonly ?OrganizationService $organizationService = null,
    ) {}

    /** @param array<string, mixed> $details */
    public function record(
        string $action,
        ?string $organizationId = null,
        ?string $userId = null,
        ?string $apiKeyId = null,
        ?string $sessionId = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $resourceUuid = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $details = [],
        bool $success = true,
    ): void {
        try {
            Database::getInstance()->insert('audit_log', [
                'organization_id' => $organizationId !== null ? (int) $organizationId : null,
                'user_id'         => $userId !== null ? (int) $userId : null,
                'api_key_id'      => $apiKeyId !== null ? (int) $apiKeyId : null,
                'session_id'      => $sessionId !== null ? (int) $sessionId : null,
                'action'          => $action,
                'resource_type'   => $resourceType,
                'resource_id'     => $resourceId !== null ? (int) $resourceId : null,
                'resource_uuid'   => $resourceUuid,
                'ip_address'      => $ipAddress,
                'user_agent'      => $userAgent !== null ? \substr($userAgent, 0, 512) : null,
                'details_json'    => $details !== [] ? \json_encode($details, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : null,
                'success'         => $success ? 1 : 0,
                'created_at'      => now()->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to write audit log', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return AuditLog[]
     */
    public function listForOrganization(string $orgId, string $userId, array $filters = []): array
    {
        return $this->paginateForOrganization($orgId, $userId, $filters)['entries'];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{entries: AuditLog[], total:int, limit:int, offset:int, has_more:bool}
     */
    public function paginateForOrganization(string $orgId, string $userId, array $filters = []): array
    {
        $this->assertCanView($orgId, $userId);

        $sql = ' FROM audit_log WHERE organization_id = ?';
        $params = [(int) $orgId];

        if (($filters['action'] ?? null) !== null && \trim((string) $filters['action']) !== '') {
            $sql .= ' AND action = ?';
            $params[] = \trim((string) $filters['action']);
        }
        if (($filters['resource_type'] ?? null) !== null && \trim((string) $filters['resource_type']) !== '') {
            $sql .= ' AND resource_type = ?';
            $params[] = \trim((string) $filters['resource_type']);
        }
        if (($filters['resource_uuid'] ?? null) !== null && \trim((string) $filters['resource_uuid']) !== '') {
            $sql .= ' AND resource_uuid = ?';
            $params[] = \trim((string) $filters['resource_uuid']);
        }
        if (($filters['success'] ?? null) !== null && $filters['success'] !== '') {
            $sql .= ' AND success = ?';
            $params[] = (bool) $filters['success'] ? 1 : 0;
        }
        if (($filters['ip_address'] ?? null) !== null && \trim((string) $filters['ip_address']) !== '') {
            $sql .= ' AND ip_address = ?';
            $params[] = \trim((string) $filters['ip_address']);
        }
        if (($filters['user_id'] ?? null) !== null && \trim((string) $filters['user_id']) !== '') {
            $sql .= ' AND user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (($filters['from'] ?? null) !== null && \trim((string) $filters['from']) !== '') {
            $sql .= ' AND created_at >= ?';
            $params[] = \trim((string) $filters['from']);
        }
        if (($filters['to'] ?? null) !== null && \trim((string) $filters['to']) !== '') {
            $sql .= ' AND created_at <= ?';
            $params[] = \trim((string) $filters['to']);
        }
        if (($filters['search'] ?? null) !== null && \trim((string) $filters['search']) !== '') {
            $search = '%' . \trim((string) $filters['search']) . '%';
            $sql .= ' AND (action LIKE ? OR resource_uuid LIKE ? OR ip_address LIKE ? OR user_agent LIKE ? OR details_json LIKE ?)';
            \array_push($params, $search, $search, $search, $search, $search);
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $total = (int) Database::getInstance()->fetchColumn('SELECT COUNT(*)' . $sql, $params);
        $rows = Database::getInstance()->fetchAll(
            'SELECT *' . $sql . ' ORDER BY id DESC LIMIT ? OFFSET ?',
            [...$params, $limit, $offset]
        );

        $entries = \array_map(fn($row) => AuditLog::fromRow($row), $rows);

        return [
            'entries' => $entries,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + \count($entries)) < $total,
        ];
    }

    /** @return array{audit_deleted:int, rate_limit_deleted:int} */
    public function cleanupExpired(): array
    {
        $db = Database::getInstance();
        if (!$db->tableExists('audit_log') || !$db->tableExists('rate_limit_log')) {
            return ['audit_deleted' => 0, 'rate_limit_deleted' => 0];
        }

        $retentionDays = max(1, (int) ($_ENV['LOG_RETENTION_DAYS'] ?? 90));
        $cutoff = now()->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s');
        $rateLimitCutoff = now()->modify('-1 day')->format('Y-m-d H:i:s');

        $auditStmt = $db->query('DELETE FROM audit_log WHERE created_at < ?', [$cutoff]);
        $rateStmt = $db->query('DELETE FROM rate_limit_log WHERE updated_at < ?', [$rateLimitCutoff]);

        return [
            'audit_deleted' => $auditStmt->rowCount(),
            'rate_limit_deleted' => $rateStmt->rowCount(),
        ];
    }

    private function assertCanView(string $orgId, string $userId): void
    {
        if ($this->organizationService === null || !$this->organizationService->hasPermission($orgId, $userId, 'admin')) {
            throw new AuthException(__('ui.backend.audit.requires_admin_view'), 403);
        }
    }
}
