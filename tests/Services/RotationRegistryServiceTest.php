<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Exceptions\AuthException;
use Passway\Services\RotationHttpClient;
use Passway\Services\RotationRegistryService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 */
final class RotationRegistryServiceTest extends DatabaseTestCase
{
    private RotationRegistryService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $client = new RotationHttpClient(function (string $method, string $url, ?array $payload): array {
            if (\str_ends_with($url, '/health')) {
                return ['status' => 200, 'body' => ['status' => 'ok']];
            }

            if (\str_ends_with($url, '/spec')) {
                return ['status' => 200, 'body' => [
                    'name' => 'Stub Rotation Service',
                    'supported_secret_types' => ['dynamic'],
                ]];
            }

            return ['status' => 404, 'body' => []];
        });

        $this->svc = new RotationRegistryService($client);
    }

    public function test_setup_admin_can_create_rotation_service(): void
    {
        $admin = $this->createTestUser('admin@example.com');

        $service = $this->svc->create('Postgres Rotator', 'https://rotator.example.test', $admin->id);

        $this->assertSame('Postgres Rotator', $service->name);
        $this->assertTrue($service->isVerified);
        $this->assertSame('dynamic', $service->spec()['supported_secret_types'][0]);
    }

    public function test_non_setup_admin_cannot_create_rotation_service(): void
    {
        $this->createTestUser('admin@example.com');
        $second = $this->createTestUser('user@example.com');

        $this->expectException(AuthException::class);
        $this->svc->create('Rotator', 'https://rotator.example.test', $second->id);
    }

    public function test_verify_refreshes_spec_data(): void
    {
        $admin = $this->createTestUser('admin@example.com');
        $service = $this->svc->create('Rotator', 'https://rotator.example.test', $admin->id);

        $verified = $this->svc->verify($service->uuid, $admin->id);

        $this->assertTrue($verified->isVerified);
        $this->assertSame('Stub Rotation Service', $verified->spec()['name']);
    }
}
