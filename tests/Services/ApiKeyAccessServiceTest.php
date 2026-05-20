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

        $this->insertAclRule('api_key', $apiKey->id, 'directory', $rootId, 'read', false);

        $this->assertTrue($this->svc->can($apiKey->id, 'read', 'directory', $childId, $org->id));
    }

    public function test_can_rejects_directory_from_other_organization(): void
    {
        $owner = $this->createTestUser();
        $orgA = $this->orgService->create('Org A', $owner->id);
        $orgB = $this->orgService->create('Org B', $owner->id);
        ['key' => $apiKey] = $this->apiKeyService->create('Key', $orgA->id, $owner->id);

        $directoryId = $this->insertDirectory($orgB->id, null, 'Other', '/other-node', 0, $owner->id);

        $this->assertFalse($this->svc->can($apiKey->id, 'read', 'directory', $directoryId, $orgB->id));
    }

    public function test_can_organization_allows_reader_role_to_read(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        ['key' => $apiKey] = $this->apiKeyService->create('Reader key', $org->id, $owner->id, 'reader');

        $this->assertTrue($this->svc->canOrganization($apiKey->id, $org->id, 'read'));
        $this->assertFalse($this->svc->canOrganization($apiKey->id, $org->id, 'write'));
    }

    public function test_can_organization_allows_editor_role_to_write(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        ['key' => $apiKey] = $this->apiKeyService->create('Editor key', $org->id, $owner->id, 'editor');

        $this->assertTrue($this->svc->canOrganization($apiKey->id, $org->id, 'read'));
        $this->assertTrue($this->svc->canOrganization($apiKey->id, $org->id, 'write'));
    }

    public function test_exact_directory_acl_deny_overrides_legacy_allow(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        ['key' => $apiKey] = $this->apiKeyService->create('Key', $org->id, $owner->id);

        $rootId = $this->insertDirectory($org->id, null, 'Root', '/root-node', 0, $owner->id);
        $childId = $this->insertDirectory($org->id, $rootId, 'Child', '/root-node/child-node', 1, $owner->id);

        $this->insertAclRule('api_key', $apiKey->id, 'directory', $childId, 'read', true);

        $this->assertFalse($this->svc->can($apiKey->id, 'read', 'directory', $childId, $org->id));
    }

    public function test_directory_acl_is_inherited_for_secret_access(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        ['key' => $apiKey] = $this->apiKeyService->create('Key', $org->id, $owner->id);

        $rootId = $this->insertDirectory($org->id, null, 'Root', '/root-node', 0, $owner->id);
        $childId = $this->insertDirectory($org->id, $rootId, 'Child', '/root-node/child-node', 1, $owner->id);
        $secretId = $this->insertSecret($org->id, $childId, 'Secret', $owner->id);

        $this->insertAclRule('api_key', $apiKey->id, 'directory', $rootId, 'read', false);

        $this->assertTrue($this->svc->can($apiKey->id, 'read', 'secret', $secretId, $org->id));
    }

    public function test_exact_secret_acl_overrides_directory_acl_and_legacy_fallback(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        ['key' => $apiKey] = $this->apiKeyService->create('Key', $org->id, $owner->id);

        $rootId = $this->insertDirectory($org->id, null, 'Root', '/root-node', 0, $owner->id);
        $secretId = $this->insertSecret($org->id, $rootId, 'Secret', $owner->id);

        $this->insertAclRule('api_key', $apiKey->id, 'directory', $rootId, 'read', true);
        $this->insertAclRule('api_key', $apiKey->id, 'secret', $secretId, 'read', false);

        $this->assertTrue($this->svc->can($apiKey->id, 'read', 'secret', $secretId, $org->id));
    }

    public function test_secret_access_is_denied_when_no_acl_rule_exists(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        ['key' => $apiKey] = $this->apiKeyService->create('Key', $org->id, $owner->id);

        $rootId = $this->insertDirectory($org->id, null, 'Root', '/root-node', 0, $owner->id);
        $secretId = $this->insertSecret($org->id, $rootId, 'Secret', $owner->id);

        $this->assertFalse($this->svc->can($apiKey->id, 'read', 'secret', $secretId, $org->id));
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
            'owner_user_id' => (int) $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (string) $id;
    }

    private function insertSecret(string $orgId, string $directoryId, string $name, string $createdBy): string
    {
        $now = now()->format('Y-m-d H:i:s');
        $id = Database::getInstance()->insert('secrets', [
            'uuid' => generate_uuid(),
            'directory_id' => (int) $directoryId,
            'organization_id' => (int) $orgId,
            'name' => $name,
            'type' => 'static',
            'encrypted_value' => 'ciphertext',
            'nonce' => 'nonce',
            'requires_approval' => 0,
            'version' => 1,
            'created_by' => (int) $createdBy,
            'owner_user_id' => (int) $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (string) $id;
    }

    private function insertAclRule(
        string $subjectType,
        string $subjectId,
        string $resourceType,
        string $resourceId,
        string $permission,
        bool $isDeny,
    ): void {
        Database::getInstance()->insert('user_permissions', [
            'subject_type' => $subjectType,
            'subject_id' => (int) $subjectId,
            'resource_type' => $resourceType,
            'resource_id' => (int) $resourceId,
            'permission' => $permission,
            'is_deny' => $isDeny ? 1 : 0,
            'expires_at' => null,
            'granted_by' => null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }
}
