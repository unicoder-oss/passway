<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\Organization;
use Passway\Models\OrganizationIntegration;
use Passway\Models\RotationService;
use Passway\Services\OrganizationIntegrationService;

/**
 * Интеграции rotation services для конкретной организации.
 */
final class OrganizationIntegrationController
{
    public function __construct(
        private readonly OrganizationIntegrationService $integrationService,
    ) {}

    public function list(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $items = $this->integrationService->listForOrg($org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::success(\array_map(fn($i) => $this->serialize($i), $items));
    }

    public function show(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $item = $this->integrationService->get((string) $request->routeParam('intUuid'), $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serialize($item));
    }

    public function create(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $name = (string) ($request->input('name') ?? '');
        $serviceUuid = (string) ($request->input('rotation_service_uuid') ?? '');
        $credentials = $request->input('credentials');

        if (!\is_array($credentials)) {
            return Response::validationError(['credentials' => [__('ui.backend.common.credentials_object_required')]]);
        }

        try {
            $item = $this->integrationService->create($org->id, $serviceUuid, $name, $credentials, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serialize($item), 201);
    }

    public function update(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $credentials = $request->input('credentials');

        if ($credentials !== null && !\is_array($credentials)) {
            return Response::validationError(['credentials' => [__('ui.backend.common.credentials_object_required')]]);
        }

        try {
            $item = $this->integrationService->update(
                (string) $request->routeParam('intUuid'),
                $org->id,
                $user->id,
                $request->input('name') !== null ? (string) $request->input('name') : null,
                \is_array($credentials) ? $credentials : null,
                $request->input('is_active') !== null ? (bool) $request->input('is_active') : null,
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serialize($item));
    }

    public function delete(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);

        try {
            $this->integrationService->delete((string) $request->routeParam('intUuid'), $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success();
    }

    private function findOrgOrFail(Request $request): Organization
    {
        $org = Organization::findByUuid((string) $request->routeParam('uuid'));
        if ($org === null) {
            throw new \RuntimeException(__('ui.backend.common.organization_not_found'));
        }

        return $org;
    }

    /** @return array<string, mixed> */
    private function serialize(OrganizationIntegration $item): array
    {
        $service = RotationService::findById($item->rotationServiceId);

        return [
            'uuid'                  => $item->uuid,
            'name'                  => $item->name,
            'rotation_service_uuid' => $service?->uuid,
            'rotation_service_name' => $service?->name,
            'is_active'             => $item->isActive,
            'created_at'            => $item->createdAt,
            'updated_at'            => $item->updatedAt,
        ];
    }
}
