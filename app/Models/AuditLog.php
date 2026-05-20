<?php

declare(strict_types=1);

namespace Passway\Models;

final class AuditLog
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $organizationId,
        public readonly ?string $userId,
        public readonly ?string $apiKeyId,
        public readonly ?string $sessionId,
        public readonly string $action,
        public readonly ?string $resourceType,
        public readonly ?string $resourceId,
        public readonly ?string $resourceUuid,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly ?string $detailsJson,
        public readonly bool $success,
        public readonly string $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (string) $row['id'],
            organizationId: $row['organization_id'] !== null ? (string) $row['organization_id'] : null,
            userId:         $row['user_id'] !== null ? (string) $row['user_id'] : null,
            apiKeyId:       $row['api_key_id'] !== null ? (string) $row['api_key_id'] : null,
            sessionId:      $row['session_id'] !== null ? (string) $row['session_id'] : null,
            action:         (string) $row['action'],
            resourceType:   $row['resource_type'] !== null ? (string) $row['resource_type'] : null,
            resourceId:     $row['resource_id'] !== null ? (string) $row['resource_id'] : null,
            resourceUuid:   $row['resource_uuid'] !== null ? (string) $row['resource_uuid'] : null,
            ipAddress:      $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
            userAgent:      $row['user_agent'] !== null ? (string) $row['user_agent'] : null,
            detailsJson:    $row['details_json'] !== null ? (string) $row['details_json'] : null,
            success:        (bool) $row['success'],
            createdAt:      (string) $row['created_at'],
        );
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        if ($this->detailsJson === null || $this->detailsJson === '') {
            return [];
        }

        $decoded = \json_decode($this->detailsJson, true);
        return \is_array($decoded) ? $decoded : [];
    }
}
