<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Services\EncryptionService;
use Passway\Services\OrganizationIntegrationService;
use Passway\Services\OrganizationService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 * @requires extension sodium
 */
final class OrganizationIntegrationServiceTest extends DatabaseTestCase
{
    private OrganizationIntegrationService $svc;
    private OrganizationService $orgSvc;

    public static function setUpBeforeClass(): void
    {
        $_ENV['MASTER_KEY'] = \bin2hex(\random_bytes(32));
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgSvc = new OrganizationService();
        $this->svc = new OrganizationIntegrationService($this->orgSvc, new EncryptionService());
    }

    public function test_admin_can_create_and_decrypt_integration_credentials(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);

        $serviceId = Database::getInstance()->insert('rotation_services', [
            'uuid'          => generate_uuid(),
            'name'          => 'Stub Rotator',
            'url'           => 'https://rotator.example.test',
            'health_url'    => 'https://rotator.example.test/health',
            'spec_json'     => \json_encode([
                'integration_schema' => [
                    'fields' => [
                        ['name' => 'endpoint', 'label' => 'Endpoint', 'type' => 'string', 'required' => true],
                        ['name' => 'token', 'label' => 'Token', 'type' => 'secret_text', 'required' => true],
                    ],
                ],
            ], \JSON_UNESCAPED_SLASHES),
            'is_active'     => 1,
            'is_verified'   => 1,
            'last_check_at' => now()->format('Y-m-d H:i:s'),
            'created_by'    => (int) $owner->id,
            'created_at'    => now()->format('Y-m-d H:i:s'),
            'updated_at'    => now()->format('Y-m-d H:i:s'),
        ]);

        $serviceUuid = (string) Database::getInstance()->fetchColumn(
            'SELECT uuid FROM rotation_services WHERE id = ?',
            [(int) $serviceId]
        );

        $integration = $this->svc->create($org->id, $serviceUuid, 'Prod DB', [
            'endpoint' => 'db.internal',
            'token'    => 'secret-token',
        ], $owner->id);

        $this->assertSame('Prod DB', $integration->name);

        $decoded = $this->svc->getDecryptedCredentials($integration->id);
        $this->assertSame('db.internal', $decoded['endpoint']);
        $this->assertSame('secret-token', $decoded['token']);
    }

    public function test_non_admin_cannot_manage_integrations(): void
    {
        $owner = $this->createTestUser();
        $user = $this->createTestUser('user@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $user->id, 'observer', null);

        $serviceId = Database::getInstance()->insert('rotation_services', [
            'uuid'          => generate_uuid(),
            'name'          => 'Stub Rotator',
            'url'           => 'https://rotator.example.test',
            'health_url'    => 'https://rotator.example.test/health',
            'spec_json'     => \json_encode([
                'integration_schema' => [
                    'fields' => [
                        ['name' => 'token', 'label' => 'Token', 'type' => 'secret_text', 'required' => true],
                    ],
                ],
            ], \JSON_UNESCAPED_SLASHES),
            'is_active'     => 1,
            'is_verified'   => 1,
            'last_check_at' => now()->format('Y-m-d H:i:s'),
            'created_by'    => (int) $owner->id,
            'created_at'    => now()->format('Y-m-d H:i:s'),
            'updated_at'    => now()->format('Y-m-d H:i:s'),
        ]);

        $serviceUuid = (string) Database::getInstance()->fetchColumn(
            'SELECT uuid FROM rotation_services WHERE id = ?',
            [(int) $serviceId]
        );

        $this->expectException(AuthException::class);
        $this->svc->create($org->id, $serviceUuid, 'Prod DB', ['token' => 'x'], $user->id);
    }
}
