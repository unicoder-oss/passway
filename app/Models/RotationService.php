<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Зарегистрированный внешний сервис ротации.
 */
final class RotationService
{
    public function __construct(
        public readonly string $id,
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $healthUrl,
        public readonly ?string $specJson,
        public readonly bool $isActive,
        public readonly bool $isVerified,
        public readonly ?string $lastCheckAt,
        public readonly ?string $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:          (string) $row['id'],
            uuid:        (string) $row['uuid'],
            name:        (string) $row['name'],
            url:         (string) $row['url'],
            healthUrl:   $row['health_url'] !== null ? (string) $row['health_url'] : null,
            specJson:    $row['spec_json'] !== null ? (string) $row['spec_json'] : null,
            isActive:    (bool) $row['is_active'],
            isVerified:  (bool) $row['is_verified'],
            lastCheckAt: $row['last_check_at'] !== null ? (string) $row['last_check_at'] : null,
            createdBy:   $row['created_by'] !== null ? (string) $row['created_by'] : null,
            createdAt:   (string) $row['created_at'],
            updatedAt:   (string) $row['updated_at'],
        );
    }

    /** @return array<string, mixed> */
    public function spec(): array
    {
        if ($this->specJson === null || $this->specJson === '') {
            return [];
        }

        $decoded = \json_decode($this->specJson, true);
        return \is_array($decoded) ? $decoded : [];
    }

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM rotation_services WHERE id = ?',
            [(int) $id]
        );

        return $row !== null ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM rotation_services WHERE uuid = ?',
            [$uuid]
        );

        return $row !== null ? self::fromRow($row) : null;
    }

    /** @return self[] */
    public static function findAllActive(): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM rotation_services WHERE is_active = 1 ORDER BY name ASC'
        );

        return \array_map(fn($row) => self::fromRow($row), $rows);
    }
}
