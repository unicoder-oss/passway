<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Services\HashingService;
use Passway\Services\SessionService;
use Passway\Services\TokenService;
use Passway\Tests\DatabaseTestCase;

/**
 * Тесты SessionService.
 * Использует in-memory SQLite (DB_SQLITE_PATH=:memory:).
 *
 * @requires extension pdo_sqlite
 */
final class SessionServiceTest extends DatabaseTestCase
{
    private SessionService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['SESSION_TTL'] = '86400';

        $this->svc = new SessionService(
            new TokenService(),
            new HashingService(),
        );
    }

    // ------------------------------------------------------------------ //
    //  create()                                                           //
    // ------------------------------------------------------------------ //

    public function test_create_returns_64_hex_token(): void
    {
        $user  = $this->createTestUser();
        $token = $this->svc->create($user->id, '127.0.0.1', 'PHPUnit/TestAgent');

        $this->assertSame(64, \strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_create_stores_token_hash_not_plaintext(): void
    {
        $user  = $this->createTestUser();
        $token = $this->svc->create($user->id, '127.0.0.1', null);

        $db  = Database::getInstance();
        $row = $db->fetchOne('SELECT token_hash FROM sessions WHERE user_id = ?', [$user->id]);

        $this->assertNotNull($row);
        // Хэш должен быть 64 hex символа (SHA-256)
        $this->assertSame(64, \strlen($row['token_hash']));
        // Токен ≠ хэш — plaintext не хранится
        $this->assertNotSame($token, $row['token_hash']);
    }

    public function test_create_sets_correct_expiry(): void
    {
        $_ENV['SESSION_TTL'] = '3600';
        $svc  = new SessionService(new TokenService(), new HashingService());
        $user = $this->createTestUser();
        $svc->create($user->id, '127.0.0.1', null);

        $row     = Database::getInstance()->fetchOne('SELECT expires_at FROM sessions WHERE user_id = ?', [$user->id]);
        $expiry  = \strtotime($row['expires_at']);
        $expected = \time() + 3600;

        // Допуск ±5 секунд
        $this->assertEqualsWithDelta($expected, $expiry, 5.0);
    }

    // ------------------------------------------------------------------ //
    //  validate()                                                         //
    // ------------------------------------------------------------------ //

    public function test_validate_returns_user_for_valid_token(): void
    {
        $user  = $this->createTestUser();
        $token = $this->svc->create($user->id, '127.0.0.1', 'Agent');

        $found = $this->svc->validate($token);

        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
        $this->assertSame($user->email, $found->email);
    }

    public function test_validate_returns_null_for_wrong_token(): void
    {
        $user = $this->createTestUser();
        $this->svc->create($user->id, '127.0.0.1', null);

        $result = $this->svc->validate(\str_repeat('0', 64));

        $this->assertNull($result);
    }

    public function test_validate_returns_null_for_expired_session(): void
    {
        $user = $this->createTestUser();
        $this->svc->create($user->id, '127.0.0.1', null);

        // Принудительно устареть сессию
        Database::getInstance()->query(
            "UPDATE sessions SET expires_at = datetime('now', '-1 hour') WHERE user_id = ?",
            [$user->id]
        );

        // validate() ищет по raw-токену, а у нас только хэш — создаём ещё один и истекаем его.
        $token2 = $this->svc->create($user->id, '127.0.0.1', null);
        Database::getInstance()->query(
            "UPDATE sessions SET expires_at = datetime('now', '-1 hour') WHERE user_id = ?",
            [$user->id]
        );

        $result = $this->svc->validate($token2);

        $this->assertNull($result);
    }

    public function test_validate_updates_last_activity(): void
    {
        $user  = $this->createTestUser();
        $token = $this->svc->create($user->id, '127.0.0.1', null);

        // Искусственно откатить last_activity_at
        Database::getInstance()->query(
            "UPDATE sessions SET last_activity_at = datetime('now', '-10 minutes') WHERE user_id = ?",
            [$user->id]
        );

        $this->svc->validate($token);

        $row = Database::getInstance()->fetchOne(
            'SELECT last_activity_at FROM sessions WHERE user_id = ?',
            [$user->id]
        );

        $activity = \strtotime($row['last_activity_at']);
        $this->assertEqualsWithDelta(\time(), $activity, 5.0);
    }

    // ------------------------------------------------------------------ //
    //  invalidate()                                                       //
    // ------------------------------------------------------------------ //

    public function test_invalidate_removes_session(): void
    {
        $user  = $this->createTestUser();
        $token = $this->svc->create($user->id, '127.0.0.1', null);

        $this->svc->invalidate($token);

        $result = $this->svc->validate($token);
        $this->assertNull($result);
    }

    public function test_invalidate_does_not_affect_other_sessions(): void
    {
        $user   = $this->createTestUser();
        $token1 = $this->svc->create($user->id, '127.0.0.1', null);
        $token2 = $this->svc->create($user->id, '127.0.0.1', null);

        $this->svc->invalidate($token1);

        $this->assertNull($this->svc->validate($token1));
        $this->assertNotNull($this->svc->validate($token2));
    }

    // ------------------------------------------------------------------ //
    //  invalidateAll()                                                    //
    // ------------------------------------------------------------------ //

    public function test_invalidate_all_removes_all_user_sessions(): void
    {
        $user   = $this->createTestUser();
        $token1 = $this->svc->create($user->id, '127.0.0.1', null);
        $token2 = $this->svc->create($user->id, '192.168.1.1', null);

        $this->svc->invalidateAll($user->id);

        $this->assertNull($this->svc->validate($token1));
        $this->assertNull($this->svc->validate($token2));
    }

    // ------------------------------------------------------------------ //
    //  cleanup()                                                          //
    // ------------------------------------------------------------------ //

    public function test_cleanup_removes_only_expired_sessions(): void
    {
        $user     = $this->createTestUser();
        $active  = $this->svc->create($user->id, '127.0.0.1', null);
        $this->svc->create($user->id, '127.0.0.1', null); // будет истечена

        // Истечь вторую сессию
        Database::getInstance()->query(
            "UPDATE sessions SET expires_at = datetime('now', '-2 hours') WHERE user_id = ? AND rowid = (SELECT MAX(rowid) FROM sessions WHERE user_id = ?)",
            [$user->id, $user->id]
        );

        $deleted = $this->svc->cleanup();

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertNotNull($this->svc->validate($active));
    }
}
