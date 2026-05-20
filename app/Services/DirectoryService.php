<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\AuthContext;
use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Directory;

/**
 * Сервис управления каталогами.
 *
 * Авторизация (через PermissionService, который включает ACL и role fallback):
 *   read                  — list, show
 *   write                 — create, rename, move
 *   delete                — только владелец каталога
 *   create_subdirectories — legacy alias для write на родителе
 *
 * Структура пути (materialized path):
 *   Корневой каталог : /{uuid}
 *   Дочерний        : /{parent_uuid}/.../{uuid}
 */
final class DirectoryService
{
    /**
     * Максимальная глубина вложенности (depth 0..19 = 20 уровней).
     * depth 0 — корневой каталог.
     */
    public const MAX_DEPTH = 19;

    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly PermissionService   $permissionService,
        private readonly ?ApiKeyAccessService $apiKeyAccessService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Создание                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Создать каталог в организации.
     *
     * @param string      $orgId      ID организации
     * @param string|null $parentUuid UUID родителя (null = корневой каталог)
     * @param string      $name       Имя каталога
     * @param string      $userId     ID создателя
     *
     * @throws AuthException             если нет прав (требуется editor+)
     * @throws \InvalidArgumentException при пустом/слишком длинном имени
     * @throws \RuntimeException         если родитель не найден или превышена глубина
     */
    public function create(
        string  $orgId,
        ?string $parentUuid,
        string  $name,
        string  $userId,
    ): Directory {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ui.backend.directory.name_empty'));
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.directory.name_too_long'));
        }

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
            // В новой модели вложенные каталоги регулируются правом write на родителе.
            $this->assertCan('write', $userId, 'directory', $parent->id, $orgId);
        } else {
            // Корневой каталог — org-level editor
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
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return Directory::findByUuid($uuid)
            ?? throw new \RuntimeException(__('ui.backend.directory.failed_load_created'));
    }

    // ------------------------------------------------------------------ //
    //  Чтение                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Список всех каталогов организации (плоский, сортировка по depth/path).
     *
     * @return Directory[]
     * @throws AuthException если нет прав (требуется reader+)
     */
    public function listAll(string $orgId, string $userId): array
    {
        $this->assertHasPermission($orgId, $userId, 'reader');
        return Directory::findByOrgId($orgId);
    }

    /**
     * Прямые дочерние каталоги (или корневые, если parentUuid = null).
     *
     * @return Directory[]
     * @throws AuthException если нет прав
     * @throws \RuntimeException если родитель не найден в данной организации
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

        return Directory::findChildren($orgId, $parentId);
    }

    /**
     * Найти каталог по UUID с проверкой принадлежности организации.
     *
     * @throws AuthException если нет прав
     * @throws \RuntimeException если не найден или принадлежит другой орг.
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
    //  Переименование                                                     //
    // ------------------------------------------------------------------ //

    /**
     * Переименовать каталог.
     *
     * @throws AuthException             если нет прав (требуется editor+)
     * @throws \InvalidArgumentException при пустом/слишком длинном имени
     * @throws \RuntimeException         если не найден
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
    //  Перемещение                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Переместить каталог (и все его потомки) к новому родителю.
     * Если newParentUuid = null — переместить в корень организации.
     *
     * @throws AuthException     если нет прав (требуется editor+)
     * @throws \RuntimeException при кольцевой ссылке, превышении глубины или
     *                           если каталог/родитель не найден
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

        // Определить нового родителя
        $newParent = null;
        if ($newParentUuid !== null) {
            $newParent = Directory::findByUuid($newParentUuid);
            if ($newParent === null || $newParent->organizationId !== $orgId) {
                throw new \RuntimeException(__('ui.backend.directory.new_parent_not_found'));
            }
            // Защита от кольцевых ссылок
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

        // Ничего не меняется — пропустить
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
            // Обновить сам каталог
            $db->update('directories', [
                'parent_id'  => $newParent !== null ? (int) $newParent->id : null,
                'depth'      => $newDepth,
                'path'       => $newBasePath,
                'updated_at' => $now,
            ], ['id' => $dir->id]);

            // Обновить всех потомков (пересчитать path и depth)
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
    //  Удаление                                                           //
    // ------------------------------------------------------------------ //

    /**
     * Мягкое удаление каталога и всех его потомков (устанавливает deleted_at).
     *
     * @throws AuthException     если пользователь не владелец каталога
     * @throws \RuntimeException если не найден
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
            // Сначала удалить потомков (по materialized path)
            foreach (Directory::findDescendants($dir->path) as $desc) {
                $db->query(
                    'UPDATE secrets SET deleted_at = ? WHERE directory_id = ? AND deleted_at IS NULL',
                    [$now, (int) $desc->id]
                );
                $db->update('directories', ['deleted_at' => $now], ['id' => $desc->id]);
            }
            // Удалить сам каталог
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

        $dir->update([
            'owner_user_id' => (int) $newOwnerId,
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

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

    // ------------------------------------------------------------------ //
    //  Вспомогательные                                                    //
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
}
