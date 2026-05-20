<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\ApiKey;
use Passway\Models\Organization;
use Passway\Services\ApiKeyService;

/**
 * Controller API key management.
 *
 * GET    /api/v1/organizations/:uuid/api-keys                             - list keys
 * POST   /api/v1/organizations/:uuid/api-keys                             - create key
 * GET    /api/v1/organizations/:uuid/api-keys/:keyUuid                    - key details
 * PATCH  /api/v1/organizations/:uuid/api-keys/:keyUuid                    - change key role
 * DELETE /api/v1/organizations/:uuid/api-keys/:keyUuid                    - revoke key
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
        $role        = \trim((string) ($request->input('role') ?? 'reader'));
        $expiresAt   = $request->input('expires_at') !== null
            ? \trim((string) $request->input('expires_at'))
            : null;

        if ($name === '') {
            return Response::validationError(['name' => [__('ui.backend.apikey.name_required')]]);
        }

        try {
            ['key' => $apiKey, 'raw' => $rawKey] = $this->apiKeyService->create(
                $name,
                $org->id,
                $user->id,
                $role,
                $expiresAt !== '' ? $expiresAt : null,
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        }

        // Return the raw key ONCE
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

    public function update(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $keyUuid = (string) $request->routeParam('keyUuid');
        $role = \trim((string) ($request->input('role') ?? ''));

        if ($role === '') {
            return Response::validationError(['role' => [__('ui.backend.common.role_required')]]);
        }

        try {
            $apiKey = $this->apiKeyService->updateRole($keyUuid, $role, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['role' => [$e->getMessage()]]);
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
    private function serializeKey(ApiKey $k): array
    {
        return [
            'uuid'         => $k->uuid,
            'name'         => $k->name,
            'role'         => $k->role,
            'key_prefix'   => $k->keyPrefix,
            'is_active'    => $k->isActive,
            'last_used_at' => $k->lastUsedAt,
            'expires_at'   => $k->expiresAt,
            'created_at'   => $k->createdAt,
        ];
    }
}
