<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\ApprovalRequest;
use Passway\Models\User;
use Passway\Services\ApprovalService;
use Passway\Services\DirectoryService;
use Passway\Services\EncryptionService;
use Passway\Services\GroupService;
use Passway\Services\OrganizationService;
use Passway\Services\PermissionService;
use Passway\Services\SecretService;
use Passway\Tests\DatabaseTestCase;

/**
 * Тесты ApprovalService: создание запросов, одобрение, отклонение, токены.
 *
 * @requires extension pdo_sqlite
 * @requires extension sodium
 */
final class ApprovalServiceTest extends DatabaseTestCase
{
    private ApprovalService   $svc;
    private SecretService     $secretSvc;
    private DirectoryService  $dirSvc;
    private OrganizationService $orgSvc;

    public static function setUpBeforeClass(): void
    {
        $_ENV['MASTER_KEY'] = \bin2hex(\random_bytes(32));
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgSvc   = new OrganizationService();
        $permSvc        = new PermissionService($this->orgSvc, new GroupService($this->orgSvc));
        $encSvc         = new EncryptionService();
        $this->dirSvc   = new DirectoryService($this->orgSvc, $permSvc);
        $this->secretSvc = new SecretService($this->orgSvc, $encSvc, $permSvc);
        $this->svc      = new ApprovalService($this->orgSvc, $encSvc);

        // team-режим
        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Создаёт орг. с owner, добавляет admin, создаёт каталог и секрет с requires_approval=true.
     *
     * @return array{owner: User, admin: User, requester: User, orgId: string, secretUuid: string}
     */
    private function setupApprovalScenario(): array
    {
        $owner    = $this->createTestUser('owner@test.com');
        $admin    = $this->createTestUser('admin@test.com');
        $requester = $this->createTestUser('requester@test.com');

        $org = $this->orgSvc->create('TestOrg', $owner->id);
        $this->orgSvc->addMember($org->id, $admin->id, 'admin', $owner->id);
        $this->orgSvc->addMember($org->id, $requester->id, 'reader', $owner->id);

        $dir = $this->dirSvc->create($org->id, null, 'Secrets', $owner->id);

        // Создаём секрет с requires_approval=true
        $secret = $this->secretSvc->create($org->id, $dir->uuid, 'DB Password', 'static', 'supersecret', $owner->id);
        Database::getInstance()->update('secrets', ['requires_approval' => 1], ['id' => $secret->id]);

        return [
            'owner'      => $owner,
            'admin'      => $admin,
            'requester'  => $requester,
            'orgId'      => $org->id,
            'secretUuid' => $secret->uuid,
        ];
    }

    // ------------------------------------------------------------------ //
    //  request()                                                          //
    // ------------------------------------------------------------------ //

    public function test_request_creates_pending_approval(): void
    {
        ['requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', 'Need access for debugging', $requester->id, $orgId);

        $this->assertInstanceOf(ApprovalRequest::class, $req);
        $this->assertSame('pending', $req->status);
        $this->assertSame('read', $req->requestType);
        $this->assertSame('Need access for debugging', $req->reason);
        $this->assertSame($requester->id, $req->requestedBy);
        $this->assertNull($req->accessTokenHash);
        $this->assertNull($req->resolvedAt);
    }

    public function test_request_assigns_secret_owner_as_reviewer(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req      = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $reviewers = \Passway\Models\ApprovalReviewer::findByRequestId($req->id);

        // Единственный ревьювер — owner секрета
        $reviewerIds = \array_map(fn($r) => $r->reviewerId, $reviewers);
        $this->assertSame([$owner->id], $reviewerIds);
    }

    public function test_request_fails_for_secret_owner_with_direct_access(): void
    {
        ['owner' => $owner, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->request($secUuid, 'read', null, $owner->id, $orgId);
    }

    public function test_request_fails_for_non_member(): void
    {
        ['orgId' => $orgId, 'secretUuid' => $secUuid] = $this->setupApprovalScenario();
        $outsider = $this->createTestUser('outsider@test.com');

        $this->expectException(AuthException::class);
        $this->svc->request($secUuid, 'read', null, $outsider->id, $orgId);
    }

    public function test_request_fails_if_secret_not_requires_approval(): void
    {
        $owner = $this->createTestUser('owner2@test.com');
        $org   = $this->orgSvc->create('Org2', $owner->id);
        $dir   = $this->dirSvc->create($org->id, null, 'Dir', $owner->id);
        $secret = $this->secretSvc->create($org->id, $dir->uuid, 'Normal', 'static', 'value', $owner->id);
        // requires_approval остаётся false

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->request($secret->uuid, 'read', null, $owner->id, $org->id);
    }

    public function test_request_fails_on_duplicate_pending(): void
    {
        ['requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);

        $this->expectException(\RuntimeException::class);
        $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
    }

    public function test_request_invalid_type(): void
    {
        ['requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->request($secUuid, 'admin', null, $requester->id, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  listMy() / listPending()                                           //
    // ------------------------------------------------------------------ //

    public function test_list_my_returns_own_requests(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);

        $mine = $this->svc->listMy($requester->id, $orgId);
        $this->assertCount(1, $mine);
        $this->assertSame($requester->id, $mine[0]->requestedBy);

        // Owner видит только свои запросы (нет запросов от owner в этом тесте)
        $ownerMine = $this->svc->listMy($owner->id, $orgId);
        $this->assertCount(0, $ownerMine);
    }

    public function test_list_pending_returns_empty_for_non_reviewer(): void
    {
        ['admin' => $admin, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);

        $pending = $this->svc->listPending($admin->id, $orgId);

        $this->assertCount(0, $pending);
    }

    public function test_list_pending_shows_pending_requests(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);

        $pending = $this->svc->listPending($owner->id, $orgId);
        $this->assertCount(1, $pending);
        $this->assertSame('pending', $pending[0]->status);
    }

    // ------------------------------------------------------------------ //
    //  get()                                                              //
    // ------------------------------------------------------------------ //

    public function test_get_by_requester(): void
    {
        ['requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $created = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $fetched = $this->svc->get($created->uuid, $requester->id, $orgId);

        $this->assertSame($created->uuid, $fetched->uuid);
    }

    public function test_get_by_secret_owner(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $created = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $fetched = $this->svc->get($created->uuid, $owner->id, $orgId);

        $this->assertSame($created->uuid, $fetched->uuid);
    }

    public function test_get_denied_for_other_user(): void
    {
        ['requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $other   = $this->createTestUser('other@test.com');
        $this->orgSvc->addMember($orgId, $other->id, 'reader', $requester->id);

        $created = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);

        $this->expectException(AuthException::class);
        $this->svc->get($created->uuid, $other->id, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  approve()                                                          //
    // ------------------------------------------------------------------ //

    public function test_approve_generates_token(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        ['request' => $approved, 'token' => $token] = $this->svc->approve($req->uuid, $owner->id, $orgId);

        $this->assertSame('approved', $approved->status);
        $this->assertSame($owner->id, $approved->approvedBy);
        $this->assertNotNull($approved->accessTokenHash);
        $this->assertNotEmpty($token);
        // Токен - hex 64 символа (32 байта)
        $this->assertSame(64, \strlen($token));
        // В БД хранится hash, не сам токен
        $this->assertNotSame($token, $approved->accessTokenHash);
        $this->assertSame(\hash('sha256', $token), $approved->accessTokenHash);

        $createdAudit = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'approval.request_create' AND resource_uuid = ? ORDER BY id DESC LIMIT 1",
            [$req->uuid]
        );
        $approvedAudit = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'approval.request_approve' AND resource_uuid = ? ORDER BY id DESC LIMIT 1",
            [$req->uuid]
        );

        $this->assertNotNull($createdAudit);
        $this->assertNotNull($approvedAudit);
    }

    public function test_approve_requires_secret_owner(): void
    {
        ['admin' => $admin, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);

        $this->expectException(AuthException::class);
        $this->svc->approve($req->uuid, $admin->id, $orgId);
    }

    public function test_approve_fails_if_requester_became_owner(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $this->secretSvc->transferOwnership($secUuid, $orgId, $requester->id, $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->approve($req->uuid, $requester->id, $orgId);
    }

    public function test_approve_fails_if_not_pending(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $this->svc->approve($req->uuid, $owner->id, $orgId);

        // Пытаемся одобрить повторно
        $this->expectException(\RuntimeException::class);
        $this->svc->approve($req->uuid, $owner->id, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  reject()                                                           //
    // ------------------------------------------------------------------ //

    public function test_reject_sets_status(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req      = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $rejected = $this->svc->reject($req->uuid, 'Not authorized', $owner->id, $orgId);

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('Not authorized', $rejected->rejectionReason);
        $this->assertSame($owner->id, $rejected->approvedBy);
        $this->assertNotNull($rejected->resolvedAt);
    }

    public function test_reject_requires_secret_owner(): void
    {
        ['admin' => $admin, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);

        $this->expectException(AuthException::class);
        $this->svc->reject($req->uuid, null, $admin->id, $orgId);
    }

    public function test_reject_fails_if_not_pending(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $this->svc->reject($req->uuid, null, $owner->id, $orgId);

        $this->expectException(\RuntimeException::class);
        $this->svc->reject($req->uuid, null, $owner->id, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  revoke()                                                           //
    // ------------------------------------------------------------------ //

    public function test_requester_can_revoke_own_pending(): void
    {
        ['requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $this->svc->revoke($req->uuid, $requester->id, $orgId);

        $updated = ApprovalRequest::findByUuid($req->uuid);
        $this->assertSame('revoked', $updated?->status);
    }

    public function test_admin_can_revoke_any_pending(): void
    {
        ['admin' => $admin, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $this->svc->revoke($req->uuid, $admin->id, $orgId);

        $updated = ApprovalRequest::findByUuid($req->uuid);
        $this->assertSame('revoked', $updated?->status);
    }

    public function test_admin_can_revoke_approved(): void
    {
        ['owner' => $owner, 'admin' => $admin, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $this->svc->approve($req->uuid, $owner->id, $orgId);
        $this->svc->revoke($req->uuid, $admin->id, $orgId);

        $updated = ApprovalRequest::findByUuid($req->uuid);
        $this->assertSame('revoked', $updated?->status);
    }

    public function test_requester_cannot_revoke_approved(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $this->svc->approve($req->uuid, $owner->id, $orgId);

        $this->expectException(\RuntimeException::class);
        $this->svc->revoke($req->uuid, $requester->id, $orgId);
    }

    public function test_other_user_cannot_revoke(): void
    {
        ['requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $other = $this->createTestUser('other2@test.com');
        $this->orgSvc->addMember($orgId, $other->id, 'reader', $requester->id);

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);

        $this->expectException(AuthException::class);
        $this->svc->revoke($req->uuid, $other->id, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  useToken()                                                         //
    // ------------------------------------------------------------------ //

    public function test_use_token_returns_decrypted_value(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        ['token' => $token] = $this->svc->approve($req->uuid, $owner->id, $orgId);

        ['secret' => $secret, 'value' => $value] =
            $this->svc->useToken($req->uuid, $token, $requester->id, $orgId);

        $this->assertSame('supersecret', $value);
        $this->assertSame($secUuid, $secret->uuid);
    }

    public function test_use_token_consumes_token(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        ['token' => $token] = $this->svc->approve($req->uuid, $owner->id, $orgId);

        $this->svc->useToken($req->uuid, $token, $requester->id, $orgId);

        // Повторная попытка должна провалиться
        $this->expectException(AuthException::class);
        $this->svc->useToken($req->uuid, $token, $requester->id, $orgId);
    }

    public function test_use_token_fails_with_wrong_token(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        $this->svc->approve($req->uuid, $owner->id, $orgId);

        $this->expectException(AuthException::class);
        $this->svc->useToken($req->uuid, 'wrong_token', $requester->id, $orgId);
    }

    public function test_use_token_fails_for_non_requester(): void
    {
        ['owner' => $owner, 'requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $other = $this->createTestUser('other3@test.com');
        $this->orgSvc->addMember($orgId, $other->id, 'reader', $requester->id);

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);
        ['token' => $token] = $this->svc->approve($req->uuid, $owner->id, $orgId);

        $this->expectException(AuthException::class);
        $this->svc->useToken($req->uuid, $token, $other->id, $orgId);
    }

    public function test_use_token_fails_for_pending_request(): void
    {
        ['requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $req = $this->svc->request($secUuid, 'read', null, $requester->id, $orgId);

        $this->expectException(AuthException::class);
        $this->svc->useToken($req->uuid, 'some-token', $requester->id, $orgId);
    }

    // ------------------------------------------------------------------ //
    //  SecretService.get() integration                                    //
    // ------------------------------------------------------------------ //

    public function test_secret_get_blocked_for_user_with_requires_approval(): void
    {
        ['requester' => $requester, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        $this->expectException(AuthException::class);
        $this->secretSvc->get($secUuid, $orgId, $requester->id);
    }

    public function test_secret_get_allowed_for_owner_with_requires_approval(): void
    {
        ['owner' => $owner, 'orgId' => $orgId, 'secretUuid' => $secUuid] =
            $this->setupApprovalScenario();

        // owner секрета имеет прямой доступ даже при requires_approval=true
        ['value' => $value] = $this->secretSvc->get($secUuid, $orgId, $owner->id);
        $this->assertSame('supersecret', $value);
    }
}
