<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Thin model for a secret access approval request.
 *
 * Table structure approval_requests:
 *   - id, uuid, secret_id, requested_by
 *   - request_type (read|write|delete)
 *   - reason (request reason text)
 *   - status (pending|approved|rejected|expired|revoked)
 *   - approved_by, rejection_reason
 *   - expires_at - deadline for reviewer response; after approval - token TTL
 *   - access_token_hash - SHA-256 hash of the one-time token (NULL after use)
 *   - created_at, resolved_at
 */
final class ApprovalRequest
{
    public const REQUESTER_TYPE_USER = 'user';
    public const REQUESTER_TYPE_API_KEY = 'api_key';
    public const VALID_REQUESTER_TYPES = [self::REQUESTER_TYPE_USER, self::REQUESTER_TYPE_API_KEY];
    public const VALID_REQUEST_TYPES = ['read', 'write', 'delete'];
    public const VALID_STATUSES      = ['pending', 'approved', 'rejected', 'expired', 'revoked'];

    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $secretId,
        public readonly ?string $requestedBy,
        public readonly string  $requesterType,
        public readonly string  $requesterId,
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
    //  Factory                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:              (string) $row['id'],
            uuid:            (string) $row['uuid'],
            secretId:        (string) $row['secret_id'],
            requestedBy:     isset($row['requested_by']) && $row['requested_by'] !== null
                ? (string) $row['requested_by'] : null,
            requesterType:   isset($row['requester_type']) && $row['requester_type'] !== null && (string) $row['requester_type'] !== ''
                ? (string) $row['requester_type'] : self::REQUESTER_TYPE_USER,
            requesterId:     isset($row['requester_id']) && $row['requester_id'] !== null
                ? (string) $row['requester_id']
                : (string) $row['requested_by'],
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
    //  Queries                                                            //
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
     * All requests created by the user (newest first).
     *
     * @return self[]
     */
    public static function findByRequesterId(string $userId): array
    {
        return self::findByActor(self::REQUESTER_TYPE_USER, $userId);
    }

    /**
     * @return self[]
     */
    public static function findByActor(string $requesterType, string $requesterId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM approval_requests WHERE requester_type = ? AND requester_id = ? ORDER BY created_at DESC',
            [$requesterType, (int) $requesterId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Pending approval requests, where the user is a reviewer.
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
     * Check whether the user has a pending request for this secret and type.
     */
    public static function hasPending(string $secretId, string $userId, string $requestType): bool
    {
        return self::hasPendingForActor($secretId, self::REQUESTER_TYPE_USER, $userId, $requestType);
    }

    public static function hasPendingForActor(string $secretId, string $requesterType, string $requesterId, string $requestType): bool
    {
        $count = (int) Database::getInstance()->fetchColumn(
            "SELECT COUNT(*) FROM approval_requests
             WHERE secret_id = ? AND requester_type = ? AND requester_id = ? AND request_type = ? AND status = 'pending'",
            [(int) $secretId, $requesterType, (int) $requesterId, $requestType]
        );
        return $count > 0;
    }

    public static function findPendingForActor(string $secretId, string $requesterType, string $requesterId, string $requestType = 'read'): ?self
    {
        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM approval_requests
             WHERE secret_id = ? AND requester_type = ? AND requester_id = ? AND request_type = ? AND status = 'pending'
             ORDER BY created_at DESC LIMIT 1",
            [(int) $secretId, $requesterType, (int) $requesterId, $requestType]
        );

        return $row ? self::fromRow($row) : null;
    }

    public function isUserRequester(): bool
    {
        return $this->requesterType === self::REQUESTER_TYPE_USER;
    }

    public function isApiKeyRequester(): bool
    {
        return $this->requesterType === self::REQUESTER_TYPE_API_KEY;
    }

    // ------------------------------------------------------------------ //
    //  Writes                                                             //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $data */
    public function update(array $data): void
    {
        Database::getInstance()->update('approval_requests', $data, ['id' => $this->id]);
    }
}
