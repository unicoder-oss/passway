<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\RotationService;

/**
 * Управление глобальным каталогом внешних rotation services.
 *
 * В текущей модели прав отдельного system-admin нет, поэтому эту роль временно
 * выполняет первый пользователь, созданный во время setup.
 */
final class RotationRegistryService
{
    public function __construct(
        private readonly RotationHttpClient $httpClient,
        private readonly ?AuditService $auditService = null,
    ) {}

    /** @return RotationService[] */
    public function listAll(): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM rotation_services ORDER BY name ASC'
        );

        return \array_map(fn($row) => RotationService::fromRow($row), $rows);
    }

    public function get(string $uuid): RotationService
    {
        return RotationService::findByUuid($uuid)
            ?? throw new \RuntimeException(__('ui.backend.rotation.service_not_found'));
    }

    public function create(string $name, string $url, string $userId): RotationService
    {
        $this->assertSystemAdmin($userId);
        $name = $this->normalizeName($name);
        $url = $this->normalizeUrl($url);

        $verified = $this->httpClient->checkHealth($url);
        $spec = $this->httpClient->fetchSpec($url);
        $now = now()->format('Y-m-d H:i:s');
        $uuid = generate_uuid();

        Database::getInstance()->insert('rotation_services', [
            'uuid'          => $uuid,
            'name'          => $name,
            'url'           => $url,
            'health_url'    => $url . '/health',
            'spec_json'     => \json_encode($spec, \JSON_UNESCAPED_SLASHES),
            'is_active'     => 1,
            'is_verified'   => $verified ? 1 : 0,
            'last_check_at' => $now,
            'created_by'    => (int) $userId,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $created = $this->get($uuid);
        $this->getAuditService()->record(
            action: 'rotation.service_create',
            userId: $userId,
            resourceType: 'rotation_service',
            resourceId: $created->id,
            resourceUuid: $created->uuid,
            details: ['url' => $created->url],
        );

        return $created;
    }

    public function update(
        string $uuid,
        string $userId,
        ?string $name = null,
        ?string $url = null,
        ?bool $isActive = null,
    ): RotationService {
        $this->assertSystemAdmin($userId);
        $service = $this->get($uuid);
        $nextUrl = $url !== null ? $this->normalizeUrl($url) : $service->url;

        $data = ['updated_at' => now()->format('Y-m-d H:i:s')];

        if ($name !== null) {
            $data['name'] = $this->normalizeName($name);
        }

        if ($url !== null) {
            $verified = $this->httpClient->checkHealth($nextUrl);
            $spec = $this->httpClient->fetchSpec($nextUrl);

            $data['url'] = $nextUrl;
            $data['health_url'] = $nextUrl . '/health';
            $data['spec_json'] = \json_encode($spec, \JSON_UNESCAPED_SLASHES);
            $data['is_verified'] = $verified ? 1 : 0;
            $data['last_check_at'] = now()->format('Y-m-d H:i:s');
        }

        if ($isActive !== null) {
            $data['is_active'] = $isActive ? 1 : 0;
        }

        Database::getInstance()->update('rotation_services', $data, ['id' => $service->id]);

        $updated = $this->get($uuid);
        $this->getAuditService()->record(
            action: 'rotation.service_update',
            userId: $userId,
            resourceType: 'rotation_service',
            resourceId: $updated->id,
            resourceUuid: $updated->uuid,
        );

        return $updated;
    }

    public function verify(string $uuid, string $userId): RotationService
    {
        $this->assertSystemAdmin($userId);
        $service = $this->get($uuid);

        $verified = $this->httpClient->checkHealth($service->url);
        $spec = $this->httpClient->fetchSpec($service->url);

        Database::getInstance()->update('rotation_services', [
            'spec_json'     => \json_encode($spec, \JSON_UNESCAPED_SLASHES),
            'is_verified'   => $verified ? 1 : 0,
            'last_check_at' => now()->format('Y-m-d H:i:s'),
            'updated_at'    => now()->format('Y-m-d H:i:s'),
        ], ['id' => $service->id]);

        $verifiedService = $this->get($uuid);
        $this->getAuditService()->record(
            action: 'rotation.service_verify',
            userId: $userId,
            resourceType: 'rotation_service',
            resourceId: $verifiedService->id,
            resourceUuid: $verifiedService->uuid,
            details: ['verified' => $verifiedService->isVerified],
            success: $verifiedService->isVerified,
        );

        return $verifiedService;
    }

    public function delete(string $uuid, string $userId): void
    {
        $this->assertSystemAdmin($userId);
        $service = $this->get($uuid);

        Database::getInstance()->delete('rotation_services', ['id' => (int) $service->id]);

        $this->getAuditService()->record(
            action: 'rotation.service_delete',
            userId: $userId,
            resourceType: 'rotation_service',
            resourceId: $service->id,
            resourceUuid: $service->uuid,
        );
    }

    private function assertSystemAdmin(string $userId): void
    {
        $firstUserId = Database::getInstance()->fetchColumn(
            'SELECT id FROM users ORDER BY id ASC LIMIT 1'
        );

        if ((string) $firstUserId !== $userId) {
            throw new AuthException(__('ui.backend.rotation.requires_setup_admin'), 403);
        }
    }

    private function normalizeName(string $name): string
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(__('ui.backend.rotation.name_empty'));
        }
        if (\strlen($name) > 255) {
            throw new \InvalidArgumentException(__('ui.backend.rotation.name_too_long'));
        }

        return $name;
    }

    private function normalizeUrl(string $url): string
    {
        $url = \rtrim(\trim($url), '/');
        if ($url === '' || !\filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(__('ui.backend.rotation.invalid_url'));
        }

        return $url;
    }

    private function getAuditService(): AuditService
    {
        return $this->auditService ?? new AuditService(new LoggerService());
    }
}
