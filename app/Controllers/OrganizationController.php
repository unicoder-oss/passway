<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\Organization;
use Passway\Models\OrganizationMember;
use Passway\Models\User;
use Passway\Services\ApiKeyAccessService;
use Passway\Services\OrganizationService;

/**
 * Контроллер организаций.
 *
 * POST   /api/v1/organizations                            — создать
 * GET    /api/v1/organizations                            — список своих
 * GET    /api/v1/organizations/:uuid                      — детали
 * GET    /api/v1/organizations/:uuid/members              — список участников
 * PATCH  /api/v1/organizations/:uuid/members/:userUuid   — изменить роль
 * DELETE /api/v1/organizations/:uuid/members/:userUuid   — удалить участника
 * POST   /api/v1/organizations/:uuid/transfer             — передать владение
 */
final class OrganizationController
{
    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly ApiKeyAccessService $apiKeyAccessService,
    ) {}

    // ------------------------------------------------------------------ //
    //  POST /api/v1/organizations                                         //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $name = \trim((string) ($request->input('name') ?? ''));

        if ($name === '') {
            return Response::validationError(['name' => [__('ui.backend.common.name_required')]]);
        }

        try {
            $org = $this->organizationService->create($name, $user->id);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 409);
        }

        return Response::success($this->serializeOrg($org), 201);
    }

    // ------------------------------------------------------------------ //
    //  GET /api/v1/organizations                                          //
    // ------------------------------------------------------------------ //

    public function list(Request $request): Response
    {
        $user  = AuthContext::requireUser();
        $orgs  = $this->organizationService->getForUser($user->id);

        return Response::success(\array_map(
            fn($o) => $this->serializeOrg($o),
            $orgs
        ));
    }

    // ------------------------------------------------------------------ //
    //  GET /api/v1/organizations/:uuid                                    //
    // ------------------------------------------------------------------ //

    public function show(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        if (AuthContext::isApiKeyRequest()) {
            $apiKey = AuthContext::getApiKey();
            if ($apiKey === null || !$this->apiKeyAccessService->canOrganization($apiKey->id, $org->id, 'read')) {
                return Response::forbidden(__('ui.backend.organization.not_member'));
            }

            return Response::success($this->serializeOrg($org));
        }

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'observer')) {
            return Response::forbidden(__('ui.backend.organization.not_member'));
        }

        $role = $this->organizationService->getMemberRole($org->id, $user->id);

        return Response::success(\array_merge(
            $this->serializeOrg($org),
            ['your_role' => $role]
        ));
    }

    // ------------------------------------------------------------------ //
    //  GET /api/v1/organizations/:uuid/members                            //
    // ------------------------------------------------------------------ //

    public function listMembers(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);

        if (!$this->organizationService->hasPermission($org->id, $user->id, 'observer')) {
            return Response::forbidden(__('ui.backend.organization.not_member'));
        }

        $members = $this->organizationService->listMembers($org->id);

        return Response::success(\array_map(
            fn(OrganizationMember $m) => $this->serializeMember($m),
            $members
        ));
    }

    // ------------------------------------------------------------------ //
    //  PATCH /api/v1/organizations/:uuid/members/:userUuid                //
    // ------------------------------------------------------------------ //

    public function updateMember(Request $request): Response
    {
        $requester = AuthContext::requireUser();
        $org       = $this->findOrgOrFail($request);
        $targetUser = $this->findMemberUserOrFail($request, $org->id);

        $newRole = \trim((string) ($request->input('role') ?? ''));
        if ($newRole === '') {
            return Response::validationError(['role' => [__('ui.backend.common.role_required')]]);
        }

        try {
            $this->organizationService->updateMemberRole(
                $org->id, $targetUser->id, $newRole, $requester->id
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['role' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 404);
        }

        return Response::success();
    }

    // ------------------------------------------------------------------ //
    //  DELETE /api/v1/organizations/:uuid/members/:userUuid               //
    // ------------------------------------------------------------------ //

    public function removeMember(Request $request): Response
    {
        $requester  = AuthContext::requireUser();
        $org        = $this->findOrgOrFail($request);
        $targetUser = $this->findMemberUserOrFail($request, $org->id);

        try {
            $this->organizationService->removeMember($org->id, $targetUser->id, $requester->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 404);
        }

        return Response::success();
    }

    // ------------------------------------------------------------------ //
    //  POST /api/v1/organizations/:uuid/transfer                          //
    // ------------------------------------------------------------------ //

    public function transferOwnership(Request $request): Response
    {
        $requester   = AuthContext::requireUser();
        $org         = $this->findOrgOrFail($request);
        $newOwnerUuid = \trim((string) ($request->input('user_uuid') ?? ''));

        if ($newOwnerUuid === '') {
            return Response::validationError(['user_uuid' => [__('ui.backend.group.user_uuid_required')]]);
        }

        $newOwner = User::findByUuid($newOwnerUuid);
        if ($newOwner === null) {
            return Response::notFound(__('ui.backend.common.user_not_found'));
        }

        try {
            $this->organizationService->transferOwnership($org->id, $newOwner->id, $requester->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 400);
        }

        return Response::success();
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    private function findOrgOrFail(Request $request): Organization
    {
        $uuid = $request->routeParam('uuid');
        $org  = Organization::findByUuid((string) $uuid);
        if ($org === null) {
            // Бросаем 404 — перехватится в Application::handleException()
            throw new \RuntimeException(__('ui.backend.common.organization_not_found'));
        }
        return $org;
    }

    private function findMemberUserOrFail(Request $request, string $orgId): User
    {
        $userUuid = $request->routeParam('userUuid');
        $user     = User::findByUuid((string) $userUuid);
        if ($user === null) {
            throw new \RuntimeException(__('ui.backend.common.user_not_found'));
        }
        $member = OrganizationMember::findByOrgAndUser($orgId, $user->id);
        if ($member === null) {
            throw new \RuntimeException(__('ui.backend.common.user_not_member_org'));
        }
        return $user;
    }

    /** @return array<string, mixed> */
    private function serializeOrg(Organization $org): array
    {
        return [
            'uuid'       => $org->uuid,
            'name'       => $org->name,
            'slug'       => $org->slug,
            'is_active'  => $org->isActive,
            'created_at' => $org->createdAt,
            'updated_at' => $org->updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeMember(OrganizationMember $m): array
    {
        $user = User::findById($m->userId);
        return [
            'user_uuid'  => $user?->uuid,
            'email'      => $user?->email,
            'role'       => $m->role,
            'joined_at'  => $m->joinedAt,
        ];
    }
}
