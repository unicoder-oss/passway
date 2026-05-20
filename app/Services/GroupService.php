<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Group;
use Passway\Models\GroupMember;
use Passway\Models\OrganizationMember;

/**
 * Сервис управления группами пользователей внутри организации.
 *
 * Авторизация:
 *   admin+    — создание/удаление групп, управление участниками
 *   observer+ — чтение (list, show, listMembers)
 */
final class GroupService
{
    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly ?AuditService       $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Создание                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Создать группу в организации.
     *
     * @throws AuthException             если нет прав (admin+)
     * @throws \InvalidArgumentException при пустом/слишком длинном имени
     * @throws \RuntimeException         если имя уже занято в организации
     */
    public function create(
        string  $orgId,
        string  $name,
        ?string $description,
        string  $userId,
    ): Group {
        $this->assertHasPermission($orgId, $userId, 'admin');

        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Group name cannot be empty.');
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException('Group name is too long (max 255 characters).');
        }

        $count = (int) Database::getInstance()->fetchColumn(
            'SELECT COUNT(*) FROM groups WHERE organization_id = ? AND name = ?',
            [(int) $orgId, $name]
        );
        if ($count > 0) {
            throw new \RuntimeException('A group with this name already exists in the organization.');
        }

        $uuid = generate_uuid();
        $now  = now()->format('Y-m-d H:i:s');

        Database::getInstance()->insert('groups', [
            'uuid'            => $uuid,
            'organization_id' => (int) $orgId,
            'name'            => $name,
            'description'     => $description,
            'created_by'      => (int) $userId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $group = Group::findByUuid($uuid)
            ?? throw new \RuntimeException('Failed to load created group.');

        $this->getAuditService()->record(
            action: 'group.create',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'group',
            resourceId: $group->id,
            resourceUuid: $group->uuid,
        );

        return $group;
    }

    // ------------------------------------------------------------------ //
    //  Чтение                                                             //
    // ------------------------------------------------------------------ //

    /**
     * @return Group[]
     * @throws AuthException если нет прав (observer+)
     */
    public function list(string $orgId, string $userId): array
    {
        $this->assertHasPermission($orgId, $userId, 'observer');
        return Group::findByOrgId($orgId);
    }

    /**
     * @throws AuthException     если нет прав (observer+)
     * @throws \RuntimeException если не найдена или принадлежит другой орг.
     */
    public function findInOrg(string $groupUuid, string $orgId, string $userId): Group
    {
        $this->assertHasPermission($orgId, $userId, 'observer');
        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException('Group not found.');
        }
        return $group;
    }

    // ------------------------------------------------------------------ //
    //  Удаление                                                           //
    // ------------------------------------------------------------------ //

    /**
     * @throws AuthException     если нет прав (admin+)
     * @throws \RuntimeException если не найдена
     */
    public function delete(string $groupUuid, string $orgId, string $userId): void
    {
        $this->assertHasPermission($orgId, $userId, 'admin');
        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException('Group not found.');
        }
        Database::getInstance()->delete('groups', ['id' => (int) $group->id]);

        $this->getAuditService()->record(
            action: 'group.delete',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'group',
            resourceId: $group->id,
            resourceUuid: $group->uuid,
        );
    }

    // ------------------------------------------------------------------ //
    //  Управление участниками                                             //
    // ------------------------------------------------------------------ //

    /**
     * Добавить пользователя в группу.
     *
     * @throws AuthException     если нет прав (admin+)
     * @throws \RuntimeException если группа не найдена, пользователь не в орг. или уже в группе
     */
    public function addMember(
        string $groupUuid,
        string $targetUserId,
        string $requesterId,
        string $orgId,
    ): GroupMember {
        $this->assertHasPermission($orgId, $requesterId, 'admin');

        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException('Group not found.');
        }

        if (OrganizationMember::findByOrgAndUser($orgId, $targetUserId) === null) {
            throw new \RuntimeException('User is not a member of the organization.');
        }

        if (GroupMember::findByGroupAndUser($group->id, $targetUserId) !== null) {
            throw new \RuntimeException('User is already a member of this group.');
        }

        Database::getInstance()->insert('group_members', [
            'group_id' => (int) $group->id,
            'user_id'  => (int) $targetUserId,
            'added_by' => (int) $requesterId,
            'added_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $member = GroupMember::findByGroupAndUser($group->id, $targetUserId)
            ?? throw new \RuntimeException('Failed to load created group member.');

        $this->getAuditService()->record(
            action: 'group.member_add',
            organizationId: $orgId,
            userId: $requesterId,
            resourceType: 'group',
            resourceId: $group->id,
            resourceUuid: $group->uuid,
            details: ['target_user_id' => $targetUserId],
        );

        return $member;
    }

    /**
     * Удалить пользователя из группы.
     *
     * @throws AuthException     если нет прав (admin+)
     * @throws \RuntimeException если группа не найдена или пользователь не в группе
     */
    public function removeMember(
        string $groupUuid,
        string $targetUserId,
        string $requesterId,
        string $orgId,
    ): void {
        $this->assertHasPermission($orgId, $requesterId, 'admin');

        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException('Group not found.');
        }

        if (GroupMember::findByGroupAndUser($group->id, $targetUserId) === null) {
            throw new \RuntimeException('User is not a member of this group.');
        }

        Database::getInstance()->delete('group_members', [
            'group_id' => (int) $group->id,
            'user_id'  => (int) $targetUserId,
        ]);

        $this->getAuditService()->record(
            action: 'group.member_remove',
            organizationId: $orgId,
            userId: $requesterId,
            resourceType: 'group',
            resourceId: $group->id,
            resourceUuid: $group->uuid,
            details: ['target_user_id' => $targetUserId],
        );
    }

    /**
     * @return GroupMember[]
     * @throws AuthException     если нет прав (observer+)
     * @throws \RuntimeException если группа не найдена
     */
    public function listMembers(string $groupUuid, string $orgId, string $userId): array
    {
        $this->assertHasPermission($orgId, $userId, 'observer');
        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException('Group not found.');
        }
        return GroupMember::findByGroupId($group->id);
    }

    /**
     * Получить ID всех групп пользователя в организации.
     *
     * @return string[]
     */
    public function getUserGroupIds(string $userId, string $orgId): array
    {
        return GroupMember::getGroupIdsForUserInOrg($userId, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  Вспомогательные                                                    //
    // ------------------------------------------------------------------ //

    private function assertHasPermission(string $orgId, string $userId, string $minRole): void
    {
        if (!$this->organizationService->hasPermission($orgId, $userId, $minRole)) {
            throw new AuthException(
                \sprintf("Requires '%s' role in this organization.", $minRole),
                403
            );
        }
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService(), $this->organizationService);
    }
}
