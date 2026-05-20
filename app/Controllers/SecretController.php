<?php

declare(strict_types=1);

namespace Passway\Controllers;

use Passway\Core\AuthContext;
use Passway\Core\Request;
use Passway\Core\Response;
use Passway\Exceptions\AuthException;
use Passway\Exceptions\DecryptionException;
use Passway\Models\ApiKey;
use Passway\Models\Group;
use Passway\Models\Organization;
use Passway\Models\OrganizationMember;
use Passway\Models\Secret;
use Passway\Models\SecretVersion;
use Passway\Models\User;
use Passway\Models\UserPermission;
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
 * GET    /api/v1/organizations/:uuid/secrets/:secUuid/acl                           — exact ACL
 * PUT    /api/v1/organizations/:uuid/secrets/:secUuid/acl                           — replace exact ACL
 * POST   /api/v1/organizations/:uuid/secrets/:secUuid/owner                         — transfer ownership
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
        $rotationInput = $request->input('rotation_input');
        $templateOverrides = $this->parseTemplateOverridesInput($request->input('template_overrides'));
        $templateValue = $request->input('value');
        $templateValue = \is_string($templateValue) ? $templateValue : null;

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
                    $templateOverrides,
                    $rotationSchedule !== '' ? $rotationSchedule : null,
                    $templateValue,
                )
                : ($type === 'dynamic'
                    ? $this->rotationService->provisionDynamicSecret(
                        $org->id,
                        $dirUuid,
                        $name,
                        $rotationIntegrationUuid !== '' ? $rotationIntegrationUuid : '',
                        $rotationSchedule !== '' ? $rotationSchedule : null,
                        \is_array($rotationInput) ? $rotationInput : [],
                        $user->id,
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
                ));
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
    //  POST .../secrets/template-preview                                  //
    // ------------------------------------------------------------------ //

    public function previewTemplate(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $dirUuid = (string) $request->routeParam('dirUuid');
        $templateUuid = \trim((string) ($request->input('template_uuid') ?? ''));
        $providedValue = $request->input('value');
        $providedValue = \is_string($providedValue) ? $providedValue : null;

        if ($templateUuid === '') {
            return Response::validationError(['template_uuid' => [__('ui.backend.secret.template_not_found')]]);
        }

        try {
            $preview = $this->secretService->previewTemplate(
                $org->id,
                $dirUuid,
                $user->id,
                $templateUuid,
                $this->parseTemplateOverridesInput($request->input('template_overrides')),
                $providedValue,
                $this->parseBooleanInput($request->input('normalize_value')),
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['template_overrides' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($preview);
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
            $dynamicView = $this->secretService->getDynamicSecretView($secUuid, $org->id, $user->id);
        } catch (AuthException $e) {
            return Response::forbidden($e->getMessage());
        } catch (DecryptionException $e) {
            return Response::error(__('ui.backend.common.secret_decrypt_failed'), 500);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }

        return Response::success($this->serializeWithValue($secret, $value, $dynamicView));
    }

    // ------------------------------------------------------------------ //
    //  POST .../secrets/:secUuid/regenerate                               //
    // ------------------------------------------------------------------ //

    public function regenerate(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            $result = $this->secretService->regenerateFromTemplate(
                $secUuid,
                $org->id,
                $user->id,
                $this->parseTemplateOverridesInput($request->input('template_overrides')),
                \is_string($request->input('value')) ? (string) $request->input('value') : null,
            );
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\InvalidArgumentException $e) {
            return Response::validationError(['template_overrides' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success(\array_merge(
            $this->serializeMeta($result['secret']),
            [
                'value' => $result['value'],
                'display_value' => $result['display_value'],
                'extra_fields' => $result['extra_fields'],
                'template_uuid' => $result['template_uuid'],
                'template_name' => $result['template_name'],
                'template_type' => $result['template_type'],
                'parameter_schema' => $result['parameter_schema'],
                'template_overrides' => $result['template_overrides'],
            ]
        ));
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

        if ($rotationIntegrationUuid !== null) {
            return Response::validationError([
                'rotation_integration_uuid' => [__('ui.backend.secret.rotation_integration_immutable')],
            ]);
        }

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

    public function acl(Request $request): Response
    {
        $user = AuthContext::requireUser();
        $org = $this->findOrgOrFail($request);
        $secUuid = (string) $request->routeParam('secUuid');

        try {
            $rules = $this->secretService->listAcl($secUuid, $org->id, $user->id);
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
        $secUuid = (string) $request->routeParam('secUuid');
        $rulesInput = $request->input('rules');

        if (!\is_array($rulesInput)) {
            return Response::validationError(['rules' => [__('ui.backend.permission.rules_array_required')]]);
        }

        try {
            $rules = $this->secretService->replaceAcl(
                $secUuid,
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
        $secUuid = (string) $request->routeParam('secUuid');
        $userUuid = \trim((string) ($request->input('user_uuid') ?? ''));

        if ($userUuid === '') {
            return Response::validationError(['user_uuid' => [__('ui.backend.group.user_uuid_required')]]);
        }

        $targetUser = User::findByUuid($userUuid);
        if ($targetUser === null) {
            return Response::notFound(__('ui.backend.common.user_not_found'));
        }

        try {
            $secret = $this->secretService->transferOwnership($secUuid, $org->id, $targetUser->id, $user->id);
        } catch (AuthException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }

        return Response::success($this->serializeMeta($secret));
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
            'owner_user_uuid'   => $s->ownerUserId !== null ? User::findById($s->ownerUserId)?->uuid : null,
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
    private function serializeWithValue(Secret $s, string $value, array $dynamicView = []): array
    {
        return \array_merge($this->serializeMeta($s), [
            'value' => $value,
            'rotation_input' => $dynamicView['input'] ?? [],
            'rotation_outputs' => $dynamicView['outputs'] ?? [],
            'rotation_primary_field' => $dynamicView['primary_field'] ?? null,
        ]);
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

    /** @return array<string, mixed> */
    private function parseTemplateOverridesInput(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        if (\is_array($input)) {
            return $input;
        }

        if (!\is_string($input)) {
            throw new \InvalidArgumentException(__('ui.backend.web.credentials_json_object'));
        }

        $decoded = \json_decode($input, true);
        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException(__('ui.backend.web.credentials_json_object'));
        }

        return $decoded;
    }

    private function parseBooleanInput(mixed $input): bool
    {
        if (\is_bool($input)) {
            return $input;
        }

        if (\is_string($input)) {
            return \in_array(\strtolower($input), ['1', 'true', 'on', 'yes'], true);
        }

        if (\is_int($input)) {
            return $input === 1;
        }

        return false;
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
