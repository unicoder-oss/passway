<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Organization;
use Passway\Models\OrganizationMember;
use Passway\Services\OrganizationService;
use Passway\Tests\DatabaseTestCase;

/**
 * OrganizationService tests: creation, members, roles, solo mode.
 *
 * @requires extension pdo_sqlite
 */
final class OrganizationServiceTest extends DatabaseTestCase
{
    private OrganizationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new OrganizationService();

        // Set team mode by default (solo limits to 1 org)
        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    // ------------------------------------------------------------------ //
    //  create()                                                           //
    // ------------------------------------------------------------------ //

    public function test_create_returns_organization(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org   = $this->svc->create('Acme Corp', $owner->id);

        $this->assertInstanceOf(Organization::class, $org);
        $this->assertSame('Acme Corp', $org->name);
        $this->assertSame('acme-corp', $org->slug);
        $this->assertSame($owner->id, $org->ownerId);
        $this->assertTrue($org->isActive);
    }

    public function test_create_writes_audit_log(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org   = $this->svc->create('Audit Corp', $owner->id);

        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'org.create' AND resource_uuid = ? ORDER BY id DESC LIMIT 1",
            [$org->uuid]
        );

        $this->assertNotNull($row);
    }

    public function test_create_adds_owner_as_member(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org   = $this->svc->create('Acme Corp', $owner->id);

        $member = OrganizationMember::findByOrgAndUser($org->id, $owner->id);
        $this->assertNotNull($member);
        $this->assertSame('owner', $member->role);
    }

    public function test_create_generates_unique_slug_on_conflict(): void
    {
        $owner = $this->createTestUser('owner@example.com');
        $org1  = $this->svc->create('Acme', $owner->id);
        $org2  = $this->svc->create('Acme', $owner->id);

        $this->assertSame('acme', $org1->slug);
        $this->assertSame('acme-2', $org2->slug);
    }

    public function test_create_throws_for_empty_name(): void
    {
        $owner = $this->createTestUser();
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create('', $owner->id);
    }

    public function test_create_allows_multiple_orgs_in_solo_mode(): void
    {
        Database::getInstance()->query(
            "UPDATE system_config SET value = 'solo' WHERE key = 'deploy_mode'"
        );

        $owner = $this->createTestUser();
        $org1 = $this->svc->create('First Org', $owner->id);
        $org2 = $this->svc->create('Second Org', $owner->id);

        $this->assertNotSame($org1->id, $org2->id);
    }

    public function test_create_allows_multiple_orgs_in_team_mode(): void
    {
        $owner = $this->createTestUser();
        $org1  = $this->svc->create('Org One', $owner->id);
        $org2  = $this->svc->create('Org Two', $owner->id);

        $this->assertNotSame($org1->id, $org2->id);
    }

    // ------------------------------------------------------------------ //
    //  getForUser() / getMemberRole() / hasPermission()                   //
    // ------------------------------------------------------------------ //

    public function test_get_for_user_returns_users_orgs(): void
    {
        $owner = $this->createTestUser();
        $other = $this->createTestUser('other@example.com');

        $org1 = $this->svc->create('Org A', $owner->id);
        $this->svc->create('Org B', $other->id);

        $orgs = $this->svc->getForUser($owner->id);
        $this->assertCount(1, $orgs);
        $this->assertSame($org1->id, $orgs[0]->id);
    }

    public function test_get_member_role_returns_correct_role(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->svc->create('Org', $owner->id);

        $this->assertSame('owner', $this->svc->getMemberRole($org->id, $owner->id));
    }

    public function test_get_member_role_returns_null_for_non_member(): void
    {
        $owner   = $this->createTestUser();
        $stranger = $this->createTestUser('stranger@example.com');
        $org     = $this->svc->create('Org', $owner->id);

        $this->assertNull($this->svc->getMemberRole($org->id, $stranger->id));
    }

    public function test_has_permission_owner_passes_all_roles(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->svc->create('Org', $owner->id);

        foreach (OrganizationMember::ROLES as $role) {
            $this->assertTrue($this->svc->hasPermission($org->id, $owner->id, $role));
        }
    }

    public function test_has_permission_observer_cannot_admin(): void
    {
        $owner    = $this->createTestUser();
        $observer = $this->createTestUser('obs@example.com');
        $org      = $this->svc->create('Org', $owner->id);

        $this->svc->addMember($org->id, $observer->id, 'reader', null);

        $this->assertFalse($this->svc->hasPermission($org->id, $observer->id, 'admin'));
        $this->assertTrue($this->svc->hasPermission($org->id, $observer->id, 'reader'));
    }

    // ------------------------------------------------------------------ //
    //  addMember()                                                        //
    // ------------------------------------------------------------------ //

    public function test_add_member_creates_membership(): void
    {
        $owner  = $this->createTestUser();
        $newbie = $this->createTestUser('newbie@example.com');
        $org    = $this->svc->create('Org', $owner->id);

        $member = $this->svc->addMember($org->id, $newbie->id, 'reader', $owner->id);

        $this->assertSame('reader', $member->role);
        $this->assertSame($newbie->id, $member->userId);
    }

    public function test_add_member_throws_if_already_member(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->svc->create('Org', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->addMember($org->id, $owner->id, 'reader', null);
    }

    public function test_add_member_throws_for_invalid_role(): void
    {
        $owner  = $this->createTestUser();
        $newbie = $this->createTestUser('newbie@example.com');
        $org    = $this->svc->create('Org', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->addMember($org->id, $newbie->id, 'superadmin', null);
    }

    // ------------------------------------------------------------------ //
    //  updateMemberRole()                                                 //
    // ------------------------------------------------------------------ //

    public function test_update_member_role_by_owner(): void
    {
        $owner  = $this->createTestUser();
        $member = $this->createTestUser('member@example.com');
        $org    = $this->svc->create('Org', $owner->id);

        $this->svc->addMember($org->id, $member->id, 'reader', null);
        $this->svc->updateMemberRole($org->id, $member->id, 'editor', $owner->id);

        $this->assertSame('editor', $this->svc->getMemberRole($org->id, $member->id));
    }

    public function test_update_member_role_throws_for_non_admin(): void
    {
        $owner  = $this->createTestUser();
        $member = $this->createTestUser('member@example.com');
        $org    = $this->svc->create('Org', $owner->id);

        $this->svc->addMember($org->id, $member->id, 'reader', null);

        $this->expectException(AuthException::class);
        $this->svc->updateMemberRole($org->id, $owner->id, 'reader', $member->id);
    }

    public function test_update_owner_role_throws(): void
    {
        $owner  = $this->createTestUser();
        $admin  = $this->createTestUser('admin@example.com');
        $org    = $this->svc->create('Org', $owner->id);

        $this->svc->addMember($org->id, $admin->id, 'admin', null);

        $this->expectException(AuthException::class);
        $this->svc->updateMemberRole($org->id, $owner->id, 'reader', $admin->id);
    }

    // ------------------------------------------------------------------ //
    //  removeMember()                                                     //
    // ------------------------------------------------------------------ //

    public function test_remove_member_by_admin(): void
    {
        $owner  = $this->createTestUser();
        $admin  = $this->createTestUser('admin@example.com');
        $member = $this->createTestUser('member@example.com');
        $org    = $this->svc->create('Org', $owner->id);

        $this->svc->addMember($org->id, $admin->id, 'admin', null);
        $this->svc->addMember($org->id, $member->id, 'reader', null);
        $this->svc->removeMember($org->id, $member->id, $admin->id);

        $this->assertNull(OrganizationMember::findByOrgAndUser($org->id, $member->id));
    }

    public function test_user_can_leave_org_themselves(): void
    {
        $owner  = $this->createTestUser();
        $member = $this->createTestUser('member@example.com');
        $org    = $this->svc->create('Org', $owner->id);

        $this->svc->addMember($org->id, $member->id, 'reader', null);
        $this->svc->removeMember($org->id, $member->id, $member->id);

        $this->assertNull(OrganizationMember::findByOrgAndUser($org->id, $member->id));
    }

    public function test_remove_owner_throws(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->svc->create('Org', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->removeMember($org->id, $owner->id, $owner->id);
    }

    // ------------------------------------------------------------------ //
    //  transferOwnership()                                                //
    // ------------------------------------------------------------------ //

    public function test_transfer_ownership(): void
    {
        $owner  = $this->createTestUser();
        $newOwn = $this->createTestUser('newowner@example.com');
        $org    = $this->svc->create('Org', $owner->id);

        $this->svc->addMember($org->id, $newOwn->id, 'admin', null);
        $this->svc->transferOwnership($org->id, $newOwn->id, $owner->id);

        $this->assertSame('admin', $this->svc->getMemberRole($org->id, $owner->id));
        $this->assertSame('owner', $this->svc->getMemberRole($org->id, $newOwn->id));
    }

    public function test_transfer_ownership_throws_for_non_owner(): void
    {
        $owner  = $this->createTestUser();
        $admin  = $this->createTestUser('admin@example.com');
        $newOwn = $this->createTestUser('newowner@example.com');
        $org    = $this->svc->create('Org', $owner->id);

        $this->svc->addMember($org->id, $admin->id, 'admin', null);
        $this->svc->addMember($org->id, $newOwn->id, 'reader', null);

        $this->expectException(AuthException::class);
        $this->svc->transferOwnership($org->id, $newOwn->id, $admin->id);
    }

    // ------------------------------------------------------------------ //
    //  listMembers()                                                      //
    // ------------------------------------------------------------------ //

    public function test_list_members_returns_all(): void
    {
        $owner  = $this->createTestUser();
        $member = $this->createTestUser('member@example.com');
        $org    = $this->svc->create('Org', $owner->id);
        $this->svc->addMember($org->id, $member->id, 'reader', null);

        $members = $this->svc->listMembers($org->id);
        $this->assertCount(2, $members);
    }
}
