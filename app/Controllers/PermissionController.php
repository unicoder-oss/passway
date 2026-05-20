<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\Directory;
use Passway\Models\Group;
use Passway\Models\Organization;
use Passway\Models\UserPermission;
use Passway\Services\PermissionService;

/**
 * Controller fine-grained directory permissions.
 *
 * GET    /api/v1/organizations/:uuid/directories/:dirUuid/permissions           - list permissions
 * POST   /api/v1/organizations/:uuid/directories/:dirUuid/permissions           - grant permission
 * DELETE /api/v1/organizations/:uuid/directories/:dirUuid/permissions/:permId   - revoke permission
 */
final class PermissionController
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    // ------------------------------------------------------------------ //
    //  GET .../permissions                                                //
    // ------------------------------------------------------------------ //

    public function list(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);
        $dir  = $this->findDirOrFail($request, $org);

        try {
            $perms = $this->permissionService->listForDirectory($dir->id, $user->id, $org->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success(\array_map(fn($p) => $this->serialize($p), $perms));
    }

    // ------------------------------------------------------------------ //
    //  POST .../permissions                                               //
    // ------------------------------------------------------------------ //

    public function grant(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);
        $dir  = $this->findDirOrFail($request, $org);

        $subjectType = \trim((string) ($request->input('subject_type') ?? ''));
        $subjectUuid = \trim((string) ($request->input('subject_uuid') ?? ''));
        $permission  = \trim((string) ($request->input('permission')   ?? ''));
        $isDeny      = (bool) ($request->input('is_deny') ?? false);
        $expiresAt   = $request->input('expires_at') !== null
            ? (string) $request->input('expires_at') : null;

        // Resolve subject UUID → numeric ID
        $subjectId = $this->resolveSubjectId($subjectType, $subjectUuid);
        if ($subjectId === null) {
            return Response::notFound(__('ui.backend.common.subject_not_found'));
        }

        try {
            $perm = $this->permissionService->grant(
                $subjectType,
                $subjectId,
                'directory',
                $dir->id,
                $permission,
                $isDeny,
                $expiresAt,
                $user->id,
                $org->id,
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['permission' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serialize($perm), 201);
    }

    // ------------------------------------------------------------------ //
    //  DELETE .../permissions/:permId                                     //
    // ------------------------------------------------------------------ //

    public function revoke(Request $request): Response
    {
        $user   = AuthContext::requireUser();
        $org    = $this->findOrgOrFail($request);
        $permId = (string) $request->routeParam('permId');

        try {
            $this->permissionService->revoke($permId, $user->id, $org->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
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

    private function findDirOrFail(Request $request, Organization $org): Directory
    {
        $dirUuid = (string) $request->routeParam('dirUuid');
        $dir     = Directory::findByUuid($dirUuid);
        if ($dir === null || $dir->organizationId !== $org->id) {
            throw new \RuntimeException(__('ui.backend.directory.not_found'));
        }
        return $dir;
    }

    /**
     * Resolve subject UUID to numeric ID string.
     * Returns null if not found.
     */
    private function resolveSubjectId(string $subjectType, string $uuid): ?string
    {
        return match ($subjectType) {
            'user'  => \Passway\Models\User::findByUuid($uuid)?->id,
            'group' => Group::findByUuid($uuid)?->id,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private function serialize(UserPermission $p): array
    {
        return [
            'id'            => $p->id,
            'subject_type'  => $p->subjectType,
            'subject_id'    => $p->subjectId,
            'resource_type' => $p->resourceType,
            'resource_id'   => $p->resourceId,
            'permission'    => $p->permission,
            'is_deny'       => $p->isDeny,
            'expires_at'    => $p->expiresAt,
            'granted_by'    => $p->grantedBy,
            'created_at'    => $p->createdAt,
        ];
    }
}
