<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Exceptions\DecryptionException;
use Passway\Models\Organization;
use Passway\Models\Secret;
use Passway\Models\SecretVersion;
use Passway\Services\RotationService;
use Passway\Services\SecretService;

/**
 * Контроллер секретов организации.
 *
 * GET    /api/v1/organizations/:uuid/directories/:dirUuid/secrets                  — список
 * POST   /api/v1/organizations/:uuid/directories/:dirUuid/secrets                  — создать
 * GET    /api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid         — детали + значение
 * PATCH  /api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid         — обновить
 * DELETE /api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid         — мягкое удаление
 * GET    /api/v1/organizations/:uuid/directories/:dirUuid/secrets/:secUuid/versions — история
 */
final class SecretController
{
    public function __construct(
        private readonly SecretService $secretService,
        private readonly RotationService $rotationService,
    ) {}

    // ------------------------------------------------------------------ //
    //  GET .../secrets                                                    //
    // ------------------------------------------------------------------ //

    public function list(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        try {
            $secrets = $this->secretService->listInDirectory($dirUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success(\array_map(fn($s) => $this->serializeMeta($s), $secrets));
    }

    // ------------------------------------------------------------------ //
    //  POST .../secrets                                                   //
    // ------------------------------------------------------------------ //

    public function create(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');

        $name  = \trim((string) ($request->input('name') ?? ''));
        $type  = \trim((string) ($request->input('type') ?? 'static'));
        $value = (string) ($request->input('value') ?? '');
        $templateUuid = $request->input('template_uuid') !== null
            ? \trim((string) $request->input('template_uuid'))
            : null;
        $rotationIntegrationUuid = $request->input('rotation_integration_uuid') !== null
            ? \trim((string) $request->input('rotation_integration_uuid'))
            : null;
        $rotationSchedule = $request->input('rotation_schedule') !== null
            ? \trim((string) $request->input('rotation_schedule'))
            : null;

        if ($name === '') {
            return Response::validationError(['name' => [__('ui.backend.common.name_required')]]);
        }
        if (!\in_array($type, SecretService::VALID_TYPES, true)) {
            return Response::validationError(['type' => [
                __('ui.backend.secret.type_required', ['allowed' => \implode(', ', SecretService::VALID_TYPES)]),
            ]]);
        }

        try {
            $secret = $type === 'template' && $templateUuid !== null && $templateUuid !== ''
                ? $this->secretService->createFromTemplate(
                    $org->id,
                    $dirUuid,
                    $name,
                    $templateUuid,
                    $user->id,
                    [],
                    $rotationSchedule !== '' ? $rotationSchedule : null,
                )
                : $this->secretService->create(
                    $org->id,
                    $dirUuid,
                    $name,
                    $type,
                    $value,
                    $user->id,
                    $rotationIntegrationUuid !== '' ? $rotationIntegrationUuid : null,
                    $rotationSchedule !== '' ? $rotationSchedule : null,
                );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeMeta($secret), 201);
    }

    // ------------------------------------------------------------------ //
    //  GET .../secrets/:secUuid                                           //
    // ------------------------------------------------------------------ //

    public function show(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            ['secret' => $secret, 'value' => $value] =
                $this->secretService->get($secUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (DecryptionException $e) {
            return Response::error(__('ui.backend.common.secret_decrypt_failed'), 500);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serializeWithValue($secret, $value));
    }

    // ------------------------------------------------------------------ //
    //  PATCH .../secrets/:secUuid                                         //
    // ------------------------------------------------------------------ //

    public function update(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $secUuid = (string) $request->routeParam('secUuid');

        $newName  = $request->input('name');
        $newValue = $request->input('value');
        $rotationIntegrationUuid = $request->input('rotation_integration_uuid');
        $rotationSchedule = $request->input('rotation_schedule');

        if ($newName === null && $newValue === null && $rotationIntegrationUuid === null && $rotationSchedule === null) {
            return Response::validationError([
                'name' => [__('ui.backend.secret.update_requires_field')],
            ]);
        }

        try {
            $secret = $this->secretService->update(
                $secUuid,
                $org->id,
                $user->id,
                $newName  !== null ? (string) $newName  : null,
                $newValue !== null ? (string) $newValue : null,
            );

            if ($rotationIntegrationUuid !== null || $rotationSchedule !== null) {
                $secret = $this->secretService->configureRotation(
                    $secUuid,
                    $org->id,
                    $user->id,
                    $rotationIntegrationUuid !== null ? (string) $rotationIntegrationUuid : null,
                    $rotationSchedule !== null ? (string) $rotationSchedule : null,
                );
            }
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeMeta($secret));
    }

    // ------------------------------------------------------------------ //
    //  POST .../secrets/:secUuid/rotate                                    //
    // ------------------------------------------------------------------ //

    public function rotate(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            $this->secretService->assertCanRotate($secUuid, $org->id, $user->id);
            $secret = $this->rotationService->rotateSecretNow($secUuid, $org->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeMeta($secret));
    }

    // ------------------------------------------------------------------ //
    //  DELETE .../secrets/:secUuid                                        //
    // ------------------------------------------------------------------ //

    public function delete(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            $this->secretService->delete($secUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success();
    }

    // ------------------------------------------------------------------ //
    //  GET .../secrets/:secUuid/versions                                  //
    // ------------------------------------------------------------------ //

    public function versions(Request $request): Response
    {
        $user    = AuthContext::requireUser();
        $org     = $this->findOrgOrFail($request);
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            $versions = $this->secretService->listVersions($secUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success(\array_map(fn($v) => $this->serializeVersion($v), $versions));
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
    private function serializeMeta(Secret $s): array
    {
        return [
            'uuid'              => $s->uuid,
            'name'              => $s->name,
            'type'              => $s->type,
            'requires_approval' => $s->requiresApproval,
            'version'           => $s->version,
            'rotation_schedule' => $s->rotationSchedule,
            'last_rotated_at'   => $s->lastRotatedAt,
            'created_at'        => $s->createdAt,
            'updated_at'        => $s->updatedAt,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeWithValue(Secret $s, string $value): array
    {
        return \array_merge($this->serializeMeta($s), ['value' => $value]);
    }

    /** @return array<string, mixed> */
    private function serializeVersion(SecretVersion $v): array
    {
        return [
            'version'       => $v->version,
            'rotation_type' => $v->rotationType,
            'status'        => $v->status,
            'created_at'    => $v->createdAt,
        ];
    }
}
