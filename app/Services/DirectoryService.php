<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\AuthContext;
use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Directory;

/**
 * Directory management service.
 *
 * Authorization (through PermissionService, which includes ACL and role fallback):
 *   read                  — list, show
 *   write                 — create, rename, move
 *   delete                - directory owner only
 *   create_subdirectories - legacy alias for write on the parent
 *
 * Path structure (materialized path):
 *   Root directory : /{uuid}
 *   Child        : /{parent_uuid}/.../{uuid}
 */
final class DirectoryService
{
    /**
     * Maximum nesting depth (depth 0..19 = 20 levels).
     * depth 0 - root directory.
     */
    public const MAX_DEPTH = 19;

    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly PermissionService   $permissionService,
        private readonly ?ApiKeyAccessService $apiKeyAccessService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Creation                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Create a directory in the organization.
     *
     * @param string      $orgId      Organization ID
     * @param string|null $parentUuid Parent UUID (null = root directory)
     * @param string      $name       Directory name
     * @param string      $userId     Creator ID
     *
     * @throws AuthException             if permission is missing (requires editor+)
     * @throws \InvalidArgumentException on empty/too long name
     * @throws \RuntimeException         if the parent is not found or depth is exceeded
     */
    public function create(
        string  $orgId,
        ?string $parentUuid,
        string  $name,
        string  $userId,
        string  $defaultReadAccess = 'inherit',
        string  $defaultWriteAccess = 'inherit',
    ): Directory {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ui.backend.directory.name_empty'));
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.directory.name_too_long'));
        }

        $defaultReadAccess = $this->normalizeAccessPolicy($defaultReadAccess);
        $defaultWriteAccess = $this->normalizeAccessPolicy($defaultWriteAccess);

        $parent = null;
        if ($parentUuid !== null) {
            $parent = Directory::findByUuid($parentUuid);
            if ($parent === null || $parent->organizationId !== $orgId) {
                throw new \RuntimeException(__('ui.backend.directory.parent_not_found'));
            }
            if ($parent->depth >= self::MAX_DEPTH) {
                throw new \RuntimeException(
                    __('ui.backend.directory.max_depth_reached', ['levels' => (string) (self::MAX_DEPTH + 1)])
                );
            }
            // In the new model, nested directories are controlled by write permission on the parent.
            $this->assertCan('write', $userId, 'directory', $parent->id, $orgId);
        } else {
            // Root directory - org-level editor
            $this->assertHasPermission($orgId, $userId, 'editor');
        }

        $uuid  = generate_uuid();
        $depth = $parent !== null ? $parent->depth + 1 : 0;
        $path  = $parent !== null ? $parent->path . '/' . $uuid : '/' . $uuid;
        $now   = now()->format('Y-m-d H:i:s');

