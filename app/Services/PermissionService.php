<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\AuthContext;
use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Directory;
use Passway\Models\UserPermission;

/**
 * Сервис тонкогранулированного контроля доступа на уровне каталогов.
 *
 * Приоритет проверки:
 *   1. Роль в организации (owner/admin/moderator+ покрывают write/delete,
 *      observer+ покрывает read) — прямой обход fine-grained проверки.
 *   2. Явный запрет (is_deny=true) для пользователя или его группы
 *      на данном ресурсе.
 *   3. Явное разрешение для пользователя или его группы.
 *   4. Наследование от ближайшего предка (каталога выше).
 *   5. Нет совпадений → доступ запрещён.
 */
final class PermissionService
{
    public const VALID_PERMISSIONS = UserPermission::VALID_PERMISSIONS;

    /**
     * Минимальная роль в организации, достаточная для данного права.
     * Пользователи с этой ролью проходят проверку без fine-grained анализа.
     */
    private const PERM_TO_ORG_ROLE = [
        'read'                  => 'observer',
        'write'                 => 'moderator',
        'delete'                => 'moderator',
        'create_subdirectories' => 'moderator',
    ];

    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly GroupService        $groupService,
        private readonly ?ApiKeyAccessService $apiKeyAccessService = null,
        private readonly ?AuditService       $auditService = null,
    ) {}

    // ------------------------------------------------------------------ //
    //  Проверка доступа                                                   //
    // ------------------------------------------------------------------ //

    /**
     * Проверить, имеет ли пользователь указанное право на ресурс.
     *
     * @param string $permission   read|write|delete|create_subdirectories
     * @param string $userId       ID пользователя
     * @param string $resourceType directory|secret
     * @param string $resourceId   ID ресурса (числовой)
     * @param string $orgId        ID организации
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

        // 1. Роль в организации — ранний выход
        $minRole = self::PERM_TO_ORG_ROLE[$permission] ?? 'moderator';
        if ($this->organizationService->hasPermission($orgId, $userId, $minRole)) {
            return true;
        }

        // Пользователь должен хотя бы состоять в организации
        if ($this->organizationService->getMemberRole($orgId, $userId) === null) {
            return false;
        }

        // 2–4. Fine-grained: пользователь/группы + наследование
        return $this->checkFineGrained($permission, $userId, $resourceType, $resourceId, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  Управление записями прав                                           //
    // ------------------------------------------------------------------ //

    /**
     * Выдать разрешение или запрет субъекту на ресурс.
     * Если запись для той же тройки (subject, resource, permission) уже есть — обновляет её.
     *
     * @throws AuthException             если requesterId не имеет admin+
     * @throws \InvalidArgumentException при невалидных параметрах
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

        // Upsert: обновить существующую запись или вставить новую
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
     * Отозвать право по ID.
     *
     * @throws AuthException     если нет прав (admin+)
     * @throws \RuntimeException если запись не найдена
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
     * Список всех прав на каталог (для управления).
     *
     * @return UserPermission[]
     * @throws AuthException если нет прав (admin+)
     */
    public function listForDirectory(string $dirId, string $requesterId, string $orgId): array
    {
        if (!$this->organizationService->hasPermission($orgId, $requesterId, 'admin')) {
            throw new AuthException(__('ui.backend.permission.requires_admin_view'), 403);
        }
        return UserPermission::findForResource('directory', $dirId);
    }

    // ------------------------------------------------------------------ //
    //  Вспомогательные                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Проверить тонкогранулированные права:
     * пользователь + его группы, с наследованием от предков.
     */
    private function checkFineGrained(
        string $permission,
        string $userId,
        string $resourceType,
        string $resourceId,
        string $orgId,
    ): bool {
        $groupIds      = $this->groupService->getUserGroupIds($userId, $orgId);
        $resourceChain = $this->buildResourceChain($resourceType, $resourceId);

        foreach ($resourceChain as $resId) {
            $result = $this->evalPermission($permission, $userId, $groupIds, $resourceType, $resId);
            if ($result !== null) {
                return $result;
            }
        }

        return false;
    }

    /**
     * Проверить права на конкретный ресурс (без наследования).
     * Возвращает true/false если нашли правило, null если правил нет.
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

        // Отфильтровать: нужное право и не просроченные
        $perms = \array_filter(
            $perms,
            fn(UserPermission $p) => $p->permission === $permission && $this->isActive($p)
        );

        // Явные запреты имеют приоритет над разрешениями
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

    /**
     * Построить цепочку ресурсов для проверки наследования
     * (от конкретного ресурса к корневому).
     *
     * Для каталогов: [dirId, parentId, grandparentId, ...].
     * Для остальных: [resourceId].
     *
     * @return string[]
     */
    private function buildResourceChain(string $resourceType, string $resourceId): array
    {
        if ($resourceType !== 'directory') {
            return [$resourceId];
        }

        $chain   = [];
        $current = Directory::findById($resourceId);

        while ($current !== null) {
            $chain[] = $current->id;
            $current = $current->parentId !== null
                ? Directory::findById($current->parentId)
                : null;
        }

        return $chain;
    }

    /**
     * Проверить, что право ещё не истекло.
     */
    private function isActive(UserPermission $perm): bool
    {
        if ($perm->expiresAt === null) {
            return true;
        }
        return \strtotime($perm->expiresAt) > \time();
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
