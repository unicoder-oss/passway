<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель группы пользователей внутри организации.
 */
final class Group
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $organizationId,
        public readonly string  $name,
        public readonly ?string $description,
        public readonly ?string $createdBy,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Фабрика                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (string) $row['id'],
            uuid:           (string) $row['uuid'],
            organizationId: (string) $row['organization_id'],
            name:           (string) $row['name'],
            description:    isset($row['description']) && $row['description'] !== null
                ? (string) $row['description'] : null,
            createdBy:      isset($row['created_by']) && $row['created_by'] !== null
                ? (string) $row['created_by'] : null,
            createdAt:      (string) $row['created_at'],
            updatedAt:      (string) $row['updated_at'],
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM groups WHERE id = ?',
            [(int) $id]
        );
        return $row ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM groups WHERE uuid = ?',
            [$uuid]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Все группы организации (по алфавиту).
     *
     * @return self[]
     */
    public static function findByOrgId(string $orgId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM groups WHERE organization_id = ? ORDER BY name',
            [(int) $orgId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }
}
