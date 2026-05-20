<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель гранулярного права субъекта (user/group/api_key) на ресурс (directory/secret).
 *
 * Приоритет проверки (см. PermissionService):
 *   1. Явный запрет (is_deny=true) — запрещает доступ
 *   2. Явное разрешение (is_deny=false) — разрешает доступ
 *   3. Наследование от родительского каталога
 *   4. Нет записи → доступ запрещён
 */
final class UserPermission
{
    public const VALID_SUBJECT_TYPES  = ['user', 'group', 'api_key'];
    public const VALID_RESOURCE_TYPES = ['directory', 'secret'];
    public const VALID_PERMISSIONS    = ['read', 'write', 'delete', 'create_subdirectories'];

    public function __construct(
        public readonly string  $id,
        public readonly string  $subjectType,
        public readonly string  $subjectId,
        public readonly string  $resourceType,
        public readonly string  $resourceId,
        public readonly string  $permission,
        public readonly bool    $isDeny,
        public readonly ?string $expiresAt,
        public readonly ?string $grantedBy,
        public readonly string  $createdAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Фабрика                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:           (string) $row['id'],
            subjectType:  (string) $row['subject_type'],
            subjectId:    (string) $row['subject_id'],
            resourceType: (string) $row['resource_type'],
            resourceId:   (string) $row['resource_id'],
            permission:   (string) $row['permission'],
            isDeny:       (bool)   $row['is_deny'],
            expiresAt:    isset($row['expires_at']) && $row['expires_at'] !== null
                ? (string) $row['expires_at'] : null,
            grantedBy:    isset($row['granted_by']) && $row['granted_by'] !== null
                ? (string) $row['granted_by'] : null,
            createdAt:    (string) $row['created_at'],
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM user_permissions WHERE id = ?',
            [(int) $id]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Права конкретного субъекта на конкретный ресурс.
     *
     * @return self[]
     */
    public static function findForSubject(
        string $subjectType,
        string $subjectId,
        string $resourceType,
        string $resourceId,
    ): array {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM user_permissions
             WHERE subject_type = ? AND subject_id = ?
               AND resource_type = ? AND resource_id = ?',
            [$subjectType, (int) $subjectId, $resourceType, (int) $resourceId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Все права на указанный ресурс (для отображения/управления).
     *
     * @return self[]
     */
    public static function findForResource(string $resourceType, string $resourceId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM user_permissions
             WHERE resource_type = ? AND resource_id = ?
             ORDER BY subject_type, subject_id',
            [$resourceType, (int) $resourceId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }
}
