<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\Group;
use Passway\Models\GroupMember;
use Passway\Models\Organization;
use Passway\Services\GroupService;

/**
 * Контроллер групп пользователей организации.
 *
 * GET    /api/v1/organizations/:uuid/groups                           — список групп
 * POST   /api/v1/organizations/:uuid/groups                           — создать группу
 * GET    /api/v1/organizations/:uuid/groups/:grpUuid                  — показать группу
 * DELETE /api/v1/organizations/:uuid/groups/:grpUuid                  — удалить группу
 * GET    /api/v1/organizations/:uuid/groups/:grpUuid/members          — участники группы
 * POST   /api/v1/organizations/:uuid/groups/:grpUuid/members          — добавить участника
 * DELETE /api/v1/organizations/:uuid/groups/:grpUuid/members/:userUuid — удалить участника
 */
final class GroupController
{
    public function __construct(
        private readonly GroupService $groupService,
    ) {}

    // ------------------------------------------------------------------ //
    //  GET .../groups                                                     //
    // ------------------------------------------------------------------ //

    public function list(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        try {
            $groups = $this->groupService->list($org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success(\array_map(fn($g) => $this->serializeGroup($g), $groups));
    }

    // ------------------------------------------------------------------ //
    //  POST .../groups                                                    //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        $name        = \trim((string) ($request->input('name') ?? ''));
        $description = $request->input('description') !== null
            ? (string) $request->input('description') : null;

        if ($name === '') {
            return Response::validationError(['name' => [__('ui.backend.common.name_required')]]);
        }

        try {
            $group = $this->groupService->create($org->id, $name, $description, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeGroup($group), 201);
    }

    // ------------------------------------------------------------------ //
    //  GET .../groups/:grpUuid                                            //
    // ------------------------------------------------------------------ //

    public function show(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $grpUuid = (string) $request->routeParam('grpUuid');

        try {
            $group = $this->groupService->findInOrg($grpUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serializeGroup($group));
    }

    // ------------------------------------------------------------------ //
    //  DELETE .../groups/:grpUuid                                         //
    // ------------------------------------------------------------------ //

    public function delete(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $grpUuid = (string) $request->routeParam('grpUuid');

        try {
            $this->groupService->delete($grpUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success();
    }

    // ------------------------------------------------------------------ //
    //  GET .../groups/:grpUuid/members                                    //
    // ------------------------------------------------------------------ //

    public function listMembers(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $grpUuid = (string) $request->routeParam('grpUuid');

        try {
            $members = $this->groupService->listMembers($grpUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success(\array_map(fn($m) => $this->serializeMember($m), $members));
    }

    // ------------------------------------------------------------------ //
    //  POST .../groups/:grpUuid/members                                   //
    // ------------------------------------------------------------------ //

    public function addMember(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $grpUuid = (string) $request->routeParam('grpUuid');

        $userUuid = \trim((string) ($request->input('user_uuid') ?? ''));
        if ($userUuid === '') {
            return Response::validationError(['user_uuid' => [__('ui.backend.group.user_uuid_required')]]);
        }

        $targetUser = \Passway\Models\User::findByUuid($userUuid);
        if ($targetUser === null) {
            return Response::notFound(__('ui.backend.common.user_not_found'));
        }

        try {
            $member = $this->groupService->addMember($grpUuid, $targetUser->id, $user->id, $org->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeMember($member), 201);
    }

    // ------------------------------------------------------------------ //
    //  DELETE .../groups/:grpUuid/members/:userUuid                       //
    // ------------------------------------------------------------------ //

    public function removeMember(Request $request): Response
    {
        $user     = AuthContext::requireUser();
        $org      = $this->findOrgOrFail($request);
        $grpUuid  = (string) $request->routeParam('grpUuid');
        $userUuid = (string) $request->routeParam('userUuid');

        $targetUser = \Passway\Models\User::findByUuid($userUuid);
        if ($targetUser === null) {
            return Response::notFound(__('ui.backend.common.user_not_found'));
        }

        try {
            $this->groupService->removeMember($grpUuid, $targetUser->id, $user->id, $org->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
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
            throw new \RuntimeException(__('ui.backend.common.organization_not_found'));
        }
        return $org;
    }

    /** @return array<string, mixed> */
    private function serializeGroup(Group $g): array
    {
        return [
            'uuid'        => $g->uuid,
            'name'        => $g->name,
            'description' => $g->description,
            'created_at'  => $g->createdAt,
            'updated_at'  => $g->updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeMember(GroupMember $m): array
    {
        return [
            'group_id' => $m->groupId,
            'user_id'  => $m->userId,
            'added_by' => $m->addedBy,
            'added_at' => $m->addedAt,
        ];
    }
}
