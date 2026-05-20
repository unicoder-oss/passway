<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Services\AuthService;
use Passway\Services\HashingService;
use Passway\Services\SessionService;
use Passway\Services\TokenService;
use Passway\Tests\DatabaseTestCase;

/**
 * AuthService tests: loginWithPassword, rate limiting, audit log.
 *
 * @requires extension pdo_sqlite
 */
final class AuthServiceTest extends DatabaseTestCase
{
    private AuthService    $svc;
    private HashingService $hashing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hashing = new HashingService();
        $session = new SessionService(new TokenService(), $this->hashing);
        $this->svc = new AuthService($this->hashing, $session);

        // Ensure setup_complete = '1'
        Database::getInstance()->query(
            "UPDATE system_config SET value = '1' WHERE key = 'setup_complete'"
        );
    }

    // ------------------------------------------------------------------ //
    //  assertSetupComplete()                                              //
    // ------------------------------------------------------------------ //

    public function test_login_throws_when_setup_not_complete(): void
    {
        Database::getInstance()->query(
            "UPDATE system_config SET value = '0' WHERE key = 'setup_complete'"
        );

        $this->expectException(AuthException::class);
        $this->svc->loginWithPassword('a@b.com', 'pass', null, null);
    }

    // ------------------------------------------------------------------ //
    //  loginWithPassword()                                                //
    // ------------------------------------------------------------------ //

    public function test_successful_login_returns_token(): void
    {
        $this->createTestUser('admin@example.com');
        $result = $this->svc->loginWithPassword('admin@example.com', 'Test1234!', '1.2.3.4', 'TestAgent');

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('raw_token', $result);
        $this->assertSame(64, \strlen($result['raw_token']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['raw_token']);
    }

    public function test_login_is_case_insensitive_for_email(): void
    {
        $this->createTestUser('USER@EXAMPLE.COM');
        $result = $this->svc->loginWithPassword('user@example.com', 'Test1234!', null, null);

        $this->assertSame('success', $result['status']);
    }

    public function test_login_throws_for_nonexistent_user(): void
    {
        $this->expectException(AuthException::class);
        $this->svc->loginWithPassword('nobody@example.com', 'pass', null, null);
    }

    public function test_login_throws_for_wrong_password(): void
    {
        $this->createTestUser('test@example.com');

        $this->expectException(AuthException::class);
        $this->svc->loginWithPassword('test@example.com', 'WrongPass!', null, null);
    }

    public function test_login_throws_for_inactive_user(): void
    {
        $this->createTestUser('inactive@example.com', '', false);

        $this->expectException(AuthException::class);
        $this->svc->loginWithPassword('inactive@example.com', 'Test1234!', null, null);
    }

    public function test_login_updates_last_login_fields(): void
    {
        $user = $this->createTestUser('logged@example.com');
        $this->svc->loginWithPassword('logged@example.com', 'Test1234!', '10.0.0.1', null);

        $row = Database::getInstance()->fetchOne(
            'SELECT last_login_at, last_login_ip FROM users WHERE id = ?',
            [$user->id]
        );

        $this->assertNotNull($row['last_login_at']);
        $this->assertSame('10.0.0.1', $row['last_login_ip']);
    }

    public function test_login_writes_success_audit_log(): void
    {
        $this->createTestUser('audit@example.com');
        $this->svc->loginWithPassword('audit@example.com', 'Test1234!', '1.2.3.4', null);

        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'auth.login_success' ORDER BY id DESC LIMIT 1"
        );

        $this->assertNotNull($row);
        $this->assertSame('1', (string) $row['success']);
    }

    public function test_failed_login_writes_fail_audit_log(): void
    {
        $this->createTestUser('fail@example.com');

        try {
            $this->svc->loginWithPassword('fail@example.com', 'WrongPass!', '1.2.3.4', null);
        } catch (AuthException) {}

        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'auth.login_fail' ORDER BY id DESC LIMIT 1"
        );

        $this->assertNotNull($row);
        $this->assertSame('0', (string) $row['success']);
    }

    // ------------------------------------------------------------------ //
    //  Rate limiting                                                      //
    // ------------------------------------------------------------------ //

    public function test_rate_limit_blocks_after_5_failed_attempts(): void
    {
        $this->createTestUser('ratelimit@example.com');
        $ip = '99.88.77.66';

        // 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->svc->loginWithPassword('ratelimit@example.com', 'WrongPass!', $ip, null);
            } catch (AuthException) {}
        }

        // 6th attempt is rate limited (even with the correct password)
        $this->expectException(AuthException::class);
        $this->svc->loginWithPassword('ratelimit@example.com', 'Test1234!', $ip, null);
    }

    public function test_rate_limit_different_ips_are_independent(): void
    {
        $this->createTestUser('ratelimit2@example.com');
        $ip1 = '11.22.33.44';
        $ip2 = '55.66.77.88';

        // Block ip1
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->svc->loginWithPassword('ratelimit2@example.com', 'WrongPass!', $ip1, null);
            } catch (AuthException) {}
        }

        // ip2 should work
        $result = $this->svc->loginWithPassword('ratelimit2@example.com', 'Test1234!', $ip2, null);
        $this->assertSame('success', $result['status']);
    }

    // ------------------------------------------------------------------ //
    //  TOTP pending                                                       //
    // ------------------------------------------------------------------ //

    public function test_login_returns_totp_required_when_totp_enabled(): void
    {
        $user = $this->createTestUser('totp@example.com');

        // Enable TOTP (dummy values; only the flag matters)
        Database::getInstance()->update('users', [
            'totp_enabled' => 1,
            'totp_secret'  => 'fake_secret',
            'totp_nonce'   => \str_repeat('ab', 24),
        ], ['id' => $user->id]);

        // Start session to store pending state
        if (\session_status() === PHP_SESSION_NONE) {
            \session_start();
        }

        $result = $this->svc->loginWithPassword('totp@example.com', 'Test1234!', null, null);

        $this->assertSame('totp_required', $result['status']);
        $this->assertArrayNotHasKey('raw_token', $result);
    }

    // ------------------------------------------------------------------ //
    //  writeAuditLog()                                                    //
    // ------------------------------------------------------------------ //

    public function test_write_audit_log_stores_entry(): void
    {
        // user_id=null to avoid violating the FK constraint (there is no user with id=42)
        $this->svc->writeAuditLog(null, 'system.test', '127.0.0.1', 'Agent', true, ['key' => 'value']);

        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM audit_log WHERE action = 'system.test' LIMIT 1"
        );

        $this->assertNotNull($row);
        $this->assertNull($row['user_id']);
        $this->assertSame('127.0.0.1', $row['ip_address']);
        $details = \json_decode($row['details_json'] ?? '{}', true);
        $this->assertSame('value', $details['key']);
    }
}
