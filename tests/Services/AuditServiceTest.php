<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Services\AuditService;
use Passway\Services\LoggerService;
use Passway\Services\OrganizationService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 */
final class AuditServiceTest extends DatabaseTestCase
{
    private AuditService $svc;
    private OrganizationService $orgSvc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orgSvc = new OrganizationService();
        $this->svc = new AuditService(new LoggerService(), $this->orgSvc);
    }

    public function test_admin_can_filter_audit_entries_by_action(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);

        $this->svc->record('secret.read', $org->id, $owner->id, null, null, 'secret', '10', 'sec-1');
        $this->svc->record('secret.update', $org->id, $owner->id, null, null, 'secret', '10', 'sec-1');

        $entries = $this->svc->listForOrganization($org->id, $owner->id, ['action' => 'secret.read']);

        $this->assertCount(1, $entries);
        $this->assertSame('secret.read', $entries[0]->action);
    }

    public function test_non_admin_cannot_view_audit_log(): void
    {
        $owner = $this->createTestUser();
        $observer = $this->createTestUser('obs@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);

        $this->expectException(AuthException::class);
        $this->svc->listForOrganization($org->id, $observer->id);
    }

    public function test_cleanup_expired_deletes_old_audit_and_rate_limit_rows(): void
    {
        $db = \Passway\Core\Database::getInstance();
        $db->query(
            'INSERT INTO audit_log (action, created_at, success) VALUES (?, ?, ?)',
            ['system.old', '2000-01-01 00:00:00', 1]
        );
        $db->query(
            'INSERT INTO rate_limit_log (ip_address, bucket, count, window_start, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['1.2.3.4', 'api', 1, '2000-01-01 00:00:00', '2000-01-01 00:00:00']
        );

        $result = $this->svc->cleanupExpired();

        $this->assertSame(1, $result['audit_deleted']);
        $this->assertSame(1, $result['rate_limit_deleted']);
    }

    public function test_paginate_supports_search_and_meta(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);

        $this->svc->record('secret.read', $org->id, $owner->id, null, null, 'secret', '10', 'sec-1', '1.2.3.4', 'Agent', ['note' => 'alpha']);
        $this->svc->record('secret.update', $org->id, $owner->id, null, null, 'secret', '10', 'sec-2', '1.2.3.4', 'Agent', ['note' => 'beta']);

        $page = $this->svc->paginateForOrganization($org->id, $owner->id, [
            'search' => 'alpha',
            'limit' => 10,
            'offset' => 0,
        ]);

        $this->assertSame(1, $page['total']);
        $this->assertSame(10, $page['limit']);
        $this->assertSame(0, $page['offset']);
        $this->assertFalse($page['has_more']);
        $this->assertCount(1, $page['entries']);
        $this->assertSame('sec-1', $page['entries'][0]->resourceUuid);
    }

    public function test_paginate_supports_target_user_and_role_filters(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $target = $this->createTestUser('target@example.com');
        $other = $this->createTestUser('other@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);

        $this->svc->record(
            'group.member_add',
            $org->id,
            $owner->id,
            null,
            null,
            'group',
            '15',
            'grp-1',
            null,
            null,
            ['target_user_id' => $target->id, 'role' => 'reader']
        );
        $this->svc->record(
            'org.transfer_ownership',
            $org->id,
            $owner->id,
            null,
            null,
            'organization',
            $org->id,
            $org->uuid,
            null,
            null,
            ['new_owner_id' => $other->id]
        );

        $page = $this->svc->paginateForOrganization($org->id, $owner->id, [
            'target_user_id' => $target->id,
            'role' => 'reader',
        ]);

        $this->assertCount(1, $page['entries']);
        $this->assertSame('group.member_add', $page['entries'][0]->action);
    }

    public function test_paginate_supports_actor_kind_api_key_and_secret_filters(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $db = Database::getInstance();
        $apiKeyId = (string) $db->insert('api_keys', [
            'uuid' => 'api-key-uuid',
            'organization_id' => (int) $org->id,
            'user_id' => (int) $owner->id,
            'name' => 'Deploy key',
            'role' => 'editor',
            'key_hash' => hash('sha256', 'raw-key'),
            'key_prefix' => 'sv_test',
            'is_active' => 1,
            'last_used_at' => null,
            'expires_at' => null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        $this->svc->record('auth.unauthorized', $org->id, null, null, null, null, null, null, '1.2.3.4', null, [], false);
        $this->svc->record('auth.api_key_success', $org->id, $owner->id, $apiKeyId, null, 'api_key', $apiKeyId, 'api-key-uuid');
        $this->svc->record('secret.read', $org->id, $owner->id, null, null, 'secret', '11', 'secret-uuid');

        $apiKeyPage = $this->svc->paginateForOrganization($org->id, $owner->id, [
            'actor_kind' => 'api_key',
            'api_key_id' => $apiKeyId,
        ]);
        $this->assertCount(1, $apiKeyPage['entries']);
        $this->assertSame('auth.api_key_success', $apiKeyPage['entries'][0]->action);

        $secretPage = $this->svc->paginateForOrganization($org->id, $owner->id, [
            'secret_uuid' => 'secret-uuid',
        ]);
        $this->assertCount(1, $secretPage['entries']);
        $this->assertSame('secret.read', $secretPage['entries'][0]->action);
    }
}
