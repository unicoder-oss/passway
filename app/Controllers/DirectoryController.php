<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\ApiKey;
use Passway\Models\Directory;
use Passway\Models\Group;
use Passway\Models\Organization;
use Passway\Models\OrganizationMember;
use Passway\Models\User;
use Passway\Models\UserPermission;
use Passway\Services\DirectoryService;

/**
 * Контроллер каталогов организации.
 *
 * GET    /api/v1/organizations/:uuid/directories            — список всех (плоский)
 * POST   /api/v1/organizations/:uuid/directories            — создать
 * GET    /api/v1/organizations/:uuid/directories/:dirUuid  — детали
 * PATCH  /api/v1/organizations/:uuid/directories/:dirUuid  — переименовать / переместить
 * DELETE /api/v1/organizations/:uuid/directories/:dirUuid  — мягкое удаление
     * GET    /api/v1/organizations/:uuid/directories/:dirUuid/acl — exact ACL
     * PUT    /api/v1/organizations/:uuid/directories/:dirUuid/acl — replace exact ACL
     * GET    /api/v1/organizations/:uuid/directories/:dirUuid/access-policy — effective default policy
     * PUT    /api/v1/organizations/:uuid/directories/:dirUuid/access-policy — update default policy
     * POST   /api/v1/organizations/:uuid/directories/:dirUuid/owner — transfer ownership
 */
final class DirectoryController
{
    public function __construct(
        private readonly DirectoryService $directoryService,
    ) {}

    // ------------------------------------------------------------------ //
    //  GET /api/v1/organizations/:uuid/directories                        //
    // ------------------------------------------------------------------ //

