<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Services\DirectoryService;
use Passway\Services\EncryptionService;
use Passway\Services\GroupService;
use Passway\Services\OrganizationService;
use Passway\Services\PermissionService;
use Passway\Services\SchedulerService;
use Passway\Services\SecretService;
use Passway\Services\TemplateService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 * @requires extension sodium
 */
final class SchedulerServiceTest extends DatabaseTestCase
{
    private SchedulerService $scheduler;
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

        $this->scheduler = new SchedulerService();
        $this->orgService = new OrganizationService();
        $permSvc = new PermissionService($this->orgService, new GroupService($this->orgService));
        $this->dirService = new DirectoryService($this->orgService, $permSvc);
        $this->secretService = new SecretService(
            $this->orgService,
            new EncryptionService(),
            $permSvc,
            new TemplateService(),
        );

        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    public function test_is_due_matches_exact_minute_once(): void
    {
        $now = new \DateTimeImmutable('2026-05-02 02:30:00', new \DateTimeZone('UTC'));

        $this->assertTrue($this->scheduler->isDue('30 2 * * *', $now, '2026-05-02 02:29:00'));
        $this->assertFalse($this->scheduler->isDue('30 2 * * *', $now, '2026-05-02 02:30:00'));
    }

    public function test_is_due_supports_step_expression(): void
    {
        $now = new \DateTimeImmutable('2026-05-02 02:30:00', new \DateTimeZone('UTC'));

        $this->assertTrue($this->scheduler->isDue('*/15 * * * *', $now));
        $this->assertFalse($this->scheduler->isDue('*/20 * * * *', $now));
    }

    public function test_find_due_secrets_returns_only_matching_entries(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgService->create('Org', $owner->id);
        $dir = $this->dirService->create($org->id, null, 'Secrets', $owner->id);

        $due = $this->secretService->create(
            $org->id,
            $dir->uuid,
            'Due secret',
            'dynamic',
            'due-value',
            $owner->id,
        );
        $future = $this->secretService->create(
            $org->id,
            $dir->uuid,
            'Future secret',
            'dynamic',
            'future-value',
            $owner->id,
        );

        Database::getInstance()->update('secrets', [
            'rotation_schedule' => '30 2 * * *',
            'last_rotated_at'   => '2026-05-02 01:00:00',
        ], ['id' => $due->id]);

        Database::getInstance()->update('secrets', [
            'rotation_schedule' => '45 2 * * *',
            'last_rotated_at'   => '2026-05-02 01:00:00',
        ], ['id' => $future->id]);

        $results = $this->scheduler->findDueSecrets(
            new \DateTimeImmutable('2026-05-02 02:30:00', new \DateTimeZone('UTC'))
        );

        $this->assertCount(1, $results);
        $this->assertSame($due->uuid, $results[0]->uuid);
    }
}
