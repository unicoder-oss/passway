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
 * Сервис тонкогранулированного контроля доступа на уровне каталогов.
 *
 * Приоритет проверки:
 *   1. ACL на целевом ресурсе.
 *   2. ACL на ближайшем предке (для секретов: папка -> родительские папки).
 *   3. Fallback на роль в организации, если ACL-правил не найдено.
 *   4. Нет совпадений → доступ запрещён.
 */
final class PermissionService
{
    public const VALID_PERMISSIONS = UserPermission::VALID_PERMISSIONS;
    public const VALID_ACCESS_POLICIES = ['inherit', 'allow', 'deny'];

    /**
     * Минимальная роль в организации, достаточная для данного права.
     * Пользователи с этой ролью проходят проверку без fine-grained анализа.
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

        // Пользователь должен хотя бы состоять в организации
        if ($this->organizationService->getMemberRole($orgId, $userId) === null) {
            return false;
        }

        // 1–2. Fine-grained ACL на ресурсе и его предках
        $fineGrained = $this->checkFineGrained($permission, $userId, $resourceType, $resourceId, $orgId);
        if ($fineGrained !== null) {
            return $fineGrained;
        }

        $defaultPolicy = $this->checkDefaultPolicy($permission, $resourceType, $resourceId);
        if ($defaultPolicy !== null) {
            return $defaultPolicy;
        }

        // 3. Fallback на роль в организации
        $minRole = self::PERM_TO_ORG_ROLE[$permission] ?? 'editor';
        return $this->organizationService->hasPermission($orgId, $userId, $minRole);
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

    /**
     * @return UserPermission[]
     */
    public function listForResource(string $resourceType, string $resourceId): array
    {
        return \array_values(\array_filter(
            UserPermission::findForResource($resourceType, $resourceId),
            static fn(UserPermission $permission): bool => \in_array($permission->permission, ['read', 'write'], true)
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

    // ------------------------------------------------------------------ //
    //  Вспомогательные                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Проверить ACL пользователя/групп на ресурсе и его предках.
     */
    private function checkFineGrained(
        string $permission,
        string $userId,
        string $resourceType,
        string $resourceId,
        string $orgId,
    ): ?bool {
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
     * Построить цепочку ресурсов для проверки ACL
     * (от конкретного ресурса к корневому каталогу).
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
     * Проверить, что право ещё не истекло.
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
