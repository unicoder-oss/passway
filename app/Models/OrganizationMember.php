<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель участника организации.
 *
 * Роли (иерархия убывает): owner | admin | editor | reader
 */
final class OrganizationMember
{
    /** Допустимые роли в порядке убывания привилегий */
    public const ROLES = ['owner', 'admin', 'editor', 'reader'];

    public function __construct(
        public readonly string  $id,
        public readonly string  $organizationId,
        public readonly string  $userId,
        public readonly string  $role,
        public readonly ?string $invitedBy,
        public readonly string  $joinedAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Фабрика                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (string) $row['id'],
            organizationId: (string) $row['organization_id'],
            userId:         (string) $row['user_id'],
            role:           (string) $row['role'],
            invitedBy:      isset($row['invited_by']) && $row['invited_by'] !== null
                ? (string) $row['invited_by'] : null,
            joinedAt:       (string) $row['joined_at'],
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    public static function findByOrgAndUser(string $orgId, string $userId): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM organization_members WHERE organization_id = ? AND user_id = ?',
            [(int) $orgId, (int) $userId]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Все участники организации.
     *
     * @return self[]
     */
    public static function findByOrgId(string $orgId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM organization_members WHERE organization_id = ? ORDER BY joined_at',
            [(int) $orgId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Все организации пользователя (memberships).
     *
     * @return self[]
     */
    public static function findByUserId(string $userId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM organization_members WHERE user_id = ?',
            [(int) $userId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    // ------------------------------------------------------------------ //
    //  Вспомогательные                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Проверить, достаточно ли привилегий (role >= minRole в иерархии).
     */
    public static function roleIndex(string $role): int
    {
        $idx = \array_search($role, self::ROLES, true);
        return $idx === false ? \PHP_INT_MAX : (int) $idx;
    }

    public static function roleHasPermission(string $role, string $minRole): bool
    {
        return self::roleIndex($role) <= self::roleIndex($minRole);
    }
}
