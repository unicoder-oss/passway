<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Services\EncryptionService;
use Passway\Models\UserPermission;
use Passway\Services\DirectoryService;
use Passway\Services\GroupService;
use Passway\Services\OrganizationService;
use Passway\Services\PermissionService;
use Passway\Services\SecretService;
use Passway\Tests\DatabaseTestCase;

/**
 * PermissionService tests: org-level, fine-grained, inheritance, groups.
 *
 * @requires extension pdo_sqlite
 */
final class PermissionServiceTest extends DatabaseTestCase
{
    private OrganizationService $orgSvc;
    private GroupService        $groupSvc;
    private PermissionService   $svc;
    private DirectoryService    $dirSvc;
    private SecretService       $secretSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgSvc   = new OrganizationService();
        $this->groupSvc = new GroupService($this->orgSvc);
        $this->svc      = new PermissionService($this->orgSvc, $this->groupSvc);
        $this->dirSvc   = new DirectoryService($this->orgSvc, $this->svc);
        $this->secretSvc = new SecretService($this->orgSvc, new EncryptionService(), $this->svc);

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
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);

        $this->assertTrue($this->svc->can('read', $observer->id, 'directory', $dir->id, $org->id));
    }

    public function test_observer_cannot_write(): void
    {
        $owner    = $this->createTestUser('owner@test.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $dir      = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $observer = $this->createTestUser('obs@test.com');
        $this->orgSvc->addMember($org->id, $observer->id, 'reader', null);

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

    public function test_explicit_write_allow_for_reader_role(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Secret Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

        // The reader role can already read, but cannot write without an ACL.
        $this->assertFalse($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));

        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'write', false, null, $owner->id, $org->id);

        $this->assertTrue($this->svc->can('write', $user->id, 'directory', $dir->id, $org->id));
    }

    public function test_explicit_deny_overrides_org_role(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $editor = $this->createTestUser('mod@test.com');
        $this->orgSvc->addMember($org->id, $editor->id, 'editor', null);

        $this->assertTrue($this->svc->can('write', $editor->id, 'directory', $dir->id, $org->id));

        $this->svc->grant('user', $editor->id, 'directory', $dir->id, 'write', true, null, $owner->id, $org->id);

        $this->assertFalse($this->svc->can('write', $editor->id, 'directory', $dir->id, $org->id));
    }

    public function test_explicit_deny_overrides_reader_read_role(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $reader = $this->createTestUser('reader@test.com');
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);

        $this->assertTrue($this->svc->can('read', $reader->id, 'directory', $dir->id, $org->id));

        $this->svc->grant('user', $reader->id, 'directory', $dir->id, 'read', true, null, $owner->id, $org->id);

        $this->assertFalse($this->svc->can('read', $reader->id, 'directory', $dir->id, $org->id));
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
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

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
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

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
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

        // Allow on parent, deny on child
        $this->svc->grant('user', $user->id, 'directory', $parent->id, 'write', false, null, $owner->id, $org->id);
        $this->svc->grant('user', $user->id, 'directory', $child->id, 'write', true, null, $owner->id, $org->id);

        // Child deny wins over parent allow
        $this->assertFalse($this->svc->can('write', $user->id, 'directory', $child->id, $org->id));
    }

    public function test_child_allow_overrides_parent_deny_for_read(): void
    {
        $owner  = $this->createTestUser('owner@test.com');
        $org    = $this->orgSvc->create('Org', $owner->id);
        $parent = $this->dirSvc->create($org->id, null, 'Parent', $owner->id);
        $child  = $this->dirSvc->create($org->id, $parent->uuid, 'Child', $owner->id);
        $user   = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

        $this->svc->grant('user', $user->id, 'directory', $parent->id, 'read', true, null, $owner->id, $org->id);
        $this->svc->grant('user', $user->id, 'directory', $child->id, 'read', false, null, $owner->id, $org->id);

        $this->assertFalse($this->svc->can('read', $user->id, 'directory', $parent->id, $org->id));
        $this->assertTrue($this->svc->can('read', $user->id, 'directory', $child->id, $org->id));
    }

    public function test_secret_allow_overrides_directory_deny(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Restricted', $owner->id);
        $secret = $this->secretSvc->create($org->id, $dir->uuid, 'Hidden secret', 'static', 'value', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'read', true, null, $owner->id, $org->id);
        $this->svc->grant('user', $user->id, 'secret', $secret->id, 'read', false, null, $owner->id, $org->id);

        $this->assertFalse($this->svc->can('read', $user->id, 'directory', $dir->id, $org->id));
        $this->assertTrue($this->svc->can('read', $user->id, 'secret', $secret->id, $org->id));
    }

    public function test_directory_default_deny_blocks_reader_without_acl(): void
    {
        $owner = $this->createTestUser('owner-default-deny@test.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Restricted', $owner->id, 'deny', 'deny');
        $reader = $this->createTestUser('reader-default-deny@test.com');
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);

        $this->assertFalse($this->svc->can('read', $reader->id, 'directory', $dir->id, $org->id));
        $this->assertFalse($this->svc->can('write', $reader->id, 'directory', $dir->id, $org->id));
    }

    public function test_directory_default_deny_is_inherited_by_child_directory(): void
    {
        $owner = $this->createTestUser('owner-inherit-deny@test.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $parent = $this->dirSvc->create($org->id, null, 'Parent', $owner->id);
        $child = $this->dirSvc->create($org->id, $parent->uuid, 'Child', $owner->id);
        $this->dirSvc->updateAccessPolicy($parent->uuid, $org->id, $owner->id, 'deny', 'deny');
        $reader = $this->createTestUser('reader-inherit-deny@test.com');
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);

        $this->assertFalse($this->svc->can('read', $reader->id, 'directory', $child->id, $org->id));
        $this->assertFalse($this->svc->can('write', $reader->id, 'directory', $child->id, $org->id));
    }

    public function test_secret_inherits_directory_default_deny(): void
    {
        $owner = $this->createTestUser('owner-secret-deny@test.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Restricted', $owner->id);
        $secret = $this->secretSvc->create($org->id, $dir->uuid, 'Secret', 'static', 'value', $owner->id);
        $this->dirSvc->updateAccessPolicy($dir->uuid, $org->id, $owner->id, 'deny', 'deny');
        $reader = $this->createTestUser('reader-secret-deny@test.com');
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);

        $this->assertFalse($this->svc->can('read', $reader->id, 'secret', $secret->id, $org->id));
    }

    public function test_exact_acl_overrides_inherited_default_deny(): void
    {
        $owner = $this->createTestUser('owner-override@test.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $parent = $this->dirSvc->create($org->id, null, 'Parent', $owner->id);
        $child = $this->dirSvc->create($org->id, $parent->uuid, 'Child', $owner->id);
        $this->dirSvc->updateAccessPolicy($parent->uuid, $org->id, $owner->id, 'deny', 'deny');
        $reader = $this->createTestUser('reader-override@test.com');
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);

        $this->assertFalse($this->svc->can('read', $reader->id, 'directory', $child->id, $org->id));

        $this->svc->grant('user', $reader->id, 'directory', $child->id, 'read', false, null, $owner->id, $org->id);

        $this->assertTrue($this->svc->can('read', $reader->id, 'directory', $child->id, $org->id));
    }

    public function test_inherit_policy_falls_back_to_org_role(): void
    {
        $owner = $this->createTestUser('owner-fallback@test.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $reader = $this->createTestUser('reader-fallback@test.com');
        $this->orgSvc->addMember($org->id, $reader->id, 'reader', null);

        $this->assertTrue($this->svc->can('read', $reader->id, 'directory', $dir->id, $org->id));
        $this->assertFalse($this->svc->can('write', $reader->id, 'directory', $dir->id, $org->id));
    }

    public function test_group_acl_overrides_default_deny(): void
    {
        $owner = $this->createTestUser('owner-group-override@test.com');
        $org = $this->orgSvc->create('Org', $owner->id);
        $dir = $this->dirSvc->create($org->id, null, 'Restricted', $owner->id, 'deny', 'deny');
        $user = $this->createTestUser('user-group-override@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);
        $group = $this->groupSvc->create($org->id, 'Readers', null, $owner->id);
        $this->groupSvc->addMember($group->uuid, $user->id, $owner->id, $org->id);

        $this->assertFalse($this->svc->can('read', $user->id, 'directory', $dir->id, $org->id));

        $this->svc->grant('group', $group->id, 'directory', $dir->id, 'read', false, null, $owner->id, $org->id);

        $this->assertTrue($this->svc->can('read', $user->id, 'directory', $dir->id, $org->id));
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
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

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
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

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
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

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
        $this->orgSvc->addMember($org->id, $mod->id, 'editor', null);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

        $this->expectException(AuthException::class);
        $this->svc->grant('user', $user->id, 'directory', $dir->id, 'read', false, null, $mod->id, $org->id);
    }

    public function test_grant_invalid_permission_throws(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Docs', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

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
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

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
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);
        $perm  = $this->svc->grant('user', $user->id, 'directory', $dir->id, 'write', false, null, $owner->id, $org->id);
        $mod   = $this->createTestUser('mod@test.com');
        $this->orgSvc->addMember($org->id, $mod->id, 'editor', null);

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
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);
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
        $this->orgSvc->addMember($org->id, $mod->id, 'editor', null);

        $this->expectException(AuthException::class);
        $this->svc->listForDirectory($dir->id, $mod->id, $org->id);
    }
}