        Database::getInstance()->insert('directories', [
            'uuid'            => $uuid,
            'organization_id' => (int) $orgId,
            'parent_id'       => $parent !== null ? (int) $parent->id : null,
            'name'            => $name,
            'depth'           => $depth,
            'path'            => $path,
            'created_by'      => (int) $userId,
            'owner_user_id'   => (int) $userId,
            'default_read_access' => $defaultReadAccess,
            'default_write_access' => $defaultWriteAccess,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return Directory::findByUuid($uuid)
            ?? throw new \RuntimeException(__('ui.backend.directory.failed_load_created'));
    }

    // ------------------------------------------------------------------ //
    //  Reading                                                             //
    // ------------------------------------------------------------------ //

    /**
     * List all organization directories (flat, sorted by depth/path).
     *
     * @return Directory[]
     * @throws AuthException if permission is missing (requires reader+)
     */
    public function listAll(string $orgId, string $userId): array
    {
        $this->assertHasPermission($orgId, $userId, 'reader');

        return \array_values(\array_filter(
            Directory::findByOrgId($orgId),
            fn(Directory $dir): bool => $this->permissionService->can('read', $userId, 'directory', $dir->id, $orgId)
        ));
    }

    /**
     * Direct child directories (or root directories, if parentUuid = null).
     *
     * @return Directory[]
     * @throws AuthException if permission is missing
     * @throws \RuntimeException if the parent is not found in this organization
     */
    public function listChildren(string $orgId, ?string $parentUuid, string $userId): array
    {
        $this->assertHasPermission($orgId, $userId, 'reader');

        $parentId = null;
        if ($parentUuid !== null) {
            $parent = Directory::findByUuid($parentUuid);
            if ($parent === null || $parent->organizationId !== $orgId) {
                throw new \RuntimeException(__('ui.backend.directory.parent_not_found'));
            }
            $parentId = $parent->id;
        }

        return \array_values(\array_filter(
            Directory::findChildren($orgId, $parentId),
            fn(Directory $dir): bool => $this->permissionService->can('read', $userId, 'directory', $dir->id, $orgId)
        ));
    }

    /**
     * Find a directory by UUID with organization ownership check.
     *
     * @throws AuthException if permission is missing
     * @throws \RuntimeException if not found or belongs to another org
     */
    public function findInOrg(string $dirUuid, string $orgId, string $userId): Directory
    {
        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }

        $this->assertCan('read', $userId, 'directory', $dir->id, $orgId);

        return $dir;
    }

    // ------------------------------------------------------------------ //
    //  Rename                                                     //
    // ------------------------------------------------------------------ //

    /**
     * Rename a directory.
     *
     * @throws AuthException             if permission is missing (requires editor+)
     * @throws \InvalidArgumentException on empty/too long name
     * @throws \RuntimeException         if not found
     */
    public function rename(
        string $dirUuid,
        string $orgId,
        string $newName,
        string $userId,
    ): Directory {
        $newName = \trim($newName);
        if ($newName === '') {
            throw new \InvalidArgumentException(__('ui.backend.directory.name_empty'));
        }
        if (\strlen($newName) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.directory.name_too_long'));
        }

        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }

        $this->assertCan('write', $userId, 'directory', $dir->id, $orgId);

        $dir->update(['name' => $newName, 'updated_at' => now()->format('Y-m-d H:i:s')]);

