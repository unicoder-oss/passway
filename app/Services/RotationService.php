<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Models\Secret;

/**
 * External schema-driven rotation of dynamic secrets.
 */
final class RotationService
{
    public function __construct(
        private readonly SecretService $secretService,
        private readonly TemplateService $templateService,
        private readonly SchedulerService $schedulerService,
        private readonly RotationHttpClient $httpClient,
        private readonly OrganizationIntegrationService $integrationService,
    ) {}

    /**
     * @return array{rotated:int, skipped:int, failed:int, errors: array<int, array{secret_uuid:string, error:string}>}
     */
    public function runDue(?\DateTimeImmutable $now = null): array
    {
        $now ??= now();

        $result = [
            'rotated' => 0,
            'skipped' => 0,
            'failed'  => 0,
            'errors'  => [],
        ];

        foreach ($this->schedulerService->findDueSecrets($now) as $secret) {
            try {
                if ($secret->type !== 'dynamic') {
                    $result['skipped']++;
                    continue;
                }

                $this->rotateDynamicSecret($secret->uuid, $secret->organizationId);
                $result['rotated']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = [
                    'secret_uuid' => $secret->uuid,
                    'error'       => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $rotationInput
     */
    public function provisionDynamicSecret(
        string $orgId,
        string $dirUuid,
        string $name,
        string $rotationIntegrationUuid,
        ?string $rotationSchedule,
        array $rotationInput,
        string $userId,
        string $defaultReadAccess = 'inherit',
        string $defaultWriteAccess = 'inherit',
        bool $requiresApproval = false,
    ): Secret {
        $integration = \Passway\Models\OrganizationIntegration::findByUuid($rotationIntegrationUuid)
            ?? throw new \RuntimeException(__('ui.backend.secret.rotation_integration_not_found'));
        if ($integration->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.secret.rotation_integration_not_found'));
        }
        if (!$integration->isActive) {
            throw new \RuntimeException(__('ui.backend.secret.rotation_integration_inactive'));
        }

        $service = \Passway\Models\RotationService::findById($integration->rotationServiceId)
            ?? throw new \RuntimeException(__('ui.backend.rotation.service_not_found'));
        if (!$service->isActive || !$service->isVerified) {
            throw new \RuntimeException(__('ui.backend.integration.service_must_be_active_verified'));
        }
        $rotationInput = RotationSchemaValidator::normalizeValues($service->secretFields(), $rotationInput);

        $primaryField = $service->primarySecretField();
        if ($primaryField === null) {
            throw new \RuntimeException(__('ui.backend.secret.dynamic_primary_field_missing'));
        }

        $credentials = $this->integrationService->getDecryptedCredentials($integration->id);
        $secretUuid = generate_uuid();
        $context = [
            'secret_uuid' => $secretUuid,
            'secret_name' => $name,
            'organization_id' => $orgId,
            'directory_uuid' => $dirUuid,
        ];

        $outputs = $this->httpClient->provision($service->url, $credentials, $rotationInput, $context);
        $primaryValue = $outputs[$primaryField] ?? null;
        if (!\is_string($primaryValue) || $primaryValue === '') {
            throw new \RuntimeException(__('ui.backend.secret.dynamic_primary_value_missing', ['field' => $primaryField]));
        }

        $validationSecret = new \Passway\Models\Secret(
            id: '0',
            uuid: $secretUuid,
            directoryId: $dirUuid,
            organizationId: $orgId,
            name: $name,
            type: 'dynamic',
            encryptedValue: '',
            nonce: '',
            templateId: null,
            requiresApproval: $requiresApproval,
            rotationIntegrationId: $integration->id,
            rotationSchedule: $rotationSchedule,
            lastRotatedAt: null,
            version: 1,
            createdBy: $userId,
            ownerUserId: $userId,
            defaultReadAccess: $defaultReadAccess,
            defaultWriteAccess: $defaultWriteAccess,
            createdAt: now()->format('Y-m-d H:i:s'),
            updatedAt: now()->format('Y-m-d H:i:s'),
            deletedAt: null,
        );

        if (!$this->httpClient->validate($service->url, $credentials, $validationSecret, $rotationInput, $outputs)) {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.provision_validation_failed'));
        }

        $secret = $this->secretService->createProvisionedDynamicSecret(
            $orgId,
            $dirUuid,
            $name,
            $rotationIntegrationUuid,
            $rotationSchedule,
            $rotationInput,
            $outputs,
            $primaryField,
            $userId,
            $secretUuid,
            $defaultReadAccess,
            $defaultWriteAccess,
            $requiresApproval,
        );

        return $secret;
    }

    public function rotateTemplateSecret(string $secretUuid, string $orgId): Secret
    {
        $secret = Secret::findByUuid($secretUuid);
        if ($secret === null || $secret->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.secret.not_found'));
        }

        if ($secret->type !== 'template') {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.template_only_scheduler'));
        }

        if ($secret->templateId === null) {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.template_missing_id'));
        }

        $template = \Passway\Models\Template::findById($secret->templateId);
        if ($template === null) {
            throw new \RuntimeException(__('ui.backend.template.not_found'));
        }

        $value = $this->templateService->generate($template->uuid, $orgId);

        return $this->secretService->rotateValue(
            $secret->uuid,
            $orgId,
            $value,
            null,
            'scheduled'
        );
    }

    public function rotateDynamicSecret(string $secretUuid, string $orgId): Secret
    {
        [
            'secret' => $secret,
            'input' => $rotationInput,
            'outputs' => $currentOutputs,
            'primary_field' => $primaryField,
        ] = $this->secretService->getDynamicRotationState($secretUuid, $orgId);

        if ($secret->rotationIntegrationId === null) {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.dynamic_missing_integration'));
        }

        $integration = \Passway\Models\OrganizationIntegration::findById($secret->rotationIntegrationId)
            ?? throw new \RuntimeException(__('ui.backend.secret.rotation_integration_not_found'));
        if (!$integration->isActive) {
            throw new \RuntimeException(__('ui.backend.secret.rotation_integration_inactive'));
        }
        $service = \Passway\Models\RotationService::findById($integration->rotationServiceId)
            ?? throw new \RuntimeException(__('ui.backend.rotation.service_not_found'));
        if (!$service->isActive || !$service->isVerified) {
            throw new \RuntimeException(__('ui.backend.integration.service_must_be_active_verified'));
        }
        $credentials = $this->integrationService->getDecryptedCredentials($integration->id);

        try {
            if (!$this->httpClient->validate($service->url, $credentials, $secret, $rotationInput, $currentOutputs)) {
                throw new \RuntimeException(__('ui.backend.rotation_runtime.validation_before_failed'));
            }

            $newOutputs = $this->httpClient->rotate($service->url, $credentials, $secret, $rotationInput, $currentOutputs);
            $newPrimaryValue = $newOutputs[$primaryField] ?? null;
            if (!\is_string($newPrimaryValue) || $newPrimaryValue === '') {
                throw new \RuntimeException(__('ui.backend.secret.dynamic_primary_value_missing', ['field' => $primaryField]));
            }

            if ($this->httpClient->validate($service->url, $credentials, $secret, $rotationInput, $currentOutputs)) {
                $this->httpClient->rollback($service->url, $credentials, $secret, $rotationInput, $newOutputs, $currentOutputs);
                throw new \RuntimeException(__('ui.backend.rotation_runtime.old_value_still_valid'));
            }

            if (!$this->httpClient->validate($service->url, $credentials, $secret, $rotationInput, $newOutputs)) {
                $this->httpClient->rollback($service->url, $credentials, $secret, $rotationInput, $newOutputs, $currentOutputs);
                throw new \RuntimeException(__('ui.backend.rotation_runtime.new_value_invalid'));
            }

            $updated = $this->secretService->rotateValue(
                $secret->uuid,
                $orgId,
                $newPrimaryValue,
                null,
                'api'
            );
            $this->secretService->updateDynamicRotationOutputs($secret->uuid, $orgId, $newOutputs, $primaryField);

            return $updated;
        } catch (\Throwable $e) {
            $this->secretService->recordRotationFailure($secret->uuid, $orgId, 'api', $e->getMessage());
            throw $e;
        }
    }

    public function rotateSecretNow(string $secretUuid, string $orgId): Secret
    {
        $secret = \Passway\Models\Secret::findByUuid($secretUuid);
        if ($secret === null || $secret->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.secret.not_found'));
        }

        return match ($secret->type) {
            'dynamic'  => $this->rotateDynamicSecret($secretUuid, $orgId),
            default    => throw new \RuntimeException(__('ui.backend.rotation_runtime.manual_only_dynamic')),
        };
    }
}
