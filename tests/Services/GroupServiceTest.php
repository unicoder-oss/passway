<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\Group;
use Passway\Models\GroupMember;
use Passway\Services\GroupService;
use Passway\Services\OrganizationService;
use Passway\Tests\DatabaseTestCase;

/**
 * Тесты GroupService: создание групп, управление участниками.
 *
 * @requires extension pdo_sqlite
 */
final class GroupServiceTest extends DatabaseTestCase
{
    private GroupService        $svc;
    private OrganizationService $orgSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgSvc = new OrganizationService();
        $this->svc    = new GroupService($this->orgSvc);

        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    // ------------------------------------------------------------------ //
    //  create()                                                           //
    // ------------------------------------------------------------------ //

    public function test_create_group(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $group = $this->svc->create($org->id, 'Developers', 'Dev team', $owner->id);

        $this->assertInstanceOf(Group::class, $group);
        $this->assertSame('Developers', $group->name);
        $this->assertSame('Dev team', $group->description);
        $this->assertSame($org->id, $group->organizationId);
    }

    public function test_create_group_without_description(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $group = $this->svc->create($org->id, 'Ops', null, $owner->id);

        $this->assertNull($group->description);
    }

    public function test_create_group_empty_name_throws(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create($org->id, '  ', null, $owner->id);
    }

    public function test_create_group_duplicate_name_throws(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $this->svc->create($org->id, 'Team', null, $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->create($org->id, 'Team', null, $owner->id);
    }

    public function test_create_group_requires_admin(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);

        $this->expectException(AuthException::class);
        $this->svc->create($org->id, 'Team', null, $user->id);
    }

    public function test_create_group_non_member_throws(): void
    {
        $owner   = $this->createTestUser('owner@test.com');
        $org     = $this->orgSvc->create('Org', $owner->id);
        $nonMember = $this->createTestUser('stranger@test.com');

        $this->expectException(AuthException::class);
        $this->svc->create($org->id, 'Team', null, $nonMember->id);
    }

    public function test_create_group_throws_in_solo_mode(): void
    {
        Database::getInstance()->query(
            "UPDATE system_config SET value = 'solo' WHERE key = 'deploy_mode'"
        );

        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->create($org->id, 'Team', null, $owner->id);
    }

    // ------------------------------------------------------------------ //
    //  list()                                                             //
    // ------------------------------------------------------------------ //

    public function test_list_returns_all_groups_sorted(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $this->svc->create($org->id, 'Zebra', null, $owner->id);
        $this->svc->create($org->id, 'Alpha', null, $owner->id);

        $groups = $this->svc->list($org->id, $owner->id);

        $this->assertCount(2, $groups);
        $this->assertSame('Alpha', $groups[0]->name);
        $this->assertSame('Zebra', $groups[1]->name);
    }

    public function test_list_requires_observer(): void
    {
        $owner     = $this->createTestUser('owner@test.com');
        $org       = $this->orgSvc->create('Org', $owner->id);
        $nonMember = $this->createTestUser('stranger@test.com');

        $this->expectException(AuthException::class);
        $this->svc->list($org->id, $nonMember->id);
    }

    // ------------------------------------------------------------------ //
    //  findInOrg()                                                        //
    // ------------------------------------------------------------------ //

    public function test_find_in_org_found(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $created = $this->svc->create($org->id, 'DevOps', null, $owner->id);

        $found = $this->svc->findInOrg($created->uuid, $org->id, $owner->id);

        $this->assertSame($created->uuid, $found->uuid);
    }

    public function test_find_in_org_wrong_org_throws(): void
    {
        $owner  = $this->createTestUser('o1@test.com');
        $org1   = $this->orgSvc->create('Org1', $owner->id);
        $owner2 = $this->createTestUser('o2@test.com');
        $org2   = $this->orgSvc->create('Org2', $owner2->id);
        $group  = $this->svc->create($org1->id, 'Team', null, $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->findInOrg($group->uuid, $org2->id, $owner2->id);
    }

    // ------------------------------------------------------------------ //
    //  delete()                                                           //
    // ------------------------------------------------------------------ //

    public function test_delete_group(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);
        $group = $this->svc->create($org->id, 'ToDelete', null, $owner->id);

        $this->svc->delete($group->uuid, $org->id, $owner->id);

        $this->assertNull(Group::findByUuid($group->uuid));
    }

    public function test_delete_requires_admin(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $group = $this->svc->create($org->id, 'Team', null, $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'editor', null);

        $this->expectException(AuthException::class);
        $this->svc->delete($group->uuid, $org->id, $user->id);
    }

    // ------------------------------------------------------------------ //
    //  addMember() / removeMember() / listMembers()                      //
    // ------------------------------------------------------------------ //

