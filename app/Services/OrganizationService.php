<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Organization;
use Passway\Models\OrganizationMember;

/**
 * Service for managing organizations and their members.
 *
 * Authorization:
 *   owner      - everything
 *   admin      - member and invite management
 *   editor     - directory/secret management
 *   reader     - read-only
 */
final class OrganizationService
{
    /** Default invite link TTL (1 hour) */
    public const DEFAULT_INVITE_TTL = 3600;

    // ------------------------------------------------------------------ //
    //  Organization creation                                               //
    // ------------------------------------------------------------------ //

    /**
     * Create an organization and add the creator as owner.
     *
     * @throws \InvalidArgumentException on empty name
     */
    public function create(string $name, string $ownerId): Organization
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ui.backend.organization.name_empty'));
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.organization.name_too_long'));
        }

        $slug = $this->generateUniqueSlug($name);
        $now  = now()->format('Y-m-d H:i:s');

        $db = Database::getInstance();
        $db->transaction(function () use ($db, $name, $slug, $ownerId, $now): void {
            $orgId = $db->insert('organizations', [
                'uuid'       => generate_uuid(),
                'name'       => $name,
                'slug'       => $slug,
                'owner_id'   => (int) $ownerId,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $db->insert('organization_members', [
                'organization_id' => (int) $orgId,
                'user_id'         => (int) $ownerId,
                'role'            => 'owner',
                'invited_by'      => null,
                'joined_at'       => $now,
            ]);
        });

        // Load the created organization by slug (uuid is not yet known directly)
        $org = Organization::findBySlug($slug);
        if ($org === null) {
            throw new \RuntimeException(__('ui.backend.organization.failed_load_created'));
        }

        $this->getAuditService()->record(
            action: 'org.create',
            organizationId: $org->id,
            userId: $ownerId,
            resourceType: 'organization',
            resourceId: $org->id,
            resourceUuid: $org->uuid,
        );

        return $org;
    }

    // ------------------------------------------------------------------ //
    //  Reading                                                             //
    // ------------------------------------------------------------------ //

    /** @return Organization[] */
    public function getForUser(string $userId): array
    {
        return Organization::findByUserId($userId);
    }

    public function getMemberRole(string $orgId, string $userId): ?string
    {
        $member = OrganizationMember::findByOrgAndUser($orgId, $userId);
        return $member?->role;
    }

    /**
     * Check whether the user has at least $minRole in the organization.
     */
    public function hasPermission(string $orgId, string $userId, string $minRole): bool
    {
        $role = $this->getMemberRole($orgId, $userId);
        if ($role === null) {
            return false;
        }
        return OrganizationMember::roleHasPermission($role, $minRole);
    }

    /**
     * @return OrganizationMember[]
     */
    public function listMembers(string $orgId): array
    {
        return OrganizationMember::findByOrgId($orgId);
    }

    // ------------------------------------------------------------------ //
    //  Member management                                             //
    // ------------------------------------------------------------------ //

    /**
     * Add a member to an organization.
     *
     * @throws AuthException if requesterId lacks admin+ permission
     * @throws \RuntimeException if the user is already in the org
     */
    public function addMember(
        string  $orgId,
        string  $userId,
        string  $role,
        ?string $invitedBy,
    ): OrganizationMember {
        $this->assertValidRole($role);

        if (OrganizationMember::findByOrgAndUser($orgId, $userId) !== null) {
            throw new \RuntimeException(__('ui.backend.organization.already_member'));
        }

        $now = now()->format('Y-m-d H:i:s');
        Database::getInstance()->insert('organization_members', [
            'organization_id' => (int) $orgId,
            'user_id'         => (int) $userId,
            'role'            => $role,
            'invited_by'      => $invitedBy !== null ? (int) $invitedBy : null,
            'joined_at'       => $now,
        ]);

        $member = OrganizationMember::findByOrgAndUser($orgId, $userId)
            ?? throw new \RuntimeException(__('ui.backend.organization.failed_load_member'));

        $this->getAuditService()->record(
            action: 'org.member_add',
            organizationId: $orgId,
            userId: $invitedBy,
            resourceType: 'user',
            resourceId: $userId,
            details: ['role' => $role],
        );

        return $member;
    }

    /**
     * Update a member role.
     *
     * @throws AuthException if permission is missing or trying to change owner
     */
    public function updateMemberRole(
        string $orgId,
        string $targetUserId,
        string $newRole,
        string $requesterId,
    ): void {
        $this->assertTeamMode('ui.backend.organization.team_mode_required');
        $this->assertHasPermission($orgId, $requesterId, 'admin');
        $this->assertValidRole($newRole);

        $target = OrganizationMember::findByOrgAndUser($orgId, $targetUserId);
        if ($target === null) {
            throw new \RuntimeException(__('ui.backend.organization.member_not_found'));
        }
        if ($target->role === 'owner') {
            throw new AuthException(__('ui.backend.organization.cannot_change_owner_role'));
        }
        // Only owner can assign admin
        if ($newRole === 'admin' && !$this->hasPermission($orgId, $requesterId, 'owner')) {
            throw new AuthException(__('ui.backend.organization.only_owner_assign_admin'));
        }

        Database::getInstance()->update(
            'organization_members',
            ['role' => $newRole],
            ['organization_id' => (int) $orgId, 'user_id' => (int) $targetUserId]
        );

        $this->getAuditService()->record(
            action: 'org.member_role_update',
            organizationId: $orgId,
            userId: $requesterId,
            resourceType: 'user',
            resourceId: $targetUserId,
            details: ['role' => $newRole],
        );
    }

    /**
     * Remove a member from an organization.
     *
     * @throws AuthException if permission is missing or trying to remove owner
     */
    public function removeMember(
        string $orgId,
        string $targetUserId,
        string $requesterId,
    ): void {
        $this->assertTeamMode('ui.backend.organization.team_mode_required');
        // A member can remove themselves (leave the org)
        $isSelf = $targetUserId === $requesterId;

        if (!$isSelf) {
            $this->assertHasPermission($orgId, $requesterId, 'admin');
        }

        $target = OrganizationMember::findByOrgAndUser($orgId, $targetUserId);
        if ($target === null) {
            throw new \RuntimeException(__('ui.backend.organization.member_not_found'));
        }
        if ($target->role === 'owner') {
            throw new AuthException(__('ui.backend.organization.cannot_remove_owner'));
        }

        Database::getInstance()->delete(
            'organization_members',
            ['organization_id' => (int) $orgId, 'user_id' => (int) $targetUserId]
        );

        $this->getAuditService()->record(
            action: 'org.member_remove',
            organizationId: $orgId,
            userId: $requesterId,
            resourceType: 'user',
            resourceId: $targetUserId,
        );
    }

    /**
     * Transfer organization ownership to another member.
     *
     * @throws AuthException if requesterId is not owner
     */
    public function transferOwnership(
        string $orgId,
        string $newOwnerId,
        string $requesterId,
    ): void {
        $this->assertTeamMode('ui.backend.organization.team_mode_required');
        $this->assertHasPermission($orgId, $requesterId, 'owner');

        $newOwner = OrganizationMember::findByOrgAndUser($orgId, $newOwnerId);
        if ($newOwner === null) {
            throw new \RuntimeException(__('ui.backend.organization.new_owner_must_be_member'));
        }

        $db  = Database::getInstance();
        $now = now()->format('Y-m-d H:i:s');

        $db->transaction(function () use ($db, $orgId, $requesterId, $newOwnerId, $now): void {
            // Demote the current owner to admin
            $db->update(
                'organization_members',
                ['role' => 'admin'],
                ['organization_id' => (int) $orgId, 'user_id' => (int) $requesterId]
            );
            // Promote the new owner
            $db->update(
                'organization_members',
                ['role' => 'owner'],
                ['organization_id' => (int) $orgId, 'user_id' => (int) $newOwnerId]
            );
            // Update the owner_id field in organizations
            $db->update(
                'organizations',
                ['owner_id' => (int) $newOwnerId, 'updated_at' => $now],
                ['id' => (int) $orgId]
            );
        });

        $this->getAuditService()->record(
            action: 'org.transfer_ownership',
            organizationId: $orgId,
            userId: $requesterId,
            resourceType: 'organization',
            resourceId: $orgId,
            details: ['new_owner_id' => $newOwnerId],
        );
    }

    public function __construct(
        private readonly ?AuditService $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Helpers                                                    //
    // ------------------------------------------------------------------ //

    private function generateUniqueSlug(string $name): string
    {
        $base = \preg_replace('/[^a-z0-9]+/', '-', \strtolower($name)) ?? 'org';
        $base = \trim($base, '-');
        if ($base === '') {
            $base = 'org';
        }

        $slug    = $base;
        $counter = 2;
        while (Organization::findBySlug($slug) !== null) {
            $slug = $base . '-' . $counter++;
        }
        return $slug;
    }

    /**
     * @throws AuthException
     */
    private function assertHasPermission(string $orgId, string $userId, string $minRole): void
    {
        if (!$this->hasPermission($orgId, $userId, $minRole)) {
            throw new AuthException(
                __('ui.backend.organization.requires_role', ['role' => $minRole]),
                403
            );
        }
    }

    private function assertTeamMode(string $messageKey): void
    {
        if (DeployMode::isSolo()) {
            throw new AuthException(__($messageKey), 403);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValidRole(string $role): void
    {
        if (!\in_array($role, OrganizationMember::ROLES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.organization.invalid_role', ['allowed' => \implode(', ', OrganizationMember::ROLES)])
            );
        }
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService(), $this);
    }
}
