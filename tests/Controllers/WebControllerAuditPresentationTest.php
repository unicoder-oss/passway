<?php

declare(strict_types=1);

namespace Passway\Tests\Controllers;

use Passway\Controllers\WebController;
use Passway\Core\Application;
use Passway\Core\Database;
use Passway\Models\AuditLog;
use Passway\Services\OrganizationService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 */
final class WebControllerAuditPresentationTest extends DatabaseTestCase
{
    private WebController $controller;
    private OrganizationService $organizationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationService = new OrganizationService();
        $this->controller = Application::getInstance()->getContainer()->make(WebController::class);
    }

    protected function tearDown(): void
    {
        reset_request_locale();
        parent::tearDown();
    }

    /**
     * @dataProvider actorTargetUserLocaleProvider
     */
    public function test_it_presents_actor_target_user_and_whitelisted_details(
        string $locale,
        string $expectedTitle,
    ): void
    {
        set_request_locale($locale);

        $owner = $this->createTestUser('owner@example.com');
        $owner->update(['nickname' => 'Иван', 'updated_at' => now()->format('Y-m-d H:i:s')]);

        $target = $this->createTestUser('target@example.com');
        $target->update(['nickname' => 'Пётр', 'updated_at' => now()->format('Y-m-d H:i:s')]);

        $organization = $this->organizationService->create('Org', $owner->id);

        $entries = $this->presentEntries($organization, [
            new AuditLog(
                id: '1',
                organizationId: $organization->id,
                userId: $owner->id,
                apiKeyId: null,
                sessionId: null,
                action: 'org.member_role_update',
                resourceType: 'user',
                resourceId: $target->id,
                resourceUuid: null,
                ipAddress: '1.2.3.4',
                userAgent: null,
                detailsJson: json_encode(['role' => 'admin', 'template_uuid' => 'hidden'], JSON_THROW_ON_ERROR),
                success: true,
                createdAt: '2026-05-11 19:17:51',
            ),
        ]);

        $entry = $entries[0];

        $this->assertSame($expectedTitle, $this->flattenTitle($entry));
        $this->assertSame('Иван', $entry['actor_label']);
        $this->assertSame([], $entry['details']);
        $this->assertSame('1.2.3.4', $entry['ip_address']);
    }

    /**
     * @dataProvider secretLinkLocaleProvider
     * @param string[] $expectedDetails
     */
    public function test_it_builds_secret_link_and_api_key_detail(
        string $locale,
        string $expectedTitle,
        array $expectedDetails,
    ): void
    {
        set_request_locale($locale);

        $owner = $this->createTestUser('owner2@example.com');
        $owner->update(['nickname' => 'Мария', 'updated_at' => now()->format('Y-m-d H:i:s')]);

        $organization = $this->organizationService->create('Secrets Org', $owner->id);
        $now = now()->format('Y-m-d H:i:s');
        $db = Database::getInstance();

        $directoryUuid = generate_uuid();
        $directoryId = (string) $db->insert('directories', [
            'uuid' => $directoryUuid,
            'organization_id' => (int) $organization->id,
            'parent_id' => null,
            'name' => 'Infra',
            'depth' => 0,
            'path' => '/' . $directoryUuid,
            'created_by' => (int) $owner->id,
            'owner_user_id' => (int) $owner->id,
            'default_read_access' => 'inherit',
            'default_write_access' => 'inherit',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $secretUuid = generate_uuid();
        $secretId = (string) $db->insert('secrets', [
            'uuid' => $secretUuid,
            'directory_id' => (int) $directoryId,
            'organization_id' => (int) $organization->id,
            'name' => 'Prod DB',
            'type' => 'static',
            'encrypted_value' => 'ciphertext',
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

        $apiKeyId = (string) $db->insert('api_keys', [
            'uuid' => generate_uuid(),
            'organization_id' => (int) $organization->id,
            'user_id' => (int) $owner->id,
            'name' => 'Deploy key',
            'role' => 'editor',
            'key_hash' => hash('sha256', 'demo-key'),
            'key_prefix' => 'sv_test',
            'is_active' => 1,
            'last_used_at' => null,
            'expires_at' => null,
            'created_at' => $now,
        ]);

        $entries = $this->presentEntries($organization, [
            new AuditLog(
                id: '2',
                organizationId: $organization->id,
                userId: $owner->id,
                apiKeyId: $apiKeyId,
                sessionId: null,
                action: 'secret.read',
                resourceType: 'secret',
                resourceId: $secretId,
                resourceUuid: $secretUuid,
                ipAddress: null,
                userAgent: null,
                detailsJson: json_encode(['path' => '/api/v1/secrets'], JSON_THROW_ON_ERROR),
                success: true,
                createdAt: '2026-05-11 19:17:51',
            ),
        ]);

        $entry = $entries[0];

        $this->assertSame($expectedTitle, $this->flattenTitle($entry));
        $this->assertSame(
            '/organizations/' . $organization->uuid . '/directories/' . $directoryUuid . '/secrets/' . $secretUuid,
            $entry['title_parts'][1]['href']
        );
        $this->assertSame($expectedDetails, $entry['details']);
    }

    /** @return array<string, array{string, string}> */
    public static function actorTargetUserLocaleProvider(): array
    {
        return [
            'en' => ['en', 'Change role for user Пётр to admin'],
            'ru' => ['ru', 'Изменение роли пользователя Пётр на администратор'],
        ];
    }

    /** @return array<string, array{string, string, string[]}> */
    public static function secretLinkLocaleProvider(): array
    {
        return [
            'en' => ['en', 'View secret Prod DB', ['Via API key: Deploy key', 'Path: /api/v1/secrets']],
            'ru' => ['ru', 'Просмотр секрета Prod DB', ['Через API-ключ: Deploy key', 'Маршрут: /api/v1/secrets']],
        ];
    }

    /**
     * @param AuditLog[] $entries
     * @return array<int, array<string, mixed>>
     */
    private function presentEntries(object $organization, array $entries): array
    {
        $method = new \ReflectionMethod($this->controller, 'buildAuditViewEntries');

        /** @var array<int, array<string, mixed>> $presented */
        $presented = $method->invoke($this->controller, $organization, $entries);
        return $presented;
    }

    /** @param array<string, mixed> $entry */
    private function flattenTitle(array $entry): string
    {
        return implode('', array_map(
            static fn(array $part): string => (string) $part['text'],
            $entry['title_parts']
        ));
    }
}
