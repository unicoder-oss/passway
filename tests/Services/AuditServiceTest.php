<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

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
        $this->orgSvc->addMember($org->id, $observer->id, 'observer', null);

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
}