    public function list(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        try {
            $dirs = $this->directoryService->listAll($org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        // Собираем карту id → uuid для быстрого разрешения parent_uuid
        $idToUuid = [];
        foreach ($dirs as $dir) {
            $idToUuid[$dir->id] = $dir->uuid;
        }

        return Response::success(\array_map(
            fn($d) => $this->serializeDir($d, $idToUuid),
            $dirs
        ));
    }

    // ------------------------------------------------------------------ //
    //  POST /api/v1/organizations/:uuid/directories                       //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $user       = AuthContext::requireUser();
        $org        = $this->findOrgOrFail($request);
        $name       = \trim((string) ($request->input('name') ?? ''));
        $parentUuid = $request->input('parent_uuid');
        $parentUuid = \is_string($parentUuid) && $parentUuid !== '' ? $parentUuid : null;
        $defaultReadAccess = \trim((string) ($request->input('default_read_access') ?? 'inherit'));
        $defaultWriteAccess = \trim((string) ($request->input('default_write_access') ?? 'inherit'));

        if ($name === '') {
            return Response::validationError(['name' => [__('ui.backend.common.name_required')]]);
        }

        try {
            $dir = $this->directoryService->create(
                $org->id,
                $parentUuid,
                $name,
                $user->id,
                $defaultReadAccess,
                $defaultWriteAccess,
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeDir($dir), 201);
    }

    // ------------------------------------------------------------------ //
    //  GET /api/v1/organizations/:uuid/directories/:dirUuid               //
    // ------------------------------------------------------------------ //

    public function show(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        try {
            $dir = $this->directoryService->findInOrg($dirUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serializeDir($dir));
    }

    // ------------------------------------------------------------------ //
    //  PATCH /api/v1/organizations/:uuid/directories/:dirUuid             //
    // ------------------------------------------------------------------ //

    /**
     * Переименовать и/или переместить каталог.
     * Принимает: name (строка), parent_uuid (строка или пустая строка = корень).
     * Хотя бы одно из полей обязательно.
     */
    public function update(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        $name       = $request->input('name');
        $parentUuid = $request->input('parent_uuid');  // null = не трогать; '' = корень; uuid = новый родитель

        if ($name === null && $parentUuid === null) {
            return Response::validationError([
                'name' => [__('ui.backend.directory.at_least_one_name_or_parent')],
            ]);
        }

        try {
            // Переименование
            if ($name !== null) {
                $this->directoryService->rename($dirUuid, $org->id, (string) $name, $user->id);
            }

            // Перемещение
            if ($parentUuid !== null) {
                $newParent = \is_string($parentUuid) && $parentUuid !== '' ? $parentUuid : null;
                $this->directoryService->move($dirUuid, $org->id, $newParent, $user->id);
            }

            // Перезагрузить после всех изменений
            $dir = $this->directoryService->findInOrg($dirUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeDir($dir));
    }

    // ------------------------------------------------------------------ //
    //  DELETE /api/v1/organizations/:uuid/directories/:dirUuid            //
    // ------------------------------------------------------------------ //

    public function delete(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        try {
            $this->directoryService->delete($dirUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success();
    }

    public function acl(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        try {
            $rules = $this->directoryService->listAcl($dirUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success(['rules' => $this->serializeAclRules($rules)]);
    }

    public function replaceAcl(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $rulesInput = $request->input('rules');

        if (!\is_array($rulesInput)) {
            return Response::validationError(['rules' => [__('ui.backend.permission.rules_array_required')]]);
        }

        try {
            $rules = $this->directoryService->replaceAcl(
                $dirUuid,
                $org->id,
                $user->id,
                $this->normalizeAclRules($rulesInput, $org->id)
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['rules' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success(['rules' => $this->serializeAclRules($rules)]);
    }

    public function transferOwnership(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $userUuid = \trim((string) ($request->input('user_uuid') ?? ''));

        if ($userUuid === '') {
            return Response::validationError(['user_uuid' => [__('ui.backend.group.user_uuid_required')]]);
        }

        $targetUser = User::findByUuid($userUuid);
        if ($targetUser === null) {
            return Response::notFound(__('ui.backend.common.user_not_found'));
        }

        try {
            $dir = $this->directoryService->transferOwnership($dirUuid, $org->id, $targetUser->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeDir($dir));
    }

    public function accessPolicy(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        try {
            $policy = $this->directoryService->getAccessPolicy($dirUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($policy);
    }

    public function updateAccessPolicy(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $defaultReadAccess = \trim((string) ($request->input('default_read_access') ?? 'inherit'));
        $defaultWriteAccess = \trim((string) ($request->input('default_write_access') ?? 'inherit'));

        try {
            $policy = $this->directoryService->updateAccessPolicy(
                $dirUuid,
                $org->id,
                $user->id,
                $defaultReadAccess,
                $defaultWriteAccess,
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['default_read_access' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($policy);
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    private function findOrgOrFail(Request $request): Organization
    {
        $uuid = $request->routeParam('uuid');
        $org  = Organization::findByUuid((string) $uuid);
        if ($org === null) {
            throw new \RuntimeException(__('ui.backend.common.organization_not_found'));
        }
        return $org;
    }

    /**
     * Сериализовать каталог в массив для JSON-ответа.
     *
     * @param array<string, string> $idToUuid Карта id→uuid для быстрого разрешения parent_uuid.
     *                                        Если не передана — выполняется дополнительный запрос.
     * @return array<string, mixed>
     */
    private function serializeDir(Directory $dir, array $idToUuid = []): array
    {
        if ($dir->parentId !== null) {
            $parentUuid = $idToUuid[$dir->parentId]
                ?? Directory::findById($dir->parentId)?->uuid;
        } else {
            $parentUuid = null;
        }

        return [
            'uuid'        => $dir->uuid,
            'name'        => $dir->name,
            'owner_user_uuid' => $dir->ownerUserId !== null ? User::findById($dir->ownerUserId)?->uuid : null,
            'default_read_access' => $dir->defaultReadAccess,
            'default_write_access' => $dir->defaultWriteAccess,
            'parent_uuid' => $parentUuid,
            'depth'       => $dir->depth,
            'path'        => $dir->path,
            'created_at'  => $dir->createdAt,
            'updated_at'  => $dir->updatedAt,
        ];
    }

    /**
     * @param array<int, mixed> $rulesInput
     * @return array<int, array{subject_type:string,subject_id:string,read:?string,write:?string}>
     */
    private function normalizeAclRules(array $rulesInput, string $orgId): array
    {
        $rules = [];

        foreach ($rulesInput as $rule) {
            if (!\is_array($rule)) {
                throw new \InvalidArgumentException(__('ui.backend.permission.invalid_rule_shape'));
            }

            $subjectType = \trim((string) ($rule['subject_type'] ?? ''));
            $subjectUuid = \trim((string) ($rule['subject_uuid'] ?? ''));
            if ($subjectType === '' || $subjectUuid === '') {
                throw new \InvalidArgumentException(__('ui.backend.permission.subject_uuid_required'));
            }

            $subjectId = match ($subjectType) {
                'user' => $this->resolveUserSubjectId($subjectUuid, $orgId),
                'group' => $this->resolveGroupSubjectId($subjectUuid, $orgId),
                'api_key' => $this->resolveApiKeySubjectId($subjectUuid, $orgId),
                default => null,
            };

            if ($subjectId === null) {
                throw new \InvalidArgumentException(__('ui.backend.common.subject_not_found'));
            }

            $rules[] = [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'read' => isset($rule['read']) ? (string) $rule['read'] : null,
                'write' => isset($rule['write']) ? (string) $rule['write'] : null,
            ];
        }

        return $rules;
    }

    /** @param UserPermission[] $rules */
    private function serializeAclRules(array $rules): array
    {
        $grouped = [];

        foreach ($rules as $rule) {
            $key = $rule->subjectType . ':' . $rule->subjectId;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'subject_type' => $rule->subjectType,
                    'subject_uuid' => null,
                    'subject_name' => null,
                    'subject_email' => null,
                    'read' => null,
                    'write' => null,
                ];

                if ($rule->subjectType === 'user') {
                    $subject = User::findById($rule->subjectId);
                    $grouped[$key]['subject_uuid'] = $subject?->uuid;
                    $grouped[$key]['subject_name'] = $subject !== null ? display_name_for_user($subject) : null;
                    $grouped[$key]['subject_email'] = $subject?->email;
                } elseif ($rule->subjectType === 'group') {
                    $subject = Group::findById($rule->subjectId);
                    $grouped[$key]['subject_uuid'] = $subject?->uuid;
                    $grouped[$key]['subject_name'] = $subject?->name;
                } elseif ($rule->subjectType === 'api_key') {
                    $subject = ApiKey::findById($rule->subjectId);
                    $grouped[$key]['subject_uuid'] = $subject?->uuid;
                    $grouped[$key]['subject_name'] = $subject?->name;
                }
            }

            $grouped[$key][$rule->permission] = $rule->isDeny ? 'deny' : 'allow';
        }

        return \array_values($grouped);
    }

    private function resolveUserSubjectId(string $subjectUuid, string $orgId): ?string
    {
        $user = User::findByUuid($subjectUuid);
        if ($user === null) {
            return null;
        }

        return OrganizationMember::findByOrgAndUser($orgId, $user->id)?->userId;
    }

    private function resolveGroupSubjectId(string $subjectUuid, string $orgId): ?string
    {
        $group = Group::findByUuid($subjectUuid);
        if ($group === null || $group->organizationId !== $orgId) {
            return null;
        }

        return $group->id;
    }

    private function resolveApiKeySubjectId(string $subjectUuid, string $orgId): ?string
    {
        $apiKey = ApiKey::findByUuid($subjectUuid);
        if ($apiKey === null || $apiKey->organizationId !== $orgId) {
            return null;
        }

        return $apiKey->id;
    }
}
