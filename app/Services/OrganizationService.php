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
        $name = $this->normalizeName($name);

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
            details: [
                'organization_uuid' => $org->uuid,
                'organization_name' => $org->name,
            ],
        );

        return $org;
    }

    public function rename(string $orgId, string $name, string $requesterId): Organization
    {
        $this->assertHasPermission($orgId, $requesterId, 'admin');
        $name = $this->normalizeName($name);

        $org = Organization::findById($orgId);
        if ($org === null) {
            throw new \RuntimeException(__('ui.backend.common.organization_not_found'));
        }

        if ($org->name !== $name) {
            $org->update([
                'name' => $name,
                'slug' => $this->generateUniqueSlug($name, $org->id),
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);

            $this->getAuditService()->record(
                action: 'org.rename',
                organizationId: $orgId,
                userId: $requesterId,
                resourceType: 'organization',
                resourceId: $orgId,
                resourceUuid: $org->uuid,
                details: ['name' => $name],
            );
        }

        return Organization::findById($orgId) ?? $org;
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
        if ($newRole === 'owner') {
            throw new AuthException(__('ui.backend.organization.cannot_change_owner_role'));
        }

        $requesterIsOwner = $this->hasPermission($orgId, $requesterId, 'owner');

        // Only owner can assign admin
        if ($newRole === 'admin' && !$requesterIsOwner) {
            throw new AuthException(__('ui.backend.organization.only_owner_assign_admin'));
        }

        if (!$requesterIsOwner && ($targetUserId === $requesterId || $target->role === 'admin')) {
            throw new AuthException(__('ui.messages.access_denied'));
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

    public function delete(string $orgId, string $requesterId): void
    {
        $this->assertHasPermission($orgId, $requesterId, 'owner');

        $org = Organization::findById($orgId);
        if ($org === null) {
            throw new \RuntimeException(__('ui.backend.common.organization_not_found'));
        }

        $stats = $this->deletionStats($orgId);
        $now = now()->format('Y-m-d H:i:s');
        $db = Database::getInstance();
        $inactiveLiteral = $this->booleanSqlLiteral(false);

        $db->transaction(function () use ($db, $org, $orgId, $requesterId, $stats, $now, $inactiveLiteral): void {
            $this->getAuditService()->record(
                action: 'org.delete',
                organizationId: $orgId,
                userId: $requesterId,
                resourceType: 'organization',
                resourceId: $orgId,
                resourceUuid: $org->uuid,
                details: [
                    'organization_uuid' => $org->uuid,
                    'organization_name' => $org->name,
                    'directories_count' => $stats['directories'],
                    'secrets_count' => $stats['secrets'],
                    'api_keys_total' => $stats['api_keys_total'],
                    'api_keys_active' => $stats['api_keys_active'],
                ],
            );

            $db->query('UPDATE secrets SET deleted_at = ? WHERE organization_id = ? AND deleted_at IS NULL', [$now, (int) $orgId]);
            $db->query('UPDATE directories SET deleted_at = ? WHERE organization_id = ? AND deleted_at IS NULL', [$now, (int) $orgId]);
            $db->query("UPDATE api_keys SET is_active = {$inactiveLiteral} WHERE organization_id = ?", [(int) $orgId]);

            if ($db->tableExists('organization_integrations')) {
                $db->query("UPDATE organization_integrations SET is_active = {$inactiveLiteral}, updated_at = ? WHERE organization_id = ?", [$now, (int) $orgId]);
            }

            $db->update('organizations', [
                'is_active' => 0,
                'updated_at' => $now,
                'deleted_at' => $now,
            ], ['id' => (int) $orgId]);
        });
    }

    /** @return array{organizations_deleted:int,directories_deleted:int,secrets_deleted:int,permissions_deleted:int} */
    public function purgeDeletedExpired(): array
    {
        $db = Database::getInstance();
        if (!$db->tableExists('organizations')) {
            return ['organizations_deleted' => 0, 'directories_deleted' => 0, 'secrets_deleted' => 0, 'permissions_deleted' => 0];
        }

        $retentionDays = max(1, (int) ($_ENV['ORG_DELETED_PURGE_DAYS'] ?? 30));
        $cutoff = now()->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s');
        $rows = $db->fetchAll('SELECT id FROM organizations WHERE deleted_at IS NOT NULL AND deleted_at < ? ORDER BY id ASC', [$cutoff]);

        $result = ['organizations_deleted' => 0, 'directories_deleted' => 0, 'secrets_deleted' => 0, 'permissions_deleted' => 0];
        foreach ($rows as $row) {
            $orgId = (string) $row['id'];
            $deleted = $this->purgeDeletedOrganization($orgId);
            $result['organizations_deleted'] += $deleted['organizations_deleted'];
            $result['directories_deleted'] += $deleted['directories_deleted'];
            $result['secrets_deleted'] += $deleted['secrets_deleted'];
            $result['permissions_deleted'] += $deleted['permissions_deleted'];
        }

        return $result;
    }

    public function __construct(
        private readonly ?AuditService $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Helpers                                                    //
    // ------------------------------------------------------------------ //

    private function generateUniqueSlug(string $name, ?string $ignoreOrgId = null): string
    {
        $base = \preg_replace('/[^a-z0-9]+/', '-', \strtolower($name)) ?? 'org';
        $base = \trim($base, '-');
        if ($base === '') {
            $base = 'org';
        }

        $slug    = $base;
        $counter = 2;
        while ($this->slugExists($slug, $ignoreOrgId)) {
            $slug = $base . '-' . $counter++;
        }
        return $slug;
    }

    private function slugExists(string $slug, ?string $ignoreOrgId): bool
    {
        $sql = 'SELECT id FROM organizations WHERE slug = ?';
        $params = [$slug];
        if ($ignoreOrgId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = (int) $ignoreOrgId;
        }

        return Database::getInstance()->fetchColumn($sql, $params) !== false;
    }

    private function normalizeName(string $name): string
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ui.backend.organization.name_empty'));
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.organization.name_too_long'));
        }

        return $name;
    }

    /** @return array{directories:int,secrets:int,api_keys_total:int,api_keys_active:int} */
    public function deletionStats(string $orgId): array
    {
        $db = Database::getInstance();
        $directories = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM directories WHERE organization_id = ? AND deleted_at IS NULL AND name <> '__passway_root_secrets__'",
            [(int) $orgId]
        );
        $secrets = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM secrets WHERE organization_id = ? AND deleted_at IS NULL',
            [(int) $orgId]
        );
        $apiKeysTotal = (int) $db->fetchColumn(
            'SELECT COUNT(*) FROM api_keys WHERE organization_id = ?',
            [(int) $orgId]
        );
        $activeLiteral = $this->booleanSqlLiteral(true);
        $apiKeysActive = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM api_keys WHERE organization_id = ? AND is_active = {$activeLiteral} AND (expires_at IS NULL OR expires_at > ?)",
            [(int) $orgId, now()->format('Y-m-d H:i:s')]
        );

        return [
            'directories' => $directories,
            'secrets' => $secrets,
            'api_keys_total' => $apiKeysTotal,
            'api_keys_active' => $apiKeysActive,
        ];
    }

    private function booleanSqlLiteral(bool $value): string
    {
        if (Database::getInstance()->getDriver() === 'pgsql') {
            return $value ? 'TRUE' : 'FALSE';
        }

        return $value ? '1' : '0';
    }

    /** @return array{organizations_deleted:int,directories_deleted:int,secrets_deleted:int,permissions_deleted:int} */
    private function purgeDeletedOrganization(string $orgId): array
    {
        $db = Database::getInstance();
        return $db->transaction(function () use ($db, $orgId): array {
            $secretIds = array_map(static fn(array $row): int => (int) $row['id'], $db->fetchAll(
                'SELECT id FROM secrets WHERE organization_id = ?',
                [(int) $orgId]
            ));
            $directoryIds = array_map(static fn(array $row): int => (int) $row['id'], $db->fetchAll(
                'SELECT id FROM directories WHERE organization_id = ?',
                [(int) $orgId]
            ));

            $permissionsDeleted = 0;
            $permissionsDeleted += $this->deletePermissionsForResources('secret', $secretIds);
            $permissionsDeleted += $this->deletePermissionsForResources('directory', $directoryIds);

            $secretsDeleted = \count($secretIds);
            $directoriesDeleted = \count($directoryIds);
            $organizationsDeleted = $db->delete('organizations', ['id' => (int) $orgId]);

            return [
                'organizations_deleted' => $organizationsDeleted,
                'directories_deleted' => $directoriesDeleted,
                'secrets_deleted' => $secretsDeleted,
                'permissions_deleted' => $permissionsDeleted,
            ];
        });
    }

    /** @param int[] $resourceIds */
    private function deletePermissionsForResources(string $resourceType, array $resourceIds): int
    {
        if ($resourceIds === []) {
            return 0;
        }

        $placeholders = \implode(', ', \array_fill(0, \count($resourceIds), '?'));
        $stmt = Database::getInstance()->query(
            'DELETE FROM user_permissions WHERE resource_type = ? AND resource_id IN (' . $placeholders . ')',
            [$resourceType, ...$resourceIds]
        );

        return $stmt->rowCount();
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
