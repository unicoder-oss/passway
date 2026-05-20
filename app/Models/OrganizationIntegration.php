<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Конфигурация подключения сервиса ротации к организации.
 */
final class OrganizationIntegration
{
    public function __construct(
        public readonly string $id,
        public readonly string $uuid,
        public readonly string $organizationId,
        public readonly string $rotationServiceId,
        public readonly string $name,
        public readonly ?string $encryptedCredentials,
        public readonly ?string $credentialsNonce,
        public readonly bool $isActive,
        public readonly ?string $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                   (string) $row['id'],
            uuid:                 (string) $row['uuid'],
            organizationId:       (string) $row['organization_id'],
            rotationServiceId:    (string) $row['rotation_service_id'],
            name:                 (string) $row['name'],
            encryptedCredentials: $row['encrypted_credentials'] !== null ? (string) $row['encrypted_credentials'] : null,
            credentialsNonce:     $row['credentials_nonce'] !== null ? (string) $row['credentials_nonce'] : null,
            isActive:             (bool) $row['is_active'],
            createdBy:            $row['created_by'] !== null ? (string) $row['created_by'] : null,
            createdAt:            (string) $row['created_at'],
            updatedAt:            (string) $row['updated_at'],
        );
    }

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM organization_integrations WHERE id = ?',
            [(int) $id]
        );

        return $row !== null ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM organization_integrations WHERE uuid = ?',
            [$uuid]
        );

        return $row !== null ? self::fromRow($row) : null;
    }

    /** @return self[] */
    public static function findByOrgId(string $orgId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM organization_integrations WHERE organization_id = ? ORDER BY name ASC',
            [(int) $orgId]
        );

        return \array_map(fn($row) => self::fromRow($row), $rows);
    }
}
