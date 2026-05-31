<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\AuthContext;
use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Directory;
use Passway\Models\Secret;
use Passway\Models\UserPermission;

/**
 * Service fine-grained directory-level access control.
 *
 * Check priority:
 *   1. ACL on the target resource.
 *   2. ACL on the nearest ancestor (for secrets: folder -> parent folders).
 *   3. Fallback to the organization role if no ACL rules are found.
 *   4. No matches -> access denied.
 */
final class PermissionService
{
    public const VALID_PERMISSIONS = UserPermission::VALID_PERMISSIONS;
    public const VALID_ACCESS_POLICIES = ['inherit', 'allow', 'deny'];

    /**
     * Minimum role in the organization, sufficient for this permission.
     * Users with this role pass the check without fine-grained analysis.
     */
    private const PERM_TO_ORG_ROLE = [
        'read'                  => 'reader',
        'write'                 => 'editor',
        'delete'                => 'editor',
        'create_subdirectories' => 'editor',
    ];

    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly GroupService        $groupService,
        private readonly ?ApiKeyAccessService $apiKeyAccessService = null,
        private readonly ?AuditService       $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Access check                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Check whether the user has the specified permission on the resource.
     *
     * @param string $permission   read|write|delete|create_subdirectories
     * @param string $userId       User ID
     * @param string $resourceType directory|secret
     * @param string $resourceId   Resource ID (numeric)
     * @param string $orgId        Organization ID
     */
    public function can(
        string $permission,
        string $userId,
        string $resourceType,
        string $resourceId,
        string $orgId,
    ): bool {
        $apiKey = AuthContext::getApiKey();
        if ($apiKey !== null) {
            return $this->getApiKeyAccessService()->can($apiKey->id, $permission, $resourceType, $resourceId, $orgId);
        }

        // The user must at least belong to the organization
        if ($this->organizationService->getMemberRole($orgId, $userId) === null) {
            return false;
        }

        if ($this->isOwnedByUser($resourceType, $resourceId, $userId)) {
            return true;
        }

        // 1-2. Fine-grained ACL on the resource and its ancestors
        $fineGrained = $this->checkFineGrained($permission, $userId, $resourceType, $resourceId, $orgId);
        if ($fineGrained !== null) {
            return $fineGrained;
        }

        $defaultPolicy = $this->checkDefaultPolicy($permission, $resourceType, $resourceId);
        if ($defaultPolicy !== null) {
            return $defaultPolicy;
        }

        // 3. Fallback to the organization role
        $minRole = self::PERM_TO_ORG_ROLE[$permission] ?? 'editor';
        return $this->organizationService->hasPermission($orgId, $userId, $minRole);
    }

    // ------------------------------------------------------------------ //
    //  Permission entry management                                           //
    // ------------------------------------------------------------------ //

