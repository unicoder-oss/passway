<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\OrganizationIntegration;
use Passway\Models\RotationService;

/**
 * Управление привязками rotation services к организациям.
 */
final class OrganizationIntegrationService
{
    public function __construct(
        private readonly OrganizationService $organizationService,
        private readonly EncryptionService $encryptionService,
        private readonly ?AuditService $auditService = null,
    ) {}

    /** @return OrganizationIntegration[] */
    public function listForOrg(string $orgId, string $userId): array
    {
        $this->assertAdmin($orgId, $userId);
        return OrganizationIntegration::findByOrgId($orgId);
    }

    public function get(string $uuid, string $orgId, string $userId): OrganizationIntegration
    {
        $this->assertAdmin($orgId, $userId);

        $integration = OrganizationIntegration::findByUuid($uuid)
            ?? throw new \RuntimeException('Integration not found.');

        if ($integration->organizationId !== $orgId) {
            throw new \RuntimeException('Integration not found.');
        }

        return $integration;
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public function create(
        string $orgId,
        string $rotationServiceUuid,
        string $name,
        array $credentials,
        string $userId,
    ): OrganizationIntegration {
        $this->assertAdmin($orgId, $userId);
        $name = $this->normalizeName($name);

        $service = RotationService::findByUuid($rotationServiceUuid)
            ?? throw new \RuntimeException('Rotation service not found.');

        if (!$service->isActive || !$service->isVerified) {
            throw new \RuntimeException('Rotation service must be active and verified before use.');
        }

        $uuid = generate_uuid();
        $now = now()->format('Y-m-d H:i:s');
        $credentialsJson = $this->encodeCredentials($credentials);
        $encrypted = $this->encryptionService->encrypt($credentialsJson, $uuid);

        Database::getInstance()->insert('organization_integrations', [
            'uuid'                  => $uuid,
            'organization_id'       => (int) $orgId,
            'rotation_service_id'   => (int) $service->id,
            'name'                  => $name,
            'encrypted_credentials' => $encrypted->value,
            'credentials_nonce'     => $encrypted->nonce,
            'is_active'             => 1,
            'created_by'            => (int) $userId,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        $integration = $this->get($uuid, $orgId, $userId);
        $this->getAuditService()->record(
            action: 'rotation.integration_create',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'integration',
            resourceId: $integration->id,
            resourceUuid: $integration->uuid,
            details: ['rotation_service_uuid' => $rotationServiceUuid],
        );

        return $integration;
    }

    /**
     * @param array<string, mixed>|null $credentials
     */
    public function update(
        string $uuid,
        string $orgId,
        string $userId,
        ?string $name = null,
        ?array $credentials = null,
        ?bool $isActive = null,
    ): OrganizationIntegration {
        $integration = $this->get($uuid, $orgId, $userId);
        $data = ['updated_at' => now()->format('Y-m-d H:i:s')];

        if ($name !== null) {
            $data['name'] = $this->normalizeName($name);
        }

        if ($credentials !== null) {
            $credentialsJson = $this->encodeCredentials($credentials);
            $encrypted = $this->encryptionService->encrypt($credentialsJson, $integration->uuid);
            $data['encrypted_credentials'] = $encrypted->value;
            $data['credentials_nonce'] = $encrypted->nonce;
        }

        if ($isActive !== null) {
            $data['is_active'] = $isActive ? 1 : 0;
        }

        Database::getInstance()->update('organization_integrations', $data, ['id' => $integration->id]);

        $updated = $this->get($uuid, $orgId, $userId);
        $this->getAuditService()->record(
            action: 'rotation.integration_update',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'integration',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
        );

        return $updated;
    }

    public function delete(string $uuid, string $orgId, string $userId): void
    {
        $integration = $this->get($uuid, $orgId, $userId);
        Database::getInstance()->delete('organization_integrations', ['id' => (int) $integration->id]);

        $this->getAuditService()->record(
            action: 'rotation.integration_delete',
            organizationId: $orgId,
            userId: $userId,
            resourceType: 'integration',
            resourceId: $integration->id,
            resourceUuid: $integration->uuid,
        );
    }

    /** @return array<string, mixed> */
    public function getDecryptedCredentials(string $integrationId): array
    {
        $integration = OrganizationIntegration::findById($integrationId)
            ?? throw new \RuntimeException('Integration not found.');

        if (!$integration->isActive) {
            throw new \RuntimeException('Integration is inactive.');
        }

        if ($integration->encryptedCredentials === null || $integration->credentialsNonce === null) {
            return [];
        }

        $json = $this->encryptionService->decrypt(
            $integration->encryptedCredentials,
            $integration->credentialsNonce,
            $integration->uuid,
        );

        $decoded = \json_decode($json, true);
        return \is_array($decoded) ? $decoded : [];
    }

    private function assertAdmin(string $orgId, string $userId): void
    {
        if (!$this->organizationService->hasPermission($orgId, $userId, 'admin')) {
            throw new AuthException('Only admin or above can manage organization integrations.', 403);
        }
    }

    private function normalizeName(string $name): string
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Integration name cannot be empty.');
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException('Integration name is too long (max 255 characters).');
        }

        return $name;
    }

    /** @param array<string, mixed> $credentials */
    private function encodeCredentials(array $credentials): string
    {
        $json = \json_encode($credentials, \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode integration credentials as JSON.');
        }

        return $json;
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService());
    }
}
