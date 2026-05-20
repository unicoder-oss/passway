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
        $activeOutputs = [
            'private_key' => 'ssh-private-v1',
            'public_key' => 'ssh-public-v1',
            'fingerprint' => 'SHA256:v1',
        ];
        $client = new RotationHttpClient(function (string $method, string $url, ?array $payload) use (&$activeOutputs): array {
            if (\str_ends_with($url, '/provision')) {
                return ['status' => 200, 'body' => ['outputs' => $activeOutputs]];
            }

            if (\str_ends_with($url, '/validate')) {
                $outputs = \is_array($payload['outputs'] ?? null) ? $payload['outputs'] : [];
                $isValid = ($outputs['private_key'] ?? null) === $activeOutputs['private_key']
                    && ($outputs['public_key'] ?? null) === $activeOutputs['public_key'];

                return ['status' => 200, 'body' => ['valid' => $isValid]];
            }

            if (\str_ends_with($url, '/rotate')) {
                if (($payload['rollback'] ?? false) === true) {
                    $target = \is_array($payload['target_outputs'] ?? null) ? $payload['target_outputs'] : [];
                    if ($target !== []) {
                        $activeOutputs = $target;
                    }

                    return ['status' => 200, 'body' => ['outputs' => $activeOutputs]];
                }

                $activeOutputs = [
                    'private_key' => 'ssh-private-v2',
                    'public_key' => 'ssh-public-v2',
                    'fingerprint' => 'SHA256:v2',
                ];

                return ['status' => 200, 'body' => ['outputs' => $activeOutputs]];
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

    public function test_provision_dynamic_secret_uses_external_integration_and_persists_outputs(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        $dir = $this->dirService->create($org->id, null, 'Secrets', $owner->id);
        $integrationUuid = $this->createRotationIntegration($org->id, $owner->id);

        $secret = $this->rotationService->provisionDynamicSecret(
            $org->id,
            $dir->uuid,
            'Deploy SSH Key',
            $integrationUuid,
            '30 2 * * *',
            ['username' => 'deploy', 'account_mode' => 'existing_user'],
            $owner->id,
        );
        ['value' => $value] = $this->secretService->get($secret->uuid, $org->id, $owner->id);
        $dynamicView = $this->secretService->getDynamicSecretView($secret->uuid, $org->id, $owner->id);

        $this->assertSame('ssh-private-v1', $value);
        $this->assertSame('private_key', $dynamicView['primary_field']);
        $this->assertSame('ssh-public-v1', $dynamicView['outputs']['public_key']);
        $this->assertSame('SHA256:v1', $dynamicView['outputs']['fingerprint']);
        $this->assertSame('deploy', $dynamicView['input']['username']);
    }

    public function test_run_due_rotates_due_dynamic_secret(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        $dir = $this->dirService->create($org->id, null, 'Secrets', $owner->id);
        $integrationUuid = $this->createRotationIntegration($org->id, $owner->id);

        $secret = $this->rotationService->provisionDynamicSecret(
            $org->id,
            $dir->uuid,
            'Deploy SSH Key',
            $integrationUuid,
            '30 2 * * *',
            ['username' => 'deploy', 'account_mode' => 'existing_user'],
            $owner->id,
        );

        $result = $this->rotationService->runDue(
            new \DateTimeImmutable('2026-05-02 02:30:00', new \DateTimeZone('UTC'))
        );
        ['value' => $value] = $this->secretService->get($secret->uuid, $org->id, $owner->id);

        $this->assertSame(1, $result['rotated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('ssh-private-v2', $value);
    }

    public function test_rotate_dynamic_secret_updates_secret_and_history(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        $dir = $this->dirService->create($org->id, null, 'Secrets', $owner->id);
        $integrationUuid = $this->createRotationIntegration($org->id, $owner->id);

        $secret = $this->rotationService->provisionDynamicSecret(
            $org->id,
            $dir->uuid,
            'Deploy SSH Key',
            $integrationUuid,
            '30 2 * * *',
            ['username' => 'deploy', 'account_mode' => 'existing_user'],
            $owner->id,
        );

        $rotated = $this->rotationService->rotateDynamicSecret($secret->uuid, $org->id);
        ['value' => $value] = $this->secretService->get($secret->uuid, $org->id, $owner->id);
        $dynamicView = $this->secretService->getDynamicSecretView($secret->uuid, $org->id, $owner->id);

        $this->assertSame('ssh-private-v2', $value);
        $this->assertSame(2, $rotated->version);
        $this->assertSame('ssh-public-v2', $dynamicView['outputs']['public_key']);
        $this->assertSame('SHA256:v2', $dynamicView['outputs']['fingerprint']);

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

    private function createRotationIntegration(string $orgId, string $userId): string
    {
        $serviceId = Database::getInstance()->insert('rotation_services', [
            'uuid' => generate_uuid(),
            'name' => 'SSH Rotator',
            'url' => 'https://rotator.example.test',
            'health_url' => 'https://rotator.example.test/health',
            'spec_json' => \json_encode([
                'service' => ['name' => 'SSH Rotator'],
                'integration_schema' => [
                    'fields' => [
                        ['name' => 'host', 'label' => 'Host', 'type' => 'string', 'required' => true],
                        ['name' => 'port', 'label' => 'Port', 'type' => 'integer', 'required' => false, 'default' => 22],
                        ['name' => 'login', 'label' => 'Login', 'type' => 'string', 'required' => true],
                        ['name' => 'private_key', 'label' => 'Private key', 'type' => 'secret_text', 'required' => true],
                    ],
                ],
                'secret_schema' => [
                    'fields' => [
                        ['name' => 'username', 'label' => 'Username', 'type' => 'string', 'required' => true],
                        [
                            'name' => 'account_mode',
                            'label' => 'Account mode',
                            'type' => 'enum',
                            'required' => true,
                            'options' => [
                                ['value' => 'existing_user', 'label' => 'Existing user'],
                                ['value' => 'create_user', 'label' => 'Create user'],
                            ],
                        ],
                    ],
                ],
                'output_schema' => [
                    'primary_secret_field' => 'private_key',
                    'fields' => [
                        ['name' => 'private_key', 'label' => 'Private key', 'type' => 'secret_text', 'required' => true],
                        ['name' => 'public_key', 'label' => 'Public key', 'type' => 'readonly_text', 'required' => true],
                        ['name' => 'fingerprint', 'label' => 'Fingerprint', 'type' => 'readonly_text', 'required' => false],
                    ],
                ],
            ], \JSON_UNESCAPED_SLASHES),
            'is_active' => 1,
            'is_verified' => 1,
            'last_check_at' => now()->format('Y-m-d H:i:s'),
            'created_by' => (int) $userId,
            'created_at' => now()->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $integrationUuid = generate_uuid();
        $enc = (new EncryptionService())->encrypt('{"host":"srv","port":22,"login":"root","private_key":"pem"}', $integrationUuid);
        Database::getInstance()->insert('organization_integrations', [
            'uuid' => $integrationUuid,
            'organization_id' => (int) $orgId,
            'rotation_service_id' => (int) $serviceId,
            'name' => 'Server SSH',
            'encrypted_credentials' => $enc->value,
            'credentials_nonce' => $enc->nonce,
            'is_active' => 1,
            'created_by' => (int) $userId,
            'created_at' => now()->format('Y-m-d H:i:s'),
            'updated_at' => now()->format('Y-m-d H:i:s'),
        ]);

        return $integrationUuid;
    }
}