    /**
     * Grant an allow or deny permission to a subject on a resource.
     * If an entry for the same triple (subject, resource, permission) already exists - updates it.
     *
     * @throws AuthException             if requesterId lacks admin+
     * @throws \InvalidArgumentException on invalid parameters
     */
    public function grant(
        string  $subjectType,
        string  $subjectId,
        string  $resourceType,
        string  $resourceId,
        string  $permission,
        bool    $isDeny,
        ?string $expiresAt,
        string  $grantedBy,
        string  $orgId,
    ): UserPermission {
        $this->assertSoloSubjectAllowed($subjectType);

        if (!$this->organizationService->hasPermission($orgId, $grantedBy, 'admin')) {
            throw new AuthException(__('ui.backend.permission.requires_admin_grant'), 403);
        }

        if (!\in_array($subjectType, UserPermission::VALID_SUBJECT_TYPES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.permission.invalid_subject_type', ['allowed' => \implode(', ', UserPermission::VALID_SUBJECT_TYPES)])
            );
        }
        if (!\in_array($resourceType, UserPermission::VALID_RESOURCE_TYPES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.permission.invalid_resource_type', ['allowed' => \implode(', ', UserPermission::VALID_RESOURCE_TYPES)])
            );
        }
        if (!\in_array($permission, UserPermission::VALID_PERMISSIONS, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.permission.invalid_permission', ['allowed' => \implode(', ', UserPermission::VALID_PERMISSIONS)])
            );
        }

        $db  = Database::getInstance();
        $now = now()->format('Y-m-d H:i:s');

        // Upsert: update an existing entry or insert a new one
        $existing = UserPermission::findForSubject($subjectType, $subjectId, $resourceType, $resourceId);
        $found    = null;
        foreach ($existing as $p) {
            if ($p->permission === $permission) {
                $found = $p;
                break;
            }
        }

        if ($found !== null) {
            $db->update('user_permissions', [
                'is_deny'    => $isDeny ? 1 : 0,
                'expires_at' => $expiresAt,
                'granted_by' => (int) $grantedBy,
            ], ['id' => (int) $found->id]);

            $perm = UserPermission::findById($found->id)
                ?? throw new \RuntimeException(__('ui.backend.permission.failed_reload_after_update'));

            $this->getAuditService()->record(
                action: 'permission.grant',
                organizationId: $orgId,
                userId: $grantedBy,
                resourceType: 'permission',
                resourceId: $perm->id,
                details: ['subject_type' => $subjectType, 'permission' => $permission, 'is_deny' => $isDeny],
            );

            return $perm;
        }

        $id = $db->insert('user_permissions', [
            'subject_type'  => $subjectType,
            'subject_id'    => (int) $subjectId,
            'resource_type' => $resourceType,
            'resource_id'   => (int) $resourceId,
            'permission'    => $permission,
            'is_deny'       => $isDeny ? 1 : 0,
            'expires_at'    => $expiresAt,
            'granted_by'    => (int) $grantedBy,
            'created_at'    => $now,
        ]);

        $perm = UserPermission::findById((string) $id)
            ?? throw new \RuntimeException(__('ui.backend.permission.failed_load_created'));

        $this->getAuditService()->record(
            action: 'permission.grant',
            organizationId: $orgId,
            userId: $grantedBy,
            resourceType: 'permission',
            resourceId: $perm->id,
            details: ['subject_type' => $subjectType, 'permission' => $permission, 'is_deny' => $isDeny],
        );

        return $perm;
    }

    /**
     * Revoke permission by ID.
     *
     * @throws AuthException     if permission is missing (admin+)
     * @throws \RuntimeException if the entry is not found
     */
    public function revoke(string $permId, string $requesterId, string $orgId): void
    {
        if (!$this->organizationService->hasPermission($orgId, $requesterId, 'admin')) {
            throw new AuthException(__('ui.backend.permission.requires_admin_revoke'), 403);
        }

        if (UserPermission::findById($permId) === null) {
            throw new \RuntimeException(__('ui.backend.permission.not_found'));
        }

        Database::getInstance()->delete('user_permissions', ['id' => (int) $permId]);

        $this->getAuditService()->record(
            action: 'permission.revoke',
            organizationId: $orgId,
            userId: $requesterId,
            resourceType: 'permission',
            resourceId: $permId,
        );
    }

    /**
     * List all permissions on a directory (for management).
     *
     * @return UserPermission[]
     * @throws AuthException if permission is missing (admin+)
     */
    public function listForDirectory(string $dirId, string $requesterId, string $orgId): array
    {
        if (!$this->organizationService->hasPermission($orgId, $requesterId, 'admin')) {
            throw new AuthException(__('ui.backend.permission.requires_admin_view'), 403);
        }
        return UserPermission::findForResource('directory', $dirId);
    }

