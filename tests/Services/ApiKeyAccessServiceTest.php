<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Services\ApiKeyAccessService;
use Passway\Services\ApiKeyService;
use Passway\Services\OrganizationService;
use Passway\Tests\DatabaseTestCase;

final class ApiKeyAccessServiceTest extends DatabaseTestCase
{
    private ApiKeyAccessService $svc;
    private ApiKeyService $apiKeyService;
    private OrganizationService $orgService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = new ApiKeyAccessService();
        $this->orgService = new OrganizationService();
        $this->apiKeyService = new ApiKeyService($this->orgService);

        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    public function test_can_directory_uses_parent_directory_permission(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        ['key' => $apiKey] = $this->apiKeyService->create('Key', $org->id, $owner->id);

        $rootId = $this->insertDirectory($org->id, null, 'Root', '/root-node', 0, $owner->id);
        $childId = $this->insertDirectory($org->id, $rootId, 'Child', '/root-node/child-node', 1, $owner->id);

        $this->apiKeyService->addPermission($apiKey->uuid, 'directory', $rootId, 'read', $org->id, $owner->id);

        $this->assertTrue($this->svc->can($apiKey->id, 'read', 'directory', $childId, $org->id));
    }

    public function test_can_rejects_directory_from_other_organization(): void
    {
        $owner = $this->createTestUser();
        $orgA = $this->orgService->create('Org A', $owner->id);
        $orgB = $this->orgService->create('Org B', $owner->id);
        ['key' => $apiKey] = $this->apiKeyService->create('Key', $orgA->id, $owner->id);

        $directoryId = $this->insertDirectory($orgB->id, null, 'Other', '/other-node', 0, $owner->id);
        $this->apiKeyService->addPermission($apiKey->uuid, 'directory', null, 'read', $orgA->id, $owner->id);

        $this->assertFalse($this->svc->can($apiKey->id, 'read', 'directory', $directoryId, $orgB->id));
    }

    private function insertDirectory(string $orgId, ?string $parentId, string $name, string $path, int $depth, string $createdBy): string
    {
        $now = now()->format('Y-m-d H:i:s');
        $id = Database::getInstance()->insert('directories', [
            'uuid' => generate_uuid(),
            'organization_id' => (int) $orgId,
            'parent_id' => $parentId !== null ? (int) $parentId : null,
            'name' => $name,
            'depth' => $depth,
            'path' => $path,
            'created_by' => (int) $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (string) $id;
    }
}