    public function test_add_member(): void
    {
        $owner  = $this->createTestUser('owner@test.com');
        $org    = $this->orgSvc->create('Org', $owner->id);
        $group  = $this->svc->create($org->id, 'Dev', null, $owner->id);
        $member = $this->createTestUser('dev@test.com');
        $this->orgSvc->addMember($org->id, $member->id, 'reader', null);

        $gm = $this->svc->addMember($group->uuid, $member->id, $owner->id, $org->id);

        $this->assertInstanceOf(GroupMember::class, $gm);
        $this->assertSame($member->id, $gm->userId);
    }

    public function test_add_member_requires_admin(): void
    {
        $owner  = $this->createTestUser('owner@test.com');
        $org    = $this->orgSvc->create('Org', $owner->id);
        $group  = $this->svc->create($org->id, 'Dev', null, $owner->id);
        $mod    = $this->createTestUser('mod@test.com');
        $target = $this->createTestUser('target@test.com');
        $this->orgSvc->addMember($org->id, $mod->id, 'editor', null);
        $this->orgSvc->addMember($org->id, $target->id, 'reader', null);

        $this->expectException(AuthException::class);
        $this->svc->addMember($group->uuid, $target->id, $mod->id, $org->id);
    }

    public function test_add_member_non_org_user_throws(): void
    {
        $owner    = $this->createTestUser('owner@test.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $group    = $this->svc->create($org->id, 'Dev', null, $owner->id);
        $stranger = $this->createTestUser('stranger@test.com');

        $this->expectException(\RuntimeException::class);
        $this->svc->addMember($group->uuid, $stranger->id, $owner->id, $org->id);
    }

    public function test_add_member_twice_throws(): void
    {
        $owner  = $this->createTestUser('owner@test.com');
        $org    = $this->orgSvc->create('Org', $owner->id);
        $group  = $this->svc->create($org->id, 'Dev', null, $owner->id);
        $member = $this->createTestUser('dev@test.com');
        $this->orgSvc->addMember($org->id, $member->id, 'reader', null);
        $this->svc->addMember($group->uuid, $member->id, $owner->id, $org->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->addMember($group->uuid, $member->id, $owner->id, $org->id);
    }

    public function test_remove_member(): void
    {
        $owner  = $this->createTestUser('owner@test.com');
        $org    = $this->orgSvc->create('Org', $owner->id);
        $group  = $this->svc->create($org->id, 'Dev', null, $owner->id);
        $member = $this->createTestUser('dev@test.com');
        $this->orgSvc->addMember($org->id, $member->id, 'reader', null);
        $this->svc->addMember($group->uuid, $member->id, $owner->id, $org->id);

        $this->svc->removeMember($group->uuid, $member->id, $owner->id, $org->id);

        $grpObj = Group::findByUuid($group->uuid);
        $this->assertNotNull($grpObj);
        $this->assertNull(GroupMember::findByGroupAndUser($grpObj->id, $member->id));
    }

    public function test_remove_member_not_in_group_throws(): void
    {
        $owner  = $this->createTestUser('owner@test.com');
        $org    = $this->orgSvc->create('Org', $owner->id);
        $group  = $this->svc->create($org->id, 'Dev', null, $owner->id);
        $member = $this->createTestUser('dev@test.com');
        $this->orgSvc->addMember($org->id, $member->id, 'reader', null);

        $this->expectException(\RuntimeException::class);
        $this->svc->removeMember($group->uuid, $member->id, $owner->id, $org->id);
    }

    public function test_list_members(): void
    {
        $owner  = $this->createTestUser('owner@test.com');
        $org    = $this->orgSvc->create('Org', $owner->id);
        $group  = $this->svc->create($org->id, 'Dev', null, $owner->id);
        $m1     = $this->createTestUser('m1@test.com');
        $m2     = $this->createTestUser('m2@test.com');
        $this->orgSvc->addMember($org->id, $m1->id, 'reader', null);
        $this->orgSvc->addMember($org->id, $m2->id, 'reader', null);
        $this->svc->addMember($group->uuid, $m1->id, $owner->id, $org->id);
        $this->svc->addMember($group->uuid, $m2->id, $owner->id, $org->id);

        $members = $this->svc->listMembers($group->uuid, $org->id, $owner->id);

        $this->assertCount(2, $members);
    }

    public function test_get_user_group_ids(): void
    {
        $owner = $this->createTestUser('owner@test.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $g1    = $this->svc->create($org->id, 'G1', null, $owner->id);
        $g2    = $this->svc->create($org->id, 'G2', null, $owner->id);
        $user  = $this->createTestUser('user@test.com');
        $this->orgSvc->addMember($org->id, $user->id, 'reader', null);
        $this->svc->addMember($g1->uuid, $user->id, $owner->id, $org->id);
        $this->svc->addMember($g2->uuid, $user->id, $owner->id, $org->id);

        $ids = $this->svc->getUserGroupIds($user->id, $org->id);

        $this->assertCount(2, $ids);
        $this->assertContains($g1->id, $ids);
        $this->assertContains($g2->id, $ids);
    }
}