        return Directory::findByUuid($dirUuid)
            ?? throw new \RuntimeException(__('ui.backend.directory.failed_reload_after_rename'));
    }

    // ------------------------------------------------------------------ //
    //  Move                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Move a directory (and all its descendants) to a new parent.
     * If newParentUuid = null, move to the organization root.
     *
     * @throws AuthException     if permission is missing (requires editor+)
     * @throws \RuntimeException on a circular reference, depth excess, or
     *                           if the directory/parent is not found
     */
    public function move(
        string  $dirUuid,
        string  $orgId,
        ?string $newParentUuid,
        string  $userId,
    ): void {
        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }

        $this->assertCan('write', $userId, 'directory', $dir->id, $orgId);

        // Determine the new parent
        $newParent = null;
        if ($newParentUuid !== null) {
            $newParent = Directory::findByUuid($newParentUuid);
            if ($newParent === null || $newParent->organizationId !== $orgId) {
                throw new \RuntimeException(__('ui.backend.directory.new_parent_not_found'));
            }
            // Protection against circular references
            if ($newParent->uuid === $dir->uuid) {
                throw new \RuntimeException(__('ui.backend.directory.cannot_move_into_self'));
            }
            if (\str_starts_with($newParent->path . '/', $dir->path . '/')) {
                throw new \RuntimeException(__('ui.backend.directory.cannot_move_into_descendant'));
            }
            if ($newParent->depth >= self::MAX_DEPTH) {
                throw new \RuntimeException(
                    __('ui.backend.directory.max_depth_reached', ['levels' => (string) (self::MAX_DEPTH + 1)])
                );
            }
        }

        // Nothing changes - skip
        if ($dir->parentId === ($newParent?->id)) {
            return;
        }

        $newDepth    = $newParent !== null ? $newParent->depth + 1 : 0;
        $newBasePath = $newParent !== null
            ? $newParent->path . '/' . $dir->uuid
            : '/' . $dir->uuid;
        $oldBasePath = $dir->path;
        $depthDelta  = $newDepth - $dir->depth;
        $now         = now()->format('Y-m-d H:i:s');

        $db = Database::getInstance();
        $db->transaction(function () use (
            $db, $dir, $newParent, $newDepth, $newBasePath, $oldBasePath, $depthDelta, $now
        ): void {
            // Update the directory itself
            $db->update('directories', [
                'parent_id'  => $newParent !== null ? (int) $newParent->id : null,
                'depth'      => $newDepth,
                'path'       => $newBasePath,
                'updated_at' => $now,
            ], ['id' => $dir->id]);

            // Update all descendants (recalculate path and depth)
            foreach (Directory::findDescendants($oldBasePath) as $desc) {
                $newDescPath = $newBasePath . \substr($desc->path, \strlen($oldBasePath));
                $db->update('directories', [
                    'depth'      => $desc->depth + $depthDelta,
                    'path'       => $newDescPath,
                    'updated_at' => $now,
                ], ['id' => $desc->id]);
            }
        });
    }

    // ------------------------------------------------------------------ //
    //  Deletion                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Soft-delete a directory and all its descendants (sets deleted_at).
     *
     * @throws AuthException     if the user is not the directory owner
     * @throws \RuntimeException if not found
     */
    public function delete(string $dirUuid, string $orgId, string $userId): void
    {
        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }

        $this->assertOwnedBy($dir, $userId);

        $now = now()->format('Y-m-d H:i:s');
        $db  = Database::getInstance();

        $db->transaction(function () use ($db, $dir, $now): void {
            // Delete descendants first (by materialized path)
            foreach (Directory::findDescendants($dir->path) as $desc) {
                $db->query(
                    'UPDATE secrets SET deleted_at = ? WHERE directory_id = ? AND deleted_at IS NULL',
                    [$now, (int) $desc->id]
                );
                $db->update('directories', ['deleted_at' => $now], ['id' => $desc->id]);
            }
            // Delete the directory itself
            $db->query(
                'UPDATE secrets SET deleted_at = ? WHERE directory_id = ? AND deleted_at IS NULL',
                [$now, (int) $dir->id]
            );
            $db->update('directories', ['deleted_at' => $now], ['id' => $dir->id]);
        });
    }

    public function transferOwnership(string $dirUuid, string $orgId, string $newOwnerId, string $requesterId): Directory
    {
        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }

        $this->assertOwnedBy($dir, $requesterId, 'ui.backend.directory.owner_transfer_required');

        if ($this->organizationService->getMemberRole($orgId, $newOwnerId) === null) {
            throw new \RuntimeException(__('ui.backend.directory.new_owner_must_be_member'));
        }

        if ($dir->ownerUserId === $newOwnerId) {
            return $dir;
        }

        Database::getInstance()->transaction(function () use ($dir, $newOwnerId): void {
            $dir->update([
                'owner_user_id' => (int) $newOwnerId,
                'updated_at' => now()->format('Y-m-d H:i:s'),
            ]);
            $this->permissionService->removeUserRulesForResource('directory', $dir->id, $newOwnerId);
        });

        return Directory::findByUuid($dirUuid)
            ?? throw new \RuntimeException(__('ui.backend.directory.not_found'));
    }

    /** @return \Passway\Models\UserPermission[] */
    public function listAcl(string $dirUuid, string $orgId, string $requesterId): array
    {
        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }

        $this->assertOwnedBy($dir, $requesterId, 'ui.backend.directory.owner_acl_required');
        return $this->permissionService->listForResource('directory', $dir->id);
    }

    /**
     * @param array<int, array{subject_type:string,subject_id:string,read:?string,write:?string}> $rules
     * @return \Passway\Models\UserPermission[]
     */
    public function replaceAcl(string $dirUuid, string $orgId, string $requesterId, array $rules): array
    {
        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }

        $this->assertOwnedBy($dir, $requesterId, 'ui.backend.directory.owner_acl_required');
        return $this->permissionService->replaceForResource('directory', $dir->id, $rules, $requesterId);
    }

    /** @return array{default_read_access:string,default_write_access:string} */
    public function getAccessPolicy(string $dirUuid, string $orgId, string $requesterId): array
    {
        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }

        $this->assertOwnedBy($dir, $requesterId, 'ui.backend.directory.owner_acl_required');

        return [
            'default_read_access' => $dir->defaultReadAccess,
            'default_write_access' => $dir->defaultWriteAccess,
        ];
    }

    /** @return array{default_read_access:string,default_write_access:string} */
    public function updateAccessPolicy(
        string $dirUuid,
        string $orgId,
        string $requesterId,
        string $defaultReadAccess,
        string $defaultWriteAccess,
    ): array {
        $dir = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }

        $this->assertOwnedBy($dir, $requesterId, 'ui.backend.directory.owner_acl_required');
        $defaultReadAccess = $this->normalizeAccessPolicy($defaultReadAccess);
        $defaultWriteAccess = $this->normalizeAccessPolicy($defaultWriteAccess);

        $dir->update([
            'default_read_access' => $defaultReadAccess,
            'default_write_access' => $defaultWriteAccess,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $updated = Directory::findByUuid($dirUuid)
            ?? throw new \RuntimeException(__('ui.backend.directory.not_found'));

        return [
            'default_read_access' => $updated->defaultReadAccess,
            'default_write_access' => $updated->defaultWriteAccess,
        ];
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                    //
    // ------------------------------------------------------------------ //

    /**
     * @throws AuthException (code 403)
     */
    private function assertHasPermission(string $orgId, string $userId, string $minRole): void
    {
        $apiKey = AuthContext::getApiKey();
        if ($apiKey !== null) {
            $permission = match ($minRole) {
                'reader' => 'read',
                default => 'write',
            };

            if (!$this->getApiKeyAccessService()->canOrganization($apiKey->id, $orgId, $permission)) {
                throw new AuthException(
                    __('ui.backend.directory.requires_role', ['role' => $minRole]),
                    403
                );
            }

            return;
        }

        if (!$this->organizationService->hasPermission($orgId, $userId, $minRole)) {
            throw new AuthException(
                __('ui.backend.directory.requires_role', ['role' => $minRole]),
                403
            );
        }
    }

    /**
     * @throws AuthException (code 403)
     */
    private function assertCan(
        string $permission,
        string $userId,
        string $resourceType,
        string $resourceId,
        string $orgId,
    ): void {
        if (!$this->permissionService->can($permission, $userId, $resourceType, $resourceId, $orgId)) {
            throw new AuthException(
                __('ui.backend.directory.access_permission_required', ['permission' => $permission]),
                403
            );
        }
    }

    /**
     * @throws AuthException (code 403)
     */
    private function assertOwnedBy(Directory $directory, string $userId, string $messageKey = 'ui.backend.directory.owner_delete_required'): void
    {
        if ($directory->ownerUserId !== $userId) {
            throw new AuthException(
                __($messageKey),
                403
            );
        }
    }

    private function getApiKeyAccessService(): ApiKeyAccessService
    {
        return $this->apiKeyAccessService ?? new ApiKeyAccessService();
    }

    private function normalizeAccessPolicy(string $value): string
    {
        $value = \trim($value);
        if (!\in_array($value, PermissionService::VALID_ACCESS_POLICIES, true)) {
            throw new \InvalidArgumentException(__('ui.backend.permission.invalid_access_policy'));
        }

        return $value;
    }
}
