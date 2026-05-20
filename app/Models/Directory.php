<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель каталога (директории).
 *
 * Структура таблицы directories:
 *   - id, uuid, organization_id, parent_id (nullable)
 *   - name, depth (0 = корень), path (materialized path: /uuid/.../uuid)
 *   - created_by, owner_user_id, created_at, updated_at, deleted_at (soft delete)
 */
final class Directory
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $organizationId,
        public readonly ?string $parentId,
        public readonly string  $name,
        public readonly int     $depth,
        public readonly string  $path,
        public readonly ?string $createdBy,
        public readonly ?string $ownerUserId,
        public readonly string  $defaultReadAccess,
        public readonly string  $defaultWriteAccess,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly ?string $deletedAt,
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
            parentId:       isset($row['parent_id']) && $row['parent_id'] !== null
                ? (string) $row['parent_id'] : null,
            name:           (string) $row['name'],
            depth:          (int) $row['depth'],
            path:           (string) $row['path'],
            createdBy:      isset($row['created_by']) && $row['created_by'] !== null
                ? (string) $row['created_by'] : null,
            ownerUserId:    isset($row['owner_user_id']) && $row['owner_user_id'] !== null
                ? (string) $row['owner_user_id'] : null,
            defaultReadAccess: isset($row['default_read_access']) && $row['default_read_access'] !== null
                ? (string) $row['default_read_access'] : 'inherit',
            defaultWriteAccess: isset($row['default_write_access']) && $row['default_write_access'] !== null
                ? (string) $row['default_write_access'] : 'inherit',
            createdAt:      (string) $row['created_at'],
            updatedAt:      (string) $row['updated_at'],
            deletedAt:      isset($row['deleted_at']) && $row['deleted_at'] !== null
                ? (string) $row['deleted_at'] : null,
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM directories WHERE id = ? AND deleted_at IS NULL',
            [(int) $id]
        );
        return $row ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM directories WHERE uuid = ? AND deleted_at IS NULL',
            [$uuid]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Все не удалённые каталоги организации (порядок: глубина, путь).
     *
     * @return self[]
     */
    public static function findByOrgId(string $orgId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM directories WHERE organization_id = ? AND deleted_at IS NULL ORDER BY depth, path',
            [(int) $orgId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Прямые дочерние каталоги в рамках организации.
     * Если parentId = null — возвращает корневые каталоги.
     *
     * @return self[]
     */
    public static function findChildren(string $orgId, ?string $parentId): array
    {
        if ($parentId === null) {
            $rows = Database::getInstance()->fetchAll(
                'SELECT * FROM directories
                 WHERE organization_id = ? AND parent_id IS NULL AND deleted_at IS NULL
                 ORDER BY name',
                [(int) $orgId]
            );
        } else {
            $rows = Database::getInstance()->fetchAll(
                'SELECT * FROM directories
                 WHERE organization_id = ? AND parent_id = ? AND deleted_at IS NULL
                 ORDER BY name',
                [(int) $orgId, (int) $parentId]
            );
        }
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Все не удалённые потомки по materialized path (path LIKE '{$path}/%').
     *
     * @return self[]
     */
    public static function findDescendants(string $path): array
    {
        $rows = Database::getInstance()->fetchAll(
            "SELECT * FROM directories WHERE path LIKE ? AND deleted_at IS NULL ORDER BY depth, path",
            [$path . '/%']
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    // ------------------------------------------------------------------ //
    //  Запись                                                             //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $data */
    public function update(array $data): void
    {
        Database::getInstance()->update('directories', $data, ['id' => $this->id]);
    }
}
