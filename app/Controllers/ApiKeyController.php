<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\ApiKey;
use Passway\Models\ApiKeyPermission;
use Passway\Models\Organization;
use Passway\Services\ApiKeyService;

/**
 * Контроллер управления API-ключами.
 *
 * GET    /api/v1/organizations/:uuid/api-keys                             — список ключей
 * POST   /api/v1/organizations/:uuid/api-keys                             — создать ключ
 * GET    /api/v1/organizations/:uuid/api-keys/:keyUuid                    — детали ключа
 * DELETE /api/v1/organizations/:uuid/api-keys/:keyUuid                    — отозвать ключ
 * GET    /api/v1/organizations/:uuid/api-keys/:keyUuid/permissions        — список прав
 * POST   /api/v1/organizations/:uuid/api-keys/:keyUuid/permissions        — добавить право
 * DELETE /api/v1/organizations/:uuid/api-keys/:keyUuid/permissions/:permId — удалить право
 */
final class ApiKeyController
{
    public function __construct(
        private readonly ApiKeyService $apiKeyService,
    ) {}

    // ------------------------------------------------------------------ //
    //  GET .../api-keys                                                   //
    // ------------------------------------------------------------------ //

    public function list(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        try {
            $keys = $this->apiKeyService->listForOrg($org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success(\array_map(fn($k) => $this->serializeKey($k), $keys));
    }

    // ------------------------------------------------------------------ //
    //  POST .../api-keys                                                  //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org  = $this->findOrgOrFail($request);

        $name        = \trim((string) ($request->input('name') ?? ''));
        $environment = \trim((string) ($request->input('environment') ?? 'production'));
        $expiresAt   = $request->input('expires_at') !== null
            ? \trim((string) $request->input('expires_at'))
            : null;

        if ($name === '') {
            return Response::validationError(['name' => ['Name is required.']]);
        }

        try {
            ['key' => $apiKey, 'raw' => $rawKey] = $this->apiKeyService->create(
                $name,
                $org->id,
                $user->id,
                $environment,
                $expiresAt !== '' ? $expiresAt : null,
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['environment' => [$e->getMessage()]]);
        }

        // Возвращаем сырой ключ ОДИН РАЗ
        return Response::success(\array_merge(
            $this->serializeKey($apiKey),
            ['key' => $rawKey]
        ), 201);
    }

    // ------------------------------------------------------------------ //
    //  GET .../api-keys/:keyUuid                                          //
    // ------------------------------------------------------------------ //

    public function show(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');

        try {
            $apiKey = $this->apiKeyService->get($keyUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serializeKey($apiKey));
    }

    // ------------------------------------------------------------------ //
    //  DELETE .../api-keys/:keyUuid                                       //
    // ------------------------------------------------------------------ //

    public function revoke(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');

        try {
            $this->apiKeyService->revoke($keyUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success();
    }

    // ------------------------------------------------------------------ //
    //  GET .../api-keys/:keyUuid/permissions                              //
    // ------------------------------------------------------------------ //

    public function listPermissions(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');

        try {
            $perms = $this->apiKeyService->listPermissions($keyUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success(\array_map(fn($p) => $this->serializePerm($p), $perms));
    }

    // ------------------------------------------------------------------ //
    //  POST .../api-keys/:keyUuid/permissions                             //
    // ------------------------------------------------------------------ //

    public function addPermission(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');

        $resourceType = \trim((string) ($request->input('resource_type') ?? ''));
        $resourceId   = $request->input('resource_id') !== null
            ? \trim((string) $request->input('resource_id'))
            : null;
        $permission   = \trim((string) ($request->input('permission') ?? ''));

        if ($resourceType === '') {
            return Response::validationError(['resource_type' => ['resource_type is required.']]);
        }
        if ($permission === '') {
            return Response::validationError(['permission' => ['permission is required.']]);
        }

        try {
            $perm = $this->apiKeyService->addPermission(
                $keyUuid,
                $resourceType,
                $resourceId !== '' ? $resourceId : null,
                $permission,
                $org->id,
                $user->id,
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['permission' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializePerm($perm), 201);
    }

    // ------------------------------------------------------------------ //
    //  DELETE .../api-keys/:keyUuid/permissions/:permId                   //
    // ------------------------------------------------------------------ //

    public function removePermission(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');
        $permId  = (string) $request->routeParam('permId');

        try {
            $this->apiKeyService->removePermission($keyUuid, $permId, $org->id, $user->id);
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
            throw new \RuntimeException('Organization not found.');
        }
        return $org;
    }

    /** @return array<string, mixed> */
    private function serializeKey(ApiKey $k): array
    {
        return [
            'uuid'         => $k->uuid,
            'name'         => $k->name,
            'key_prefix'   => $k->keyPrefix,
            'environment'  => $k->environment,
            'is_active'    => $k->isActive,
            'last_used_at' => $k->lastUsedAt,
            'expires_at'   => $k->expiresAt,
            'created_at'   => $k->createdAt,
        ];
    }

    /** @return array<string, mixed> */
    private function serializePerm(ApiKeyPermission $p): array
    {
        return [
            'id'            => $p->id,
            'resource_type' => $p->resourceType,
            'resource_id'   => $p->resourceId,
            'permission'    => $p->permission,
            'created_at'    => $p->createdAt,
        ];
    }
}
