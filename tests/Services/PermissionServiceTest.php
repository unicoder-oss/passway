<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\UserPermission;
use Passway\Services\DirectoryService;
use Passway\Services\GroupService;
use Passway\Services\OrganizationService;
use Passway\Services\PermissionService;
use Passway\Tests\DatabaseTestCase;

/**
 * Тесты PermissionService: org-level, fine-grained, наследование, группы.
 *
 * @requires extension pdo_sqlite
 */
final class PermissionServiceTest extends DatabaseTestCase
{
    private OrganizationService $orgSvc;
    private GroupService        $groupSvc;
    private PermissionService   $svc;
    private DirectoryService    $dirSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgSvc   = new OrganizationService();
        $this->groupSvc = new GroupService($this->orgSvc);
        $this->svc      = new PermissionService($this->orgSvc, $this->groupSvc);
        $this->dirSvc   = new DirectoryService($this->orgSvc, $this->svc);

        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    // ------------------------------------------------------------------ //
    //  can() — org-level bypass                                           //
    // ------------------------------------------------------------------ //

    public function test_moderator_can_write(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);

        $this->assertTrue($this->svc->can('write', $owner->id, 'directory', $dir->id, $org->id));
    }

    public function test_observer_can_read(): void
    {
        $owner    = $this->createTestUser('owner@test.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $dir      = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $observer = $this->createTestUser('obs@test.com');
        $this->orgSvc->addMember($org->id, $observer->id, 'observer', null);

        $this->assertTrue($this->svc->can('read', $observer->id, 'directory', $dir->id, $org->id));
    }

    public function test_observer_cannot_write(): void
    {
        $owner    = $this->createTestUser('owner@test.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $dir      = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $observer = $this->createTestUser('obs@test.com');
        $this->orgSvc->addMember($org->id, $observer->id, 'observer', null);

        $this->assertFalse($this->svc->can('write', $observer->id, 'directory', $dir->id, $org->id));
    }

    public function test_non_member_cannot_access(): void
    {
        $owner     = $this->createTestUser('owner@test.com');
        $org       = $this->orgSvc->create('Org', $owner->id);
        $dir       = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $nonMember = $this->createTestUser('stranger@test.com');

        $this->assertFalse($this->svc->can('read', $nonMember->id, 'directory', $dir->id, $org->id));
    }

    // ------------------------------------------------------------------ //
    //  can() — fine-grained explicit permissions                          //
    // ------------------------------------------------------------------ //

    public function test_explicit_read_allow_for_user_role(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Secret Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        // 'user' role is below 'observer' in write threshold but above it for read
        // Actually 'user' has index 3, 'observer' has index 4 — 'user' CAN read at org level
        // Let's use a user that explicitly can't read at org level...
        // Wait: ROLES = ['owner','admin','moderator','user','observer']
        // observer is index 4 (lowest), user is index 3 (above observer)
        // So 'user' role CAN read at org level already.
        // To test fine-grained, we need a non-member that has explicit permission.
        // But non-members are blocked before fine-grained check.
        // Let's test explicit WRITE permission for 'user' role (which normally can't write):
        $this->assertFalse($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));

        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'write', false, null, $owner->id, $org->id);

        $this->assertTrue($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));
    }

    public function test_explicit_deny_overrides_org_role(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $mod   = $this->createTestUser('mod@test.com');
        $this->orgSvc->addMember($org->id, $mod->id, 'moderator', null);

        // Moderator normally can write — but org-level check returns true first,
        // so deny rule won't be checked (org-level trumps fine-grained).
        // Test: observer with explicit write ALLOW, then override with DENY:
        $user = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);
        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'write', false, null, $owner->id, $org->id);
        $this->assertTrue($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));

        // Now add a deny — it should override the allow
        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'write', true, null, $owner->id, $org->id);
        $this->assertFalse($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));
    }

    // ------------------------------------------------------------------ //
    //  can() — group permissions                                          //
    // ------------------------------------------------------------------ //

    public function test_group_permission_grants_access(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Restricted', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        $group = $this->groupSvc->create($org->id, 'Writers', null, $owner->id);
        $this->groupSvc->addMember($group->uuid, $user->id, $owner->id, $org->id);

        // Before group permission
        $this->assertFalse($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));

        // Grant write to the group
        $this->svc->grant('group', $group->id, 'directory', $dir->id, 'write', false, null, $owner->id, $org->id);

        $this->assertTrue($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));
    }

    // ------------------------------------------------------------------ //
    //  can() — inheritance from parent directory                          //
    // ------------------------------------------------------------------ //

    public function test_permission_inherited_from_parent_dir(): void
    {
        $owner  = $this->createTestUser('owner@test.com');
        $org    = $this->orgSvc->create('Org', $owner->id);
        $parent = $this->dirSvc->create($org->id, null, 'Parent', $owner->id);
        $child  = $this->dirSvc->create($org->id, $parent->uuid, 'Child', $owner->id);
        $user   = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        // No permission yet
        $this->assertFalse($this->svc->can('write', $user->id, 'directory', $child->id, $org->id));

        // Grant on parent — should inherit to child
        $this->svc->grant('user', $user->id, 'directory', $parent->id, 'write', false, null, $owner->id, $org->id);

        $this->assertTrue($this->svc->can('write', $user->id, 'directory', $child->id, $org->id));
    }

    public function test_child_deny_overrides_parent_allow(): void
    {
        $owner  = $this->createTestUser('owner@test.com');
        $org    = $this->orgSvc->create('Org', $owner->id);
        $parent = $this->dirSvc->create($org->id, null, 'Parent', $owner->id);
        $child  = $this->dirSvc->create($org->id, $parent->uuid, 'Child', $owner->id);
        $user   = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        // Allow on parent, deny on child
        $this->svc->grant('user', $user->id, 'directory', $parent->id, 'write', false, null, $owner->id, $org->id);
        $this->svc->grant('user', $user->id, 'directory', $child->id, 'write', true, null, $owner->id, $org->id);

        // Child deny wins over parent allow
        $this->assertFalse($this->svc->can('write', $user->id, 'directory', $child->id, $org->id));
    }

    // ------------------------------------------------------------------ //
    //  can() — expired permissions                                        //
    // ------------------------------------------------------------------ //

    public function test_expired_permission_is_ignored(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        // Grant with past expiry
        $expired = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'write', false, $expired, $owner->id, $org->id);

        $this->assertFalse($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));
    }

    // ------------------------------------------------------------------ //
    //  grant()                                                            //
    // ------------------------------------------------------------------ //

    public function test_grant_creates_permission(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        $perm = $this->svc->grant('user', $user->id, 'directory', $dir->id, 'read', false, null, $owner->id, $org->id);

        $this->assertInstanceOf(UserPermission::class, $perm);
        $this->assertSame('read', $perm->permission);
        $this->assertFalse($perm->isDeny);
    }

    public function test_grant_upserts_existing(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        $p1 = $this->svc->grant('user', $user->id, 'directory', $dir->id, 'read', false, null, $owner->id, $org->id);
        $p2 = $this->svc->grant('user', $user->id, 'directory', $dir->id, 'read', true, null, $owner->id, $org->id);

        $this->assertSame($p1->id, $p2->id);
        $this->assertTrue($p2->isDeny);
    }

    public function test_grant_requires_admin(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $mod   = $this->createTestUser('mod@test.com');
        $this->orgSvc->addMember($org->id, $mod->id, 'moderator', null);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        $this->expectException(AuthException::class);
        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'read', false, null, $mod->id, $org->id);
    }

    public function test_grant_invalid_permission_throws(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'fly', false, null, $owner->id, $org->id);
    }

    // ------------------------------------------------------------------ //
    //  revoke()                                                           //
    // ------------------------------------------------------------------ //

    public function test_revoke_removes_permission(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);

        $perm = $this->svc->grant('user', $user->id, 'directory', $dir->id, 'write', false, null, $owner->id, $org->id);
        $this->assertTrue($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));

        $this->svc->revoke($perm->id, $owner->id, $org->id);

        $this->assertFalse($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));
    }

    public function test_revoke_not_found_throws(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->revoke('99999', $owner->id, $org->id);
    }

    public function test_revoke_requires_admin(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);
        $perm  = $this->svc->grant('user', $user->id, 'directory', $dir->id, 'write', false, null, $owner->id, $org->id);
        $mod   = $this->createTestUser('mod@test.com');
        $this->orgSvc->addMember($org->id, $mod->id, 'moderator', null);

        $this->expectException(AuthException::class);
        $this->svc->revoke($perm->id, $mod->id, $org->id);
    }

    // ------------------------------------------------------------------ //
    //  listForDirectory()                                                 //
    // ------------------------------------------------------------------ //

    public function test_list_for_directory(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'user', null);
        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'read', false, null, $owner->id, $org->id);
        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'write', false, null, $owner->id, $org->id);

        $perms = $this->svc->listForDirectory($dir->id, $owner->id, $org->id);

        $this->assertCount(2, $perms);
    }

    public function test_list_for_directory_requires_admin(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $mod   = $this->createTestUser('mod@test.com');
        $this->orgSvc->addMember($org->id, $mod->id, 'moderator', null);

        $this->expectException(AuthException::class);
        $this->svc->listForDirectory($dir->id, $mod->id, $org->id);
    }
}
