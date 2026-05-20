<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\InviteLink;
use Passway\Models\Organization;
use Passway\Models\OrganizationMember;
use Passway\Services\InviteService;
use Passway\Services\OrganizationService;
use Passway\Services\TokenService;
use Passway\Tests\DatabaseTestCase;

/**
 * Тесты InviteService: создание, верификация, принятие, отзыв.
 *
 * @requires extension pdo_sqlite
 */
final class InviteServiceTest extends DatabaseTestCase
{
    private InviteService       $svc;
    private OrganizationService $orgSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgSvc = new OrganizationService();
        $this->svc    = new InviteService(new TokenService(), $this->orgSvc);

        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    // ------------------------------------------------------------------ //
    //  createJoinOrgInvite()                                              //
    // ------------------------------------------------------------------ //

    public function test_create_join_invite_returns_invite_link(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $invite = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id);

        $this->assertInstanceOf(InviteLink::class, $invite);
        $this->assertSame(InviteLink::TYPE_JOIN_ORG, $invite->type);
        $this->assertSame('reader', $invite->role);
        $this->assertSame(64, \strlen($invite->token));
    }

    public function test_create_join_invite_throws_for_non_admin(): void
    {
        $owner  = $this->createTestUser();
        $member = $this->createTestUser('member@example.com');
        $org    = $this->orgSvc->create('Org', $owner->id);

        $this->orgSvc->addMember($org->id, $member->id, 'reader', null);

        $this->expectException(AuthException::class);
        $this->svc->createJoinOrgInvite($org->id, 'reader', $member->id);
    }

    public function test_create_admin_invite_requires_owner(): void
    {
        $owner = $this->createTestUser();
        $admin = $this->createTestUser('admin@example.com');
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->orgSvc->addMember($org->id, $admin->id, 'admin', null);

        $this->expectException(AuthException::class);
        $this->svc->createJoinOrgInvite($org->id, 'admin', $admin->id);
    }

    public function test_owner_can_create_admin_invite(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);

        $invite = $this->svc->createJoinOrgInvite($org->id, 'admin', $owner->id);
        $this->assertSame('admin', $invite->role);
    }

    public function test_create_join_invite_throws_for_owner_role(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createJoinOrgInvite($org->id, 'owner', $owner->id);
    }

    // ------------------------------------------------------------------ //
    //  createOrgInvite()                                                  //
    // ------------------------------------------------------------------ //

    public function test_create_org_invite_in_team_mode(): void
    {
        $user   = $this->createTestUser();
        $invite = $this->svc->createOrgInvite($user->id);

        $this->assertSame(InviteLink::TYPE_CREATE_ORG, $invite->type);
        $this->assertSame('owner', $invite->role);
    }

    public function test_create_org_invite_throws_in_solo_mode(): void
    {
        Database::getInstance()->query(
            "UPDATE system_config SET value = 'solo' WHERE key = 'deploy_mode'"
        );

        $user = $this->createTestUser();

        $this->expectException(\RuntimeException::class);
        $this->svc->createOrgInvite($user->id);
    }

    // ------------------------------------------------------------------ //
    //  findValid()                                                        //
    // ------------------------------------------------------------------ //

    public function test_find_valid_returns_invite(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $invite = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id);

        $found = $this->svc->findValid($invite->token);
        $this->assertSame($invite->uuid, $found->uuid);
    }

    public function test_find_valid_throws_for_nonexistent_token(): void
    {
        $this->expectException(AuthException::class);
        $this->svc->findValid(\str_repeat('0', 64));
    }

    public function test_find_valid_throws_for_expired_invite(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $invite = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id, 1);

        // Принудительно истечь инвайт
        Database::getInstance()->update(
            'invite_links',
            ['expires_at' => \date('Y-m-d H:i:s', \time() - 1)],
            ['id' => $invite->id]
        );

        $this->expectException(AuthException::class);
        $this->svc->findValid($invite->token);
    }

    // ------------------------------------------------------------------ //
    //  acceptJoinOrg()                                                    //
    // ------------------------------------------------------------------ //

    public function test_accept_join_org_adds_user_to_org(): void
    {
        $owner    = $this->createTestUser();
        $acceptor = $this->createTestUser('acceptor@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);

        $invite = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id);
        $joinedOrg = $this->svc->acceptJoinOrg($invite->token, $acceptor->id);

        $this->assertSame($org->id, $joinedOrg->id);
        $member = OrganizationMember::findByOrgAndUser($org->id, $acceptor->id);
        $this->assertNotNull($member);
        $this->assertSame('reader', $member->role);
    }

    public function test_accept_marks_invite_as_used(): void
    {
        $owner    = $this->createTestUser();
        $acceptor = $this->createTestUser('acceptor@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);

        $invite = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id);
        $this->svc->acceptJoinOrg($invite->token, $acceptor->id);

        $this->expectException(AuthException::class);
        $this->svc->findValid($invite->token);
    }

    public function test_accept_throws_when_already_member(): void
    {
        $owner    = $this->createTestUser();
        $acceptor = $this->createTestUser('acceptor@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);

        $this->orgSvc->addMember($org->id, $acceptor->id, 'reader', null);

        $invite = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->acceptJoinOrg($invite->token, $acceptor->id);
    }

    // ------------------------------------------------------------------ //
    //  revoke()                                                           //
    // ------------------------------------------------------------------ //

    public function test_revoke_by_admin_succeeds(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);
        $invite = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id);

        $this->svc->revoke($invite->uuid, $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->findValid($invite->token);
    }

    public function test_revoke_throws_for_non_admin(): void
    {
        $owner   = $this->createTestUser();
        $member  = $this->createTestUser('member@example.com');
        $org     = $this->orgSvc->create('Org', $owner->id);

        $this->orgSvc->addMember($org->id, $member->id, 'reader', null);
        $invite = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->revoke($invite->uuid, $member->id);
    }

    public function test_revoke_used_invite_throws(): void
    {
        $owner    = $this->createTestUser();
        $acceptor = $this->createTestUser('acceptor@example.com');
        $org      = $this->orgSvc->create('Org', $owner->id);
        $invite   = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id);

        $this->svc->acceptJoinOrg($invite->token, $acceptor->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->revoke($invite->uuid, $owner->id);
    }

    // ------------------------------------------------------------------ //
    //  listActive()                                                       //
    // ------------------------------------------------------------------ //

    public function test_list_active_excludes_expired(): void
    {
        $owner  = $this->createTestUser();
        $org    = $this->orgSvc->create('Org', $owner->id);

        $active  = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id);
        $expired = $this->svc->createJoinOrgInvite($org->id, 'reader', $owner->id, 1);

        Database::getInstance()->update(
            'invite_links',
            ['expires_at' => \date('Y-m-d H:i:s', \time() - 1)],
            ['id' => $expired->id]
        );

        $list = $this->svc->listActive($org->id);
        $this->assertCount(1, $list);
        $this->assertSame($active->uuid, $list[0]->uuid);
    }
}