    /**
     * @return UserPermission[]
     */
    public function listForResource(string $resourceType, string $resourceId): array
    {
        return \array_values(\array_filter(
            UserPermission::findForResource($resourceType, $resourceId),
            static function (UserPermission $permission): bool {
                if (!\in_array($permission->permission, ['read', 'write'], true)) {
                    return false;
                }

                if (DeployMode::isSolo() && $permission->subjectType !== 'api_key') {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * @param array<int, array{subject_type:string,subject_id:string,read:?string,write:?string}> $rules
     * @return UserPermission[]
     */
    public function replaceForResource(string $resourceType, string $resourceId, array $rules, string $grantedBy): array
    {
        if (!\in_array($resourceType, UserPermission::VALID_RESOURCE_TYPES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.permission.invalid_resource_type', ['allowed' => \implode(', ', UserPermission::VALID_RESOURCE_TYPES)])
            );
        }

        $now = now()->format('Y-m-d H:i:s');
        $db = Database::getInstance();
        $normalized = [];

        foreach ($rules as $index => $rule) {
            $subjectType = isset($rule['subject_type']) ? \trim((string) $rule['subject_type']) : '';
            $subjectId = isset($rule['subject_id']) ? \trim((string) $rule['subject_id']) : '';

            $this->assertSoloSubjectAllowed($subjectType);

            if (!\in_array($subjectType, UserPermission::VALID_SUBJECT_TYPES, true)) {
                throw new \InvalidArgumentException(
                    __('ui.backend.permission.invalid_subject_type', ['allowed' => \implode(', ', UserPermission::VALID_SUBJECT_TYPES)])
                );
            }

            if ($subjectId === '') {
                throw new \InvalidArgumentException(__('ui.backend.permission.subject_id_required'));
            }

            $read = $this->normalizeEffect($rule['read'] ?? null);
            $write = $this->normalizeEffect($rule['write'] ?? null);
            $key = $subjectType . ':' . $subjectId;

            if (isset($normalized[$key])) {
                throw new \InvalidArgumentException(__('ui.backend.permission.duplicate_subject_rule', ['index' => (string) ($index + 1)]));
            }

            $normalized[$key] = [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'read' => $read,
                'write' => $write,
            ];
        }

        $db->transaction(function () use ($db, $resourceType, $resourceId, $grantedBy, $now, $normalized): void {
            $db->query(
                'DELETE FROM user_permissions WHERE resource_type = ? AND resource_id = ?',
                [$resourceType, (int) $resourceId]
            );

            foreach ($normalized as $rule) {
                foreach (['read', 'write'] as $permission) {
                    $effect = $rule[$permission];
                    if ($effect === null) {
                        continue;
                    }

                    $db->insert('user_permissions', [
                        'subject_type' => $rule['subject_type'],
                        'subject_id' => (int) $rule['subject_id'],
                        'resource_type' => $resourceType,
                        'resource_id' => (int) $resourceId,
                        'permission' => $permission,
                        'is_deny' => $effect === 'deny' ? 1 : 0,
                        'expires_at' => null,
                        'granted_by' => (int) $grantedBy,
                        'created_at' => $now,
                    ]);
                }
            }
        });

        return $this->listForResource($resourceType, $resourceId);
    }

    public function removeUserRulesForResource(string $resourceType, string $resourceId, string $userId): void
    {
        if (!\in_array($resourceType, UserPermission::VALID_RESOURCE_TYPES, true)) {
            throw new \InvalidArgumentException(
                __('ui.backend.permission.invalid_resource_type', ['allowed' => \implode(', ', UserPermission::VALID_RESOURCE_TYPES)])
            );
        }

        Database::getInstance()->query(
            'DELETE FROM user_permissions WHERE subject_type = ? AND subject_id = ? AND resource_type = ? AND resource_id = ?',
            ['user', (int) $userId, $resourceType, (int) $resourceId]
        );
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                    //
    // ------------------------------------------------------------------ //

    private function isOwnedByUser(string $resourceType, string $resourceId, string $userId): bool
    {
        if ($resourceType === 'secret') {
            $secret = Secret::findById($resourceId);
            return $secret !== null && $secret->ownerUserId === $userId;
        }

        if ($resourceType === 'directory') {
            $directory = Directory::findById($resourceId);
            return $directory !== null && $directory->ownerUserId === $userId;
        }

        return false;
    }

    /**
     * Check user/group ACL on the resource and its ancestors.
     */
    private function checkFineGrained(
        string $permission,
        string $userId,
        string $resourceType,
        string $resourceId,
        string $orgId,
    ): ?bool {
        if (DeployMode::isSolo()) {
            return null;
        }

        $groupIds      = $this->groupService->getUserGroupIds($userId, $orgId);
        $resourceChain = $this->buildResourceChain($resourceType, $resourceId);

        foreach ($resourceChain as $resource) {
            $result = $this->evalPermission(
                $permission,
                $userId,
                $groupIds,
                $resource['resource_type'],
                $resource['resource_id']
            );
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function checkDefaultPolicy(string $permission, string $resourceType, string $resourceId): ?bool
    {
        if (!\in_array($permission, ['read', 'write'], true)) {
            return null;
        }

        foreach ($this->buildResourceChain($resourceType, $resourceId) as $resource) {
            $effect = $permission === 'read'
                ? ($resource['default_read_access'] ?? 'inherit')
                : ($resource['default_write_access'] ?? 'inherit');

            if ($effect === 'allow') {
                return true;
            }

            if ($effect === 'deny') {
                return false;
            }
        }

        return null;
    }

    /**
     * Check permissions on a specific resource (without inheritance).
     * Returns true/false if a rule is found, null if there are no rules.
     */
    private function evalPermission(
        string $permission,
        string $userId,
        array  $groupIds,
        string $resourceType,
        string $resourceId,
    ): ?bool {
        $perms = UserPermission::findForSubject('user', $userId, $resourceType, $resourceId);

        foreach ($groupIds as $gid) {
            $perms = \array_merge(
                $perms,
                UserPermission::findForSubject('group', $gid, $resourceType, $resourceId)
            );
        }

        // Filter: the needed permission and non-expired
        $perms = \array_filter(
            $perms,
            fn(UserPermission $p) => $p->permission === $permission && $this->isActive($p)
        );

        // Explicit denies take priority over allows
        foreach ($perms as $p) {
            if ($p->isDeny) {
                return false;
            }
        }

        foreach ($perms as $p) {
            return true;
        }

        return null; // Нет правил на этом уровне — проверить предка
    }

    private function assertSoloSubjectAllowed(string $subjectType): void
    {
        if (DeployMode::isSolo() && \in_array($subjectType, ['user', 'group'], true)) {
            throw new \InvalidArgumentException(__('ui.backend.permission.solo_only_api_keys'));
        }
    }

    /**
     * Build the resource chain for ACL checks
     * (from the specific resource to the root directory).
     *
     * @return array<int, array{resource_type:string, resource_id:string, default_read_access:string, default_write_access:string}>
     */
    private function buildResourceChain(string $resourceType, string $resourceId): array
    {
        if ($resourceType === 'secret') {
            $chain = [[
                'resource_type' => 'secret',
                'resource_id' => $resourceId,
                'default_read_access' => 'inherit',
                'default_write_access' => 'inherit',
            ]];

            $secret = Secret::findById($resourceId);
            if ($secret === null) {
                return $chain;
            }

            $chain[0]['default_read_access'] = $secret->defaultReadAccess;
            $chain[0]['default_write_access'] = $secret->defaultWriteAccess;

            return [...$chain, ...$this->buildDirectoryResourceChain($secret->directoryId)];
        }

        if ($resourceType === 'directory') {
            return $this->buildDirectoryResourceChain($resourceId);
        }

        return [[
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'default_read_access' => 'inherit',
            'default_write_access' => 'inherit',
        ]];
    }

    /**
     * @return array<int, array{resource_type:string, resource_id:string, default_read_access:string, default_write_access:string}>
     */
    private function buildDirectoryResourceChain(string $directoryId): array
    {
        $chain   = [];
        $current = Directory::findById($directoryId);

        while ($current !== null) {
            $chain[] = [
                'resource_type' => 'directory',
                'resource_id' => $current->id,
                'default_read_access' => $current->defaultReadAccess,
                'default_write_access' => $current->defaultWriteAccess,
            ];
            $current = $current->parentId !== null
                ? Directory::findById($current->parentId)
                : null;
        }

        return $chain;
    }

    /**
     * Check that the permission has not expired yet.
     */
    private function isActive(UserPermission $perm): bool
    {
        if ($perm->expiresAt === null) {
            return true;
        }
        return \strtotime($perm->expiresAt) > \time();
    }

    private function normalizeEffect(mixed $effect): ?string
    {
        if ($effect === null || $effect === '') {
            return null;
        }

        $value = \is_string($effect) ? \trim($effect) : '';
        if (!\in_array($value, ['allow', 'deny'], true)) {
            throw new \InvalidArgumentException(__('ui.backend.permission.invalid_effect'));
        }

        return $value;
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService(), $this->organizationService);
    }

    private function getApiKeyAccessService(): ApiKeyAccessService
    {
        return $this->apiKeyAccessService ?? new ApiKeyAccessService();
    }
}
