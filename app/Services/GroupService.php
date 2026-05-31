<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Group;
use Passway\Models\GroupMember;
use Passway\Models\OrganizationMember;

/**
 * Service for managing user groups within an organization.
 *
 * Authorization:
 *   admin+  - create/delete groups, manage members
 *   reader+ - reading (list, show, listMembers)
 */
final class GroupService
{
    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly ?AuditService       $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Creation                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Create a group in an organization.
     *
     * @throws AuthException             if permission is missing (admin+)
     * @throws \InvalidArgumentException on empty/too long name
     * @throws \RuntimeException         if the name is already taken in the organization
     */
    public function create(
        string  $orgId,
        string  $name,
        ?string $description,
        string  $userId,
    ): Group {
        $this->assertTeamMode();
        $this->assertHasPermission($orgId, $userId, 'admin');

        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ui.backend.group.name_empty'));
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.group.name_too_long'));
        }

        $count = (int) Database::getInstance()->fetchColumn(
            'SELECT COUNT(*) FROM groups WHERE organization_id = ? AND name = ?',
            [(int) $orgId, $name]
        );
        if ($count > 0) {
            throw new \RuntimeException(__('ui.backend.group.duplicate_name'));
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
            ?? throw new \RuntimeException(__('ui.backend.group.failed_load_created'));

        $this->getAuditService()->record(
            action: 'group.create',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'group',
            resourceId: $group->id,
            resourceUuid: $group->uuid,
            details: ['group_name' => $group->name, 'group_uuid' => $group->uuid],
        );

        return $group;
    }

    // ------------------------------------------------------------------ //
    //  Reading                                                             //
    // ------------------------------------------------------------------ //

    /**
     * @return Group[]
     * @throws AuthException if permission is missing (reader+)
     */
    public function list(string $orgId, string $userId): array
    {
        $this->assertTeamMode();
        $this->assertHasPermission($orgId, $userId, 'reader');
        return Group::findByOrgId($orgId);
    }

    /**
     * @return array<int, array{group: Group, member_count: int}>
     * @throws AuthException if permission is missing (reader+)
     */
    public function listWithMemberCounts(string $orgId, string $userId): array
    {
        $this->assertTeamMode();
        $this->assertHasPermission($orgId, $userId, 'reader');

        $rows = Database::getInstance()->fetchAll(
            'SELECT g.*, COALESCE(c.member_count, 0) AS member_count
             FROM groups g
             LEFT JOIN (
                 SELECT group_id, COUNT(*) AS member_count
                 FROM group_members
                 GROUP BY group_id
             ) c ON c.group_id = g.id
             WHERE g.organization_id = ?
             ORDER BY g.name',
            [(int) $orgId],
        );

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = [
                'group' => Group::fromRow($row),
                'member_count' => (int) $row['member_count'],
            ];
        }

        return $groups;
    }

    /**
     * @throws AuthException     if permission is missing (reader+)
     * @throws \RuntimeException if not found or belongs to another org.
     */
    public function findInOrg(string $groupUuid, string $orgId, string $userId): Group
    {
        $this->assertTeamMode();
        $this->assertHasPermission($orgId, $userId, 'reader');
        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.group.not_found'));
        }
        return $group;
    }

    // ------------------------------------------------------------------ //
    //  Deletion                                                           //
    // ------------------------------------------------------------------ //

    /**
     * @throws AuthException     if permission is missing (admin+)
     * @throws \RuntimeException if not found
     */
    public function delete(string $groupUuid, string $orgId, string $userId): void
    {
        $this->assertTeamMode();
        $this->assertHasPermission($orgId, $userId, 'admin');
        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.group.not_found'));
        }
        Database::getInstance()->delete('groups', ['id' => (int) $group->id]);

        $this->getAuditService()->record(
            action: 'group.delete',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'group',
            resourceId: $group->id,
            resourceUuid: $group->uuid,
            details: ['group_name' => $group->name, 'group_uuid' => $group->uuid],
        );
    }

    // ------------------------------------------------------------------ //
    //  Member management                                             //
    // ------------------------------------------------------------------ //

    /**
     * Add a user to a group.
     *
     * @throws AuthException     if permission is missing (admin+)
     * @throws \RuntimeException if the group is not found, the user is not in the org, or is already in the group
     */
    public function addMember(
        string $groupUuid,
        string $targetUserId,
        string $requesterId,
        string $orgId,
    ): GroupMember {
        $this->assertTeamMode();
        $this->assertHasPermission($orgId, $requesterId, 'admin');

        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.group.not_found'));
        }

        if (OrganizationMember::findByOrgAndUser($orgId, $targetUserId) === null) {
            throw new \RuntimeException(__('ui.backend.common.user_not_member_org'));
        }

        if (GroupMember::findByGroupAndUser($group->id, $targetUserId) !== null) {
            throw new \RuntimeException(__('ui.backend.group.already_member'));
        }

        Database::getInstance()->insert('group_members', [
            'group_id' => (int) $group->id,
            'user_id'  => (int) $targetUserId,
            'added_by' => (int) $requesterId,
            'added_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $member = GroupMember::findByGroupAndUser($group->id, $targetUserId)
            ?? throw new \RuntimeException(__('ui.backend.group.failed_load_created_member'));

        $this->getAuditService()->record(
            action: 'group.member_add',
            organizationId: $orgId,
            userId: $requesterId,
            resourceType: 'group',
            resourceId: $group->id,
            resourceUuid: $group->uuid,
            details: ['target_user_id' => $targetUserId, 'group_name' => $group->name, 'group_uuid' => $group->uuid],
        );

        return $member;
    }

    /**
     * Remove a user from a group.
     *
     * @throws AuthException     if permission is missing (admin+)
     * @throws \RuntimeException if the group is not found or the user is not in the group
     */
    public function removeMember(
        string $groupUuid,
        string $targetUserId,
        string $requesterId,
        string $orgId,
    ): void {
        $this->assertTeamMode();
        $this->assertHasPermission($orgId, $requesterId, 'admin');

        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.group.not_found'));
        }

        if (GroupMember::findByGroupAndUser($group->id, $targetUserId) === null) {
            throw new \RuntimeException(__('ui.backend.group.user_not_member_group'));
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
            details: ['target_user_id' => $targetUserId, 'group_name' => $group->name, 'group_uuid' => $group->uuid],
        );
    }

    /**
     * @return GroupMember[]
     * @throws AuthException     if permission is missing (reader+)
     * @throws \RuntimeException if the group is not found
     */
    public function listMembers(string $groupUuid, string $orgId, string $userId): array
    {
        $this->assertTeamMode();
        $this->assertHasPermission($orgId, $userId, 'reader');
        $group = Group::findByUuid($groupUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.group.not_found'));
        }
        return GroupMember::findByGroupId($group->id);
    }

    /**
     * Get IDs of all user groups in an organization.
     *
     * @return string[]
     */
    public function getUserGroupIds(string $userId, string $orgId): array
    {
        if (DeployMode::isSolo()) {
            return [];
        }

        return GroupMember::getGroupIdsForUserInOrg($userId, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                    //
    // ------------------------------------------------------------------ //

    private function assertHasPermission(string $orgId, string $userId, string $minRole): void
    {
        if (!$this->organizationService->hasPermission($orgId, $userId, $minRole)) {
            throw new AuthException(
                __('ui.backend.group.requires_role', ['role' => $minRole]),
                403
            );
        }
    }

    private function assertTeamMode(): void
    {
        if (DeployMode::isSolo()) {
            throw new AuthException(__('ui.backend.group.team_mode_required'), 403);
        }
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService(), $this->organizationService);
    }
}
