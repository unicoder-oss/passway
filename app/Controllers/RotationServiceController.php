<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Models\RotationService;
use Passway\Services\RotationRegistryService;

/**
 * Глобальные rotation services.
 */
final class RotationServiceController
{
    public function __construct(
        private readonly RotationRegistryService $registryService,
    ) {}

    public function list(Request $request): Response
    {
        AuthContext::requireUser();
        $services = $this->registryService->listAll();
        return Response::success(\array_map(fn($s) => $this->serialize($s), $services));
    }

    public function show(Request $request): Response
    {
        AuthContext::requireUser();

        try {
            $service = $this->registryService->get((string) $request->routeParam('svcUuid'));
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serialize($service));
    }

    public function create(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $name = (string) ($request->input('name') ?? '');
        $url = (string) ($request->input('url') ?? '');

        try {
            $service = $this->registryService->create($name, $url, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serialize($service), 201);
    }

    public function update(Request $request): Response
    {
        $user = AuthContext::requireUser();

        try {
            $service = $this->registryService->update(
                (string) $request->routeParam('svcUuid'),
                $user->id,
                $request->input('name') !== null ? (string) $request->input('name') : null,
                $request->input('url') !== null ? (string) $request->input('url') : null,
                $request->input('is_active') !== null ? (bool) $request->input('is_active') : null,
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serialize($service));
    }

    public function verify(Request $request): Response
    {
        $user = AuthContext::requireUser();

        try {
            $service = $this->registryService->verify((string) $request->routeParam('svcUuid'), $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serialize($service));
    }

    public function delete(Request $request): Response
    {
        $user = AuthContext::requireUser();

        try {
            $this->registryService->delete((string) $request->routeParam('svcUuid'), $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success();
    }

    /** @return array<string, mixed> */
    private function serialize(RotationService $service): array
    {
        return [
            'uuid'          => $service->uuid,
            'name'          => $service->name,
            'url'           => $service->url,
            'health_url'    => $service->healthUrl,
            'spec'          => $service->spec(),
            'is_active'     => $service->isActive,
            'is_verified'   => $service->isVerified,
            'last_check_at' => $service->lastCheckAt,
            'created_at'    => $service->createdAt,
            'updated_at'    => $service->updatedAt,
        ];
    }
}
