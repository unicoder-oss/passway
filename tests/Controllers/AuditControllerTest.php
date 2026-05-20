<?php

declare(strict_types=1);

namespace Passway\Tests\Controllers;

use Passway\Controllers\AuditController;
use Passway\Core\AuthContext;
use Passway\Core\Database;
use Passway\Core\Request;
use Passway\Services\AuditService;
use Passway\Services\LoggerService;
use Passway\Services\OrganizationService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 */
final class AuditControllerTest extends DatabaseTestCase
{
    private OrganizationService $organizationService;
    private AuditController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        AuthContext::reset();

        $this->organizationService = new OrganizationService();
        $this->controller = new AuditController(
            new AuditService(new LoggerService(), $this->organizationService),
            $this->organizationService,
        );
    }

    protected function tearDown(): void
    {
        AuthContext::reset();
        parent::tearDown();
    }

    public function test_search_secrets_returns_matching_items_for_admin(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org = $this->organizationService->create('Org', $owner->id);
        AuthContext::setUser($owner);

        $db = Database::getInstance();
        $now = now()->format('Y-m-d H:i:s');

        $directoryId = (int) $db->insert('directories', [
            'uuid' => generate_uuid(),
            'organization_id' => (int) $org->id,
            'parent_id' => null,
            'name' => 'Root',
            'depth' => 0,
            'path' => '/' . generate_uuid(),
            'created_by' => (int) $owner->id,
            'owner_user_id' => (int) $owner->id,
            'default_read_access' => 'inherit',
            'default_write_access' => 'inherit',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (['Production DB', 'Production API', 'Local Dev'] as $name) {
            $db->insert('secrets', [
                'uuid' => generate_uuid(),
                'directory_id' => $directoryId,
                'organization_id' => (int) $org->id,
                'name' => $name,
                'type' => 'static',
                'encrypted_value' => 'cipher',
                'nonce' => str_repeat('a', 48),
                'template_id' => null,
                'requires_approval' => 0,
                'rotation_integration_id' => null,
                'rotation_schedule' => null,
                'last_rotated_at' => null,
                'version' => 1,
                'created_by' => (int) $owner->id,
                'owner_user_id' => (int) $owner->id,
                'default_read_access' => 'inherit',
                'default_write_access' => 'inherit',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }

        $request = new Request(
            server: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/organizations/' . $org->uuid . '/audit/secrets'],
            get: ['q' => 'Prod'],
            post: [],
            cookie: [],
            files: [],
            rawBody: '',
        );
        $request->setRouteParams(['uuid' => $org->uuid]);

        $response = $this->controller->searchSecrets($request);
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertCount(2, $payload['data']['items']);
        $this->assertSame('Production API', $payload['data']['items'][0]['name']);
        $this->assertSame('Production DB', $payload['data']['items'][1]['name']);
    }
}
