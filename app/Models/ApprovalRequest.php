<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель запроса на одобрение доступа к секрету.
 *
 * Структура таблицы approval_requests:
 *   - id, uuid, secret_id, requested_by
 *   - request_type (read|write|delete)
 *   - reason (текст причины запроса)
 *   - status (pending|approved|rejected|expired|revoked)
 *   - approved_by, rejection_reason
 *   - expires_at — дедлайн для ответа ревьювера; после одобрения — TTL токена
 *   - access_token_hash — SHA-256 хэш одноразового токена (NULL после использования)
 *   - created_at, resolved_at
 */
final class ApprovalRequest
{
    public const VALID_REQUEST_TYPES = ['read', 'write', 'delete'];
    public const VALID_STATUSES      = ['pending', 'approved', 'rejected', 'expired', 'revoked'];

    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $secretId,
        public readonly string  $requestedBy,
        public readonly string  $requestType,
        public readonly ?string $reason,
        public readonly string  $status,
        public readonly ?string $approvedBy,
        public readonly ?string $rejectionReason,
        public readonly string  $expiresAt,
        public readonly ?string $accessTokenHash,
        public readonly string  $createdAt,
        public readonly ?string $resolvedAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Фабрика                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:              (string) $row['id'],
            uuid:            (string) $row['uuid'],
            secretId:        (string) $row['secret_id'],
            requestedBy:     (string) $row['requested_by'],
            requestType:     (string) $row['request_type'],
            reason:          isset($row['reason']) && $row['reason'] !== null
                ? (string) $row['reason'] : null,
            status:          (string) $row['status'],
            approvedBy:      isset($row['approved_by']) && $row['approved_by'] !== null
                ? (string) $row['approved_by'] : null,
            rejectionReason: isset($row['rejection_reason']) && $row['rejection_reason'] !== null
                ? (string) $row['rejection_reason'] : null,
            expiresAt:       (string) $row['expires_at'],
            accessTokenHash: isset($row['access_token_hash']) && $row['access_token_hash'] !== null
                ? (string) $row['access_token_hash'] : null,
            createdAt:       (string) $row['created_at'],
            resolvedAt:      isset($row['resolved_at']) && $row['resolved_at'] !== null
                ? (string) $row['resolved_at'] : null,
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM approval_requests WHERE id = ?',
            [(int) $id]
        );
        return $row ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM approval_requests WHERE uuid = ?',
            [$uuid]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Все запросы, созданные пользователем (новейшие первыми).
     *
     * @return self[]
     */
    public static function findByRequesterId(string $userId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM approval_requests WHERE requested_by = ? ORDER BY created_at DESC',
            [(int) $userId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Ожидающие одобрения запросы, где пользователь является ревьювером.
     *
     * @return self[]
     */
    public static function findPendingForReviewer(string $reviewerId): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT ar.* FROM approval_requests ar
             JOIN approval_reviewers rv ON rv.approval_request_id = ar.id
             WHERE rv.reviewer_id = ? AND ar.status = 'pending'
             ORDER BY ar.created_at ASC",
            [(int) $reviewerId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Проверить, есть ли pending-запрос от пользователя для данного секрета и типа.
     */
    public static function hasPending(string $secretId, string $userId, string $requestType): bool
    {
        $count = (int) Database::getInstance()->fetchColumn(
            "SELECT COUNT(*) FROM approval_requests
             WHERE secret_id = ? AND requested_by = ? AND request_type = ? AND status = 'pending'",
            [(int) $secretId, (int) $userId, $requestType]
        );
        return $count > 0;
    }

    // ------------------------------------------------------------------ //
    //  Запись                                                             //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $data */
    public function update(array $data): void
    {
        Database::getInstance()->update('approval_requests', $data, ['id' => $this->id]);
    }
}
