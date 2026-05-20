<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель участника группы.
 */
final class GroupMember
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $groupId,
        public readonly string  $userId,
        public readonly ?string $addedBy,
        public readonly string  $addedAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Фабрика                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:      (string) $row['id'],
            groupId: (string) $row['group_id'],
            userId:  (string) $row['user_id'],
            addedBy: isset($row['added_by']) && $row['added_by'] !== null
                ? (string) $row['added_by'] : null,
            addedAt: (string) $row['added_at'],
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    public static function findByGroupAndUser(string $groupId, string $userId): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM group_members WHERE group_id = ? AND user_id = ?',
            [(int) $groupId, (int) $userId]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Все участники группы.
     *
     * @return self[]
     */
    public static function findByGroupId(string $groupId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM group_members WHERE group_id = ? ORDER BY added_at',
            [(int) $groupId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Все группы, в которых состоит пользователь.
     *
     * @return self[]
     */
    public static function findByUserId(string $userId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM group_members WHERE user_id = ?',
            [(int) $userId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Получить ID всех групп пользователя в рамках организации.
     *
     * @return string[]
     */
    public static function getGroupIdsForUserInOrg(string $userId, string $orgId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT gm.group_id FROM group_members gm
             JOIN groups g ON g.id = gm.group_id
             WHERE gm.user_id = ? AND g.organization_id = ?',
            [(int) $userId, (int) $orgId]
        );
        return \array_map(fn($r) => (string) $r['group_id'], $rows);
    }
}
