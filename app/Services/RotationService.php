<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Models\Secret;

/**
 * Автоматическая ротация секретов по расписанию.
 *
 * На этом этапе поддерживается плановая ротация шаблонных секретов.
 * Dynamic-секреты с внешними integration будут подключены в следующем подшаге.
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
                if ($secret->type !== 'template') {
                    if ($secret->type !== 'dynamic') {
                        $result['skipped']++;
                        continue;
                    }

                    $this->rotateDynamicSecret($secret->uuid, $secret->organizationId);
                    $result['rotated']++;
                    continue;
                }

                $this->rotateTemplateSecret($secret->uuid, $secret->organizationId);
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
        ['secret' => $secret, 'value' => $currentValue] = $this->secretService->getForRotation($secretUuid, $orgId);

        if ($secret->type !== 'dynamic') {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.dynamic_only_external'));
        }
        if ($secret->rotationIntegrationId === null) {
            throw new \RuntimeException(__('ui.backend.rotation_runtime.dynamic_missing_integration'));
        }

        $integration = \Passway\Models\OrganizationIntegration::findById($secret->rotationIntegrationId)
            ?? throw new \RuntimeException(__('ui.backend.secret.rotation_integration_not_found'));
        $service = \Passway\Models\RotationService::findById($integration->rotationServiceId)
            ?? throw new \RuntimeException(__('ui.backend.rotation.service_not_found'));
        $credentials = $this->integrationService->getDecryptedCredentials($integration->id);

        try {
            if (!$this->httpClient->validate($service->url, $credentials, $secret, $currentValue)) {
                throw new \RuntimeException(__('ui.backend.rotation_runtime.validation_before_failed'));
            }

            $newValue = $this->httpClient->rotate($service->url, $credentials, $secret, $currentValue);

            if ($this->httpClient->validate($service->url, $credentials, $secret, $currentValue)) {
                $this->httpClient->rollback($service->url, $credentials, $secret, $newValue, $currentValue);
                throw new \RuntimeException(__('ui.backend.rotation_runtime.old_value_still_valid'));
            }

            if (!$this->httpClient->validate($service->url, $credentials, $secret, $newValue)) {
                $this->httpClient->rollback($service->url, $credentials, $secret, $newValue, $currentValue);
                throw new \RuntimeException(__('ui.backend.rotation_runtime.new_value_invalid'));
            }

            return $this->secretService->rotateValue(
                $secret->uuid,
                $orgId,
                $newValue,
                null,
                'api'
            );
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
            'template' => $this->rotateTemplateSecret($secretUuid, $orgId),
            'dynamic'  => $this->rotateDynamicSecret($secretUuid, $orgId),
            default    => throw new \RuntimeException(__('ui.backend.rotation_runtime.manual_only_template_dynamic')),
        };
    }
}
