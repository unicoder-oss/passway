<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\ApiKey;
use Passway\Services\ApiKeyService;
use Passway\Services\OrganizationService;
use Passway\Tests\DatabaseTestCase;

/**
 * ApiKeyService tests: creation, listing, API key revocation,
 * permission management, validation, rate limiting.
 *
 * @requires extension pdo_sqlite
 */
final class ApiKeyServiceTest extends DatabaseTestCase
{
    private ApiKeyService       $svc;
    private OrganizationService $orgSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgSvc = new OrganizationService();
        $this->svc    = new ApiKeyService($this->orgSvc);

        Database::getInstance()->query(
            "UPDATE system_config SET value = 'team' WHERE key = 'deploy_mode'"
        );
    }

    // ------------------------------------------------------------------ //
    //  create()                                                           //
    // ------------------------------------------------------------------ //

    public function test_create_returns_key_and_raw_string(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['key' => $apiKey, 'raw' => $raw] = $this->svc->create('CI key', $org->id, $owner->id);

        $this->assertInstanceOf(ApiKey::class, $apiKey);
        $this->assertStringStartsWith('sv_', $raw);
        $this->assertSame(67, strlen($raw)); // sv_ (3) + 64 hex = 67
        $this->assertSame('CI key', $apiKey->name);
        $this->assertSame('reader', $apiKey->role);
        $this->assertTrue($apiKey->isActive);
        $this->assertSame(hash('sha256', $raw), $apiKey->keyHash);
    }

    public function test_create_accepts_editor_role(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);

        ['key' => $apiKey] = $this->svc->create('Deploy', $org->id, $owner->id, 'editor');

        $this->assertSame('editor', $apiKey->role);
    }

    public function test_create_returns_plain_format_without_environment(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['raw' => $raw] = $this->svc->create('Key', $org->id, $owner->id);

        $this->assertMatchesRegularExpression('/^sv_[0-9a-f]{64}$/', $raw);
    }

    public function test_create_fails_for_non_admin(): void
    {
        $owner = $this->createTestUser();
        $user  = $this->createTestUser('user@example.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $user->id, 'reader', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->create('Key', $org->id, $user->id);
    }

    public function test_create_fails_with_empty_name(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create('', $org->id, $owner->id);
    }

    public function test_create_fails_with_invalid_role(): void
    {
        $owner = $this->createTestUser();
        $org = $this->orgSvc->create('Org', $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create('Key', $org->id, $owner->id, 'owner');
    }

    // ------------------------------------------------------------------ //
    //  listForOrg()                                                       //
    // ------------------------------------------------------------------ //

    public function test_list_for_org_returns_created_keys(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        $this->svc->create('Key A', $org->id, $owner->id);
        $this->svc->create('Key B', $org->id, $owner->id);

        $keys = $this->svc->listForOrg($org->id, $owner->id);

        $this->assertCount(2, $keys);
    }

    public function test_list_for_org_fails_for_non_admin(): void
    {
        $owner = $this->createTestUser();
        $user  = $this->createTestUser('user@example.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $user->id, 'reader', $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->listForOrg($org->id, $user->id);
    }

    // ------------------------------------------------------------------ //
    //  get()                                                              //
    // ------------------------------------------------------------------ //

    public function test_get_by_uuid(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['key' => $created] = $this->svc->create('Key', $org->id, $owner->id);

        $found = $this->svc->get($created->uuid, $org->id, $owner->id);

        $this->assertSame($created->uuid, $found->uuid);
    }

    public function test_get_fails_for_wrong_org(): void
    {
        $owner = $this->createTestUser();
        $org1  = $this->orgSvc->create('Org1', $owner->id);
        $org2  = $this->orgSvc->create('Org2', $owner->id);

        ['key' => $key] = $this->svc->create('Key', $org1->id, $owner->id);

        $this->expectException(\RuntimeException::class);
        $this->svc->get($key->uuid, $org2->id, $owner->id);
    }

    // ------------------------------------------------------------------ //
    //  revoke()                                                           //
    // ------------------------------------------------------------------ //

    public function test_revoke_deactivates_key(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['key' => $key] = $this->svc->create('Key', $org->id, $owner->id);
        $this->assertTrue($key->isActive);

        $this->svc->revoke($key->uuid, $org->id, $owner->id);

        $updated = ApiKey::findByUuid($key->uuid);
        $this->assertNotNull($updated);
        $this->assertFalse($updated->isActive);
    }

    public function test_revoke_fails_for_non_admin_non_owner(): void
    {
        $owner = $this->createTestUser();
        $user  = $this->createTestUser('user@example.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $user->id, 'reader', $owner->id);

        ['key' => $key] = $this->svc->create('Key', $org->id, $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->revoke($key->uuid, $org->id, $user->id);
    }

    // ------------------------------------------------------------------ //
    //  validate()                                                         //
    // ------------------------------------------------------------------ //

    public function test_validate_returns_user_for_valid_key(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['raw' => $raw] = $this->svc->create('Key', $org->id, $owner->id);

        $user = $this->svc->validate($raw);

        $this->assertNotNull($user);
        $this->assertSame($owner->id, $user->id);
    }

    public function test_validate_returns_null_for_wrong_key(): void
    {
        $user = $this->svc->validate('sv_' . str_repeat('0', 64));

        $this->assertNull($user);
    }

    public function test_validate_for_request_writes_success_audit_log(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['raw' => $raw, 'key' => $apiKey] = $this->svc->create('Key', $org->id, $owner->id);

        $user = $this->svc->validateForRequest($raw, '1.2.3.4', 'Agent', '/api/v1/secrets');

        $this->assertNotNull($user);

        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'auth.api_key_success' AND resource_uuid = ? ORDER BY id DESC LIMIT 1",
            [$apiKey->uuid]
        );

        $this->assertNotNull($row);
    }

    public function test_validate_for_request_writes_fail_audit_log(): void
    {
        $user = $this->svc->validateForRequest('sv_' . str_repeat('0', 64), '1.2.3.4', 'Agent', '/api/v1/secrets');

        $this->assertNull($user);

        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'auth.api_key_fail' ORDER BY id DESC LIMIT 1"
        );

        $this->assertNotNull($row);
        $this->assertSame('0', (string) $row['success']);
    }

    public function test_validate_returns_null_for_revoked_key(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['key' => $key, 'raw' => $raw] = $this->svc->create('Key', $org->id, $owner->id);
        $this->svc->revoke($key->uuid, $org->id, $owner->id);

        $user = $this->svc->validate($raw);

        $this->assertNull($user);
    }

    public function test_validate_updates_last_used_at(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['key' => $key, 'raw' => $raw] = $this->svc->create('Key', $org->id, $owner->id);
        $this->assertNull($key->lastUsedAt);

        $this->svc->validate($raw);

        $updated = ApiKey::findByUuid($key->uuid);
        $this->assertNotNull($updated?->lastUsedAt);
    }

    // ------------------------------------------------------------------ //
    //  updateRole()                                                       //
    // ------------------------------------------------------------------ //

    public function test_update_role_changes_existing_key_role(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['key' => $key] = $this->svc->create('Key', $org->id, $owner->id);

        $updated = $this->svc->updateRole($key->uuid, 'editor', $org->id, $owner->id);

        $this->assertSame('editor', $updated->role);
    }

    public function test_update_role_fails_for_non_admin(): void
    {
        $owner = $this->createTestUser();
        $user  = $this->createTestUser('user@example.com');
        $org   = $this->orgSvc->create('Org', $owner->id);
        $this->orgSvc->addMember($org->id, $user->id, 'reader', $owner->id);

        ['key' => $key] = $this->svc->create('Key', $org->id, $owner->id);

        $this->expectException(AuthException::class);
        $this->svc->updateRole($key->uuid, 'editor', $org->id, $user->id);
    }

    public function test_update_role_fails_with_invalid_role(): void
    {
        $owner = $this->createTestUser();
        $org   = $this->orgSvc->create('Org', $owner->id);

        ['key' => $key] = $this->svc->create('Key', $org->id, $owner->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->updateRole($key->uuid, 'owner', $org->id, $owner->id);
    }

    // ------------------------------------------------------------------ //
    //  checkRateLimit()                                                   //
    // ------------------------------------------------------------------ //

    public function test_rate_limit_allows_first_request(): void
    {
        $allowed = $this->svc->checkRateLimit('127.0.0.1', 'api');

        $this->assertTrue($allowed);
    }

    public function test_rate_limit_allows_up_to_max_requests(): void
    {
        $ip = '10.0.0.1';

        // Fill up to the auth limit (20)
        for ($i = 0; $i < ApiKeyService::RATE_LIMIT_AUTH_MAX; $i++) {
            $this->assertTrue(
                $this->svc->checkRateLimit($ip, 'auth'),
                "Request #$i should be allowed"
            );
        }
    }

    public function test_rate_limit_blocks_over_max(): void
    {
        $ip = '10.0.0.2';

        // Hit limit exactly
        for ($i = 0; $i < ApiKeyService::RATE_LIMIT_AUTH_MAX; $i++) {
            $this->svc->checkRateLimit($ip, 'auth');
        }

        // Next request should be blocked
        $allowed = $this->svc->checkRateLimit($ip, 'auth');
        $this->assertFalse($allowed);
    }

    public function test_rate_limit_different_buckets_are_independent(): void
    {
        $ip = '10.0.0.3';

        // Exhaust auth bucket
        for ($i = 0; $i < ApiKeyService::RATE_LIMIT_AUTH_MAX + 1; $i++) {
            $this->svc->checkRateLimit($ip, 'auth');
        }
        $this->assertFalse($this->svc->checkRateLimit($ip, 'auth'));

        // API bucket should still be fine
        $this->assertTrue($this->svc->checkRateLimit($ip, 'api'));
    }

    public function test_rate_limit_different_ips_are_independent(): void
    {
        // Exhaust auth bucket for ip1
        for ($i = 0; $i < ApiKeyService::RATE_LIMIT_AUTH_MAX + 1; $i++) {
            $this->svc->checkRateLimit('10.0.1.1', 'auth');
        }
        $this->assertFalse($this->svc->checkRateLimit('10.0.1.1', 'auth'));

        // ip2 should still be allowed
        $this->assertTrue($this->svc->checkRateLimit('10.0.1.2', 'auth'));
    }
}
