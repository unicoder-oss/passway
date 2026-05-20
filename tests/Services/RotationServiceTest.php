<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Models\SecretVersion;
use Passway\Services\DirectoryService;
use Passway\Services\EncryptionService;
use Passway\Services\GroupService;
use Passway\Services\OrganizationIntegrationService;
use Passway\Services\OrganizationService;
use Passway\Services\PermissionService;
use Passway\Services\RotationHttpClient;
use Passway\Services\RotationService;
use Passway\Services\SchedulerService;
use Passway\Services\SecretService;
use Passway\Services\TemplateService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 * @requires extension sodium
 */
final class RotationServiceTest extends DatabaseTestCase
{
    private RotationService $rotationService;
    private SecretService $secretService;
    private OrganizationService $orgService;
    private DirectoryService $dirService;

    public static function setUpBeforeClass(): void
    {
        $_ENV['MASTER_KEY'] = \bin2hex(\random_bytes(32));
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgService = new OrganizationService();
        $permSvc = new PermissionService($this->orgService, new GroupService($this->orgService));
        $templateSvc = new TemplateService();
        $validValues = ['current-secret' => true, 'rotated-secret' => true];
        $client = new RotationHttpClient(function (string $method, string $url, ?array $payload) use (&$validValues): array {
            if (\str_ends_with($url, '/validate')) {
                $value = (string) ($payload['value'] ?? '');
                return ['status' => 200, 'body' => ['valid' => $validValues[$value] ?? false]];
            }

            if (\str_ends_with($url, '/rotate')) {
                if (($payload['rollback'] ?? false) === true) {
                    $validValues[(string) ($payload['current_value'] ?? '')] = false;
                    $validValues[(string) ($payload['target_value'] ?? '')] = true;
                    return ['status' => 200, 'body' => ['value' => (string) ($payload['target_value'] ?? '')]];
                }

                $validValues[(string) ($payload['current_value'] ?? '')] = false;
                $validValues['rotated-secret'] = true;
                return ['status' => 200, 'body' => ['value' => 'rotated-secret']];
            }

            return ['status' => 404, 'body' => []];
        });
        $integrationSvc = new OrganizationIntegrationService($this->orgService, new EncryptionService());

        $this->dirService = new DirectoryService($this->orgService, $permSvc);
        $this->secretService = new SecretService(
            $this->orgService,
            new EncryptionService(),
            $permSvc,
            $templateSvc,
        );
        $this->rotationService = new RotationService(
            $this->secretService,
            $templateSvc,
            new SchedulerService(),
            $client,
            $integrationSvc,
        );

        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    public function test_run_due_skips_due_template_secret(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        $dir = $this->dirService->create($org->id, null, 'Secrets', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $templateSecret = $this->secretService->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Template secret',
            (string) $template['uuid'],
            $owner->id,
        );
        $this->secretService->create(
            $org->id,
            $dir->uuid,
            'Dynamic secret',
            'dynamic',
            'manual-value',
            $owner->id,
        );

        Database::getInstance()->update('secrets', [
            'rotation_schedule' => '30 2 * * *',
            'last_rotated_at'   => '2026-05-02 01:00:00',
        ], ['id' => $templateSecret->id]);

        $result = $this->rotationService->runDue(
            new \DateTimeImmutable('2026-05-02 02:30:00', new \DateTimeZone('UTC'))
        );

        $this->assertSame(0, $result['rotated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);
    }

    public function test_rotate_dynamic_secret_uses_external_integration_and_records_api_history(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        $dir = $this->dirService->create($org->id, null, 'Secrets', $owner->id);

        $serviceId = Database::getInstance()->insert('rotation_services', [
            'uuid'          => generate_uuid(),
            'name'          => 'External Rotator',
            'url'           => 'https://rotator.example.test',
            'health_url'    => 'https://rotator.example.test/health',
            'spec_json'     => '{}',
            'is_active'     => 1,
            'is_verified'   => 1,
            'last_check_at' => now()->format('Y-m-d H:i:s'),
            'created_by'    => (int) $owner->id,
            'created_at'    => now()->format('Y-m-d H:i:s'),
            'updated_at'    => now()->format('Y-m-d H:i:s'),
        ]);

        $integrationUuid = generate_uuid();
        $enc = (new EncryptionService())->encrypt('{"token":"abc"}', $integrationUuid);
        $integrationId = Database::getInstance()->insert('organization_integrations', [
            'uuid'                  => $integrationUuid,
            'organization_id'       => (int) $org->id,
            'rotation_service_id'   => (int) $serviceId,
            'name'                  => 'Prod DB',
            'encrypted_credentials' => $enc->value,
            'credentials_nonce'     => $enc->nonce,
            'is_active'             => 1,
            'created_by'            => (int) $owner->id,
            'created_at'            => now()->format('Y-m-d H:i:s'),
            'updated_at'            => now()->format('Y-m-d H:i:s'),
        ]);

        $secret = $this->secretService->create(
            $org->id,
            $dir->uuid,
            'Dynamic DB Password',
            'dynamic',
            'current-secret',
            $owner->id,
            $integrationUuid,
            '30 2 * * *',
        );

        $rotated = $this->rotationService->rotateDynamicSecret($secret->uuid, $org->id);
        ['value' => $value] = $this->secretService->get($secret->uuid, $org->id, $owner->id);

        $this->assertSame('rotated-secret', $value);
        $this->assertSame(2, $rotated->version);

        $versions = SecretVersion::findBySecretId($secret->id);
        $this->assertCount(1, $versions);
        $this->assertSame('api', $versions[0]->rotationType);
    }

    public function test_rotate_secret_now_rejects_template_secret(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        $dir = $this->dirService->create($org->id, null, 'Secrets', $owner->id);
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $secret = $this->secretService->createFromTemplate(
            $org->id,
            $dir->uuid,
            'Template secret',
            (string) $template['uuid'],
            $owner->id,
        );

        $this->expectException(\RuntimeException::class);
        $this->rotationService->rotateSecretNow($secret->uuid, $org->id);
    }
}
