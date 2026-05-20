<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Thin model organization.
 */
final class Organization
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $name,
        public readonly ?string $description,
        public readonly ?string $avatarPath,
        public readonly string  $slug,
        public readonly string  $ownerId,
        public readonly bool    $isActive,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly ?string $deletedAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Factory                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:        (string) $row['id'],
            uuid:      (string) $row['uuid'],
            name:      (string) $row['name'],
            description: isset($row['description']) && $row['description'] !== null
                ? (string) $row['description'] : null,
            avatarPath: isset($row['avatar_path']) && $row['avatar_path'] !== null
                ? (string) $row['avatar_path'] : null,
            slug:      (string) $row['slug'],
            ownerId:   (string) $row['owner_id'],
            isActive:  (bool) ($row['is_active'] ?? true),
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            deletedAt: isset($row['deleted_at']) && $row['deleted_at'] !== null
                ? (string) $row['deleted_at'] : null,
        );
    }

    // ------------------------------------------------------------------ //
    //  Queries                                                            //
    // ------------------------------------------------------------------ //

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM organizations WHERE id = ? AND deleted_at IS NULL',
            [(int) $id]
        );
        return $row ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM organizations WHERE uuid = ? AND deleted_at IS NULL',
            [$uuid]
        );
        return $row ? self::fromRow($row) : null;
    }

    public static function findBySlug(string $slug): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM organizations WHERE slug = ? AND deleted_at IS NULL',
            [$slug]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * All (non-deleted) user organizations through organization_members.
     *
     * @return self[]
     */
    public static function findByUserId(string $userId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT o.* FROM organizations o
             JOIN organization_members m ON m.organization_id = o.id
             WHERE m.user_id = ? AND o.deleted_at IS NULL
             ORDER BY o.name',
            [(int) $userId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /** Number of active organizations (for solo mode). */
    public static function count(): int
    {
        return (int) Database::getInstance()->fetchColumn(
            'SELECT COUNT(*) FROM organizations WHERE deleted_at IS NULL'
        );
    }

    // ------------------------------------------------------------------ //
    //  Writes                                                             //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $data */
    public function update(array $data): void
    {
        Database::getInstance()->update('organizations', $data, ['id' => $this->id]);
    }
}
