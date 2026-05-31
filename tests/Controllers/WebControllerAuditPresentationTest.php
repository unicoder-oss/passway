<?php

declare(strict_types=1);

namespace Passway\Tests\Controllers;

use Passway\Controllers\WebController;
use Passway\Core\Application;
use Passway\Core\AuthContext;
use Passway\Core\Database;
use Passway\Core\Request;
use Passway\Models\AuditLog;
use Passway\Services\DirectoryService;
use Passway\Services\OrganizationService;
use Passway\Services\SecretService;
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
        AuthContext::reset();

        $this->organizationService = new OrganizationService();
        $this->controller = Application::getInstance()->getContainer()->make(WebController::class);
    }

    protected function tearDown(): void
    {
        AuthContext::reset();
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
        $owner->update(['nickname' => 'Иван', 'avatar_color' => '#123456', 'avatar_path' => '/uploads/users/owner.webp', 'updated_at' => now()->format('Y-m-d H:i:s')]);

        $target = $this->createTestUser('target@example.com');
        $target->update(['nickname' => 'Пётр', 'avatar_color' => '#654321', 'updated_at' => now()->format('Y-m-d H:i:s')]);

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
        $this->assertSame('Иван <owner@example.com>', $entry['actor_label']);
        $this->assertSame([
            'kind' => 'user',
            'path' => '/uploads/users/owner.webp',
            'initial' => 'И',
            'color' => '#123456',
        ], $entry['actor_avatar']);
        $this->assertSame([
            'kind' => 'user',
            'path' => '',
            'initial' => 'П',
            'color' => '#654321',
        ], $entry['title_parts'][1]['avatar']);
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

    public function test_it_keeps_api_key_actor_text_only(): void
    {
        $owner = $this->createTestUser('api-owner@example.com');
        $organization = $this->organizationService->create('API Org', $owner->id);

        $apiKeyId = (string) Database::getInstance()->insert('api_keys', [
            'uuid' => generate_uuid(),
            'organization_id' => (int) $organization->id,
            'user_id' => (int) $owner->id,
            'name' => 'Automation key',
            'role' => 'editor',
            'key_hash' => hash('sha256', 'automation-key'),
            'key_prefix' => 'sv_auto',
            'is_active' => 1,
            'last_used_at' => null,
            'expires_at' => null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $entries = $this->presentEntries($organization, [
            new AuditLog(
                id: '3',
                organizationId: $organization->id,
                userId: null,
                apiKeyId: $apiKeyId,
                sessionId: null,
                action: 'auth.api_key_success',
                resourceType: 'api_key',
                resourceId: $apiKeyId,
                resourceUuid: null,
                ipAddress: null,
                userAgent: null,
                detailsJson: '{}',
                success: true,
                createdAt: '2026-05-11 19:17:51',
            ),
        ]);

        $entry = $entries[0];

        $this->assertSame(__('ui.audit.api_key_actor', ['name' => 'Automation key']), $entry['actor_label']);
        $this->assertNull($entry['actor_avatar']);
    }

    public function test_it_adds_avatar_to_linked_organization_title_part(): void
    {
        $owner = $this->createTestUser('org-owner@example.com');
        $organization = $this->organizationService->create('Avatar Org', $owner->id);
        $organization->update(['avatar_path' => '/uploads/organizations/avatar.webp']);
        $organization = \Passway\Models\Organization::findById($organization->id);
        self::assertNotNull($organization);

        $entries = $this->presentEntries($organization, [
            new AuditLog(
                id: '4',
                organizationId: $organization->id,
                userId: $owner->id,
                apiKeyId: null,
                sessionId: null,
                action: 'org.create',
                resourceType: 'organization',
                resourceId: $organization->id,
                resourceUuid: $organization->uuid,
                ipAddress: null,
                userAgent: null,
                detailsJson: '{}',
                success: true,
                createdAt: '2026-05-11 19:17:51',
            ),
        ]);

        $organizationPart = $entries[0]['title_parts'][2];

        $this->assertSame('/organizations/' . $organization->uuid, $organizationPart['href']);
        $this->assertSame([
            'kind' => 'organization',
            'path' => '/uploads/organizations/avatar.webp',
            'initial' => 'A',
            'color' => avatar_fallback_color(),
        ], $organizationPart['avatar']);
    }

    public function test_it_presents_approval_request_with_linked_secret(): void
    {
        set_request_locale('ru');

        $owner = $this->createTestUser('approval-owner@example.com');
        $requester = $this->createTestUser('approval-requester@example.com');
        $organization = $this->organizationService->create('Approval Org', $owner->id);
        $now = now()->format('Y-m-d H:i:s');
        $db = Database::getInstance();

        $directoryUuid = generate_uuid();
        $directoryId = (string) $db->insert('directories', [
            'uuid' => $directoryUuid,
            'organization_id' => (int) $organization->id,
            'parent_id' => null,
            'name' => 'Prod',
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
            'name' => 'Payroll API',
            'type' => 'static',
            'encrypted_value' => 'ciphertext',
            'nonce' => str_repeat('a', 48),
            'template_id' => null,
            'requires_approval' => 1,
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

        $approvalUuid = generate_uuid();
        $approvalId = (string) $db->insert('approval_requests', [
            'uuid' => $approvalUuid,
            'secret_id' => (int) $secretId,
            'requested_by' => (int) $requester->id,
            'requester_type' => 'user',
            'requester_id' => (int) $requester->id,
            'request_type' => 'read',
            'reason' => null,
            'status' => 'pending',
            'approved_by' => null,
            'rejection_reason' => null,
            'expires_at' => now()->modify('+1 hour')->format('Y-m-d H:i:s'),
            'access_token_hash' => null,
            'created_at' => $now,
            'resolved_at' => null,
        ]);

        $entries = $this->presentEntries($organization, [
            new AuditLog(
                id: '5',
                organizationId: $organization->id,
                userId: $requester->id,
                apiKeyId: null,
                sessionId: null,
                action: 'approval.request_create',
                resourceType: 'approval_request',
                resourceId: $approvalId,
                resourceUuid: $approvalUuid,
                ipAddress: null,
                userAgent: null,
                detailsJson: json_encode(['request_type' => 'read', 'secret_uuid' => $secretUuid], JSON_THROW_ON_ERROR),
                success: true,
                createdAt: '2026-05-11 19:17:51',
            ),
            new AuditLog(
                id: '6',
                organizationId: $organization->id,
                userId: $owner->id,
                apiKeyId: null,
                sessionId: null,
                action: 'approval.request_approve',
                resourceType: 'approval_request',
                resourceId: $approvalId,
                resourceUuid: $approvalUuid,
                ipAddress: null,
                userAgent: null,
                detailsJson: json_encode(['secret_uuid' => $secretUuid], JSON_THROW_ON_ERROR),
                success: true,
                createdAt: '2026-05-11 19:18:51',
            ),
        ]);

        $this->assertSame('Создание запроса одобрения для секрета Payroll API', $this->flattenTitle($entries[0]));
        $this->assertSame(
            '/organizations/' . $organization->uuid . '/directories/' . $directoryUuid . '/secrets/' . $secretUuid,
            $entries[0]['title_parts'][1]['href']
        );
        $this->assertSame([], $entries[0]['details']);
        $this->assertSame('Одобрение запроса одобрения для секрета Payroll API пользователю approval-requester@example.com', $this->flattenTitle($entries[1]));
        $this->assertSame(
            '/organizations/' . $organization->uuid . '/directories/' . $directoryUuid . '/secrets/' . $secretUuid,
            $entries[1]['title_parts'][1]['href']
        );
    }

    public function test_it_presents_secret_transfer_owner_with_target_avatar(): void
    {
        set_request_locale('ru');

        $owner = $this->createTestUser('secret-owner@example.com');
        $target = $this->createTestUser('new-secret-owner@example.com');
        $target->update(['nickname' => 'Анна', 'avatar_color' => '#336699', 'updated_at' => now()->format('Y-m-d H:i:s')]);
        $organization = $this->organizationService->create('Transfer Org', $owner->id);
        $now = now()->format('Y-m-d H:i:s');
        $db = Database::getInstance();
        $directoryUuid = generate_uuid();
        $directoryId = (string) $db->insert('directories', [
            'uuid' => $directoryUuid,
            'organization_id' => (int) $organization->id,
            'parent_id' => null,
            'name' => 'Vault',
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
            'name' => 'Root password',
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

        $entries = $this->presentEntries($organization, [
            new AuditLog(
                id: '6',
                organizationId: $organization->id,
                userId: $owner->id,
                apiKeyId: null,
                sessionId: null,
                action: 'secret.transfer_ownership',
                resourceType: 'secret',
                resourceId: $secretId,
                resourceUuid: $secretUuid,
                ipAddress: null,
                userAgent: null,
                detailsJson: json_encode(['new_owner_id' => $target->id], JSON_THROW_ON_ERROR),
                success: true,
                createdAt: '2026-05-11 19:17:51',
            ),
        ]);

        $this->assertSame('Передача владения секретом Root password пользователю Анна <new-secret-owner@example.com>', $this->flattenTitle($entries[0]));
        $this->assertSame('/organizations/' . $organization->uuid . '/directories/' . $directoryUuid . '/secrets/' . $secretUuid, $entries[0]['title_parts'][1]['href']);
        $this->assertSame([
            'kind' => 'user',
            'path' => '',
            'initial' => 'А',
            'color' => '#336699',
        ], $entries[0]['title_parts'][3]['avatar']);
        $this->assertSame([], $entries[0]['details']);
    }

    public function test_it_presents_deleted_group_name_from_audit_details_and_uuid_in_details(): void
    {
        set_request_locale('ru');

        $owner = $this->createTestUser('group-owner@example.com');
        $target = $this->createTestUser('group-target@example.com');
        $organization = $this->organizationService->create('Group Org', $owner->id);

        $entries = $this->presentEntries($organization, [
            new AuditLog(
                id: '7',
                organizationId: $organization->id,
                userId: $owner->id,
                apiKeyId: null,
                sessionId: null,
                action: 'group.member_add',
                resourceType: 'group',
                resourceId: '999999',
                resourceUuid: 'deleted-group-uuid',
                ipAddress: null,
                userAgent: null,
                detailsJson: json_encode([
                    'target_user_id' => $target->id,
                    'group_name' => 'DevOps',
                    'group_uuid' => 'deleted-group-uuid',
                ], JSON_THROW_ON_ERROR),
                success: true,
                createdAt: '2026-05-11 19:17:51',
            ),
        ]);

        $this->assertSame('Добавление пользователя group-target@example.com в группу DevOps', $this->flattenTitle($entries[0]));
        $this->assertSame(['UUID группы: deleted-group-uuid'], $entries[0]['details']);
    }

    public function test_it_presents_deleted_group_uuid_without_resource_prefix_when_name_is_missing(): void
    {
        set_request_locale('ru');

        $owner = $this->createTestUser('group-owner-uuid@example.com');
        $organization = $this->organizationService->create('Group UUID Org', $owner->id);

        $entries = $this->presentEntries($organization, [
            new AuditLog(
                id: '8',
                organizationId: $organization->id,
                userId: $owner->id,
                apiKeyId: null,
                sessionId: null,
                action: 'group.delete',
                resourceType: 'group',
                resourceId: '999999',
                resourceUuid: 'missing-group-uuid',
                ipAddress: null,
                userAgent: null,
                detailsJson: '{}',
                success: true,
                createdAt: '2026-05-11 19:17:51',
            ),
        ]);

        $this->assertSame('Удаление группы missing-group-uuid', $this->flattenTitle($entries[0]));
    }

    public function test_secret_page_open_does_not_audit_read_until_reveal(): void
    {
        $container = Application::getInstance()->getContainer();
        $directoryService = $container->make(DirectoryService::class);
        $secretService = $container->make(SecretService::class);

        $owner = $this->createTestUser('reveal-owner@example.com');
        $organization = $this->organizationService->create('Reveal Org', $owner->id);
        $directory = $directoryService->create($organization->id, null, 'Secrets', $owner->id);
        $secret = $secretService->create($organization->id, $directory->uuid, 'Token', 'static', 'actual-secret', $owner->id);
        AuthContext::setUser($owner);

        $showRequest = new Request([], [], [], [], [], '');
        $showRequest->setRouteParams([
            'uuid' => $organization->uuid,
            'dirUuid' => $directory->uuid,
            'secUuid' => $secret->uuid,
        ]);

        $this->controller->showSecret($showRequest);
        $this->assertSame(0, $this->countSecretReads($secret->uuid));

        $revealRequest = new Request(['REQUEST_METHOD' => 'POST'], [], [], [], [], '');
        $revealRequest->setRouteParams([
            'uuid' => $organization->uuid,
            'dirUuid' => $directory->uuid,
            'secUuid' => $secret->uuid,
        ]);

        $response = $this->controller->revealSecret($revealRequest);
        $payload = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['success']);
        $this->assertSame('actual-secret', $payload['data']['value']);
        $this->assertSame(1, $this->countSecretReads($secret->uuid));
    }

    /** @return array<string, array{string, string}> */
    public static function actorTargetUserLocaleProvider(): array
    {
        return [
            'en' => ['en', 'Change role for user Пётр <target@example.com> to admin'],
            'ru' => ['ru', 'Изменение роли пользователя Пётр <target@example.com> на администратор'],
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

    private function countSecretReads(string $secretUuid): int
    {
        return (int) Database::getInstance()->fetchColumn(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'secret.read' AND resource_uuid = ?",
            [$secretUuid]
        );
    }
}
