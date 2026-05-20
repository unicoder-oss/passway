<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Exceptions\AuthException;
use Passway\Models\User;
use Passway\Services\HashingService;
use Passway\Services\SetupService;
use Passway\Services\TokenService;
use Passway\Tests\DatabaseTestCase;

/**
 * Тесты SetupService: генерация токена, верификация, completeSetup.
 *
 * @requires extension pdo_sqlite
 */
final class SetupServiceTest extends DatabaseTestCase
{
    private SetupService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = new SetupService(
            new HashingService(),
            new TokenService(),
        );

        // Установить начальное состояние: setup не завершён, токена нет
        Database::getInstance()->query(
            "UPDATE system_config SET value = '0' WHERE key = 'setup_complete'"
        );
        Database::getInstance()->query(
            "UPDATE system_config SET value = '' WHERE key = 'setup_token_hash'"
        );
        Database::getInstance()->query(
            "UPDATE system_config SET value = '' WHERE key = 'deploy_mode'"
        );
    }

    // ------------------------------------------------------------------ //
    //  isSetupComplete()                                                  //
    // ------------------------------------------------------------------ //

    public function test_is_setup_complete_false_initially(): void
    {
        $this->assertFalse($this->svc->isSetupComplete());
    }

    public function test_is_setup_complete_true_when_flag_set(): void
    {
        Database::getInstance()->query(
            "UPDATE system_config SET value = '1' WHERE key = 'setup_complete'"
        );
        $this->assertTrue($this->svc->isSetupComplete());
    }

    // ------------------------------------------------------------------ //
    //  hasSetupToken()                                                    //
    // ------------------------------------------------------------------ //

    public function test_has_setup_token_false_when_empty(): void
    {
        $this->assertFalse($this->svc->hasSetupToken());
    }

    public function test_has_setup_token_true_after_generate(): void
    {
        $this->svc->generateAndStoreSetupToken();
        $this->assertTrue($this->svc->hasSetupToken());
    }

    // ------------------------------------------------------------------ //
    //  generateAndStoreSetupToken()                                       //
    // ------------------------------------------------------------------ //

    public function test_generate_returns_64_hex_token(): void
    {
        $token = $this->svc->generateAndStoreSetupToken();

        $this->assertNotNull($token);
        $this->assertSame(64, \strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_generate_stores_sha256_hash_not_plaintext(): void
    {
        $token = $this->svc->generateAndStoreSetupToken();

        $storedHash = Database::getInstance()->fetchColumn(
            "SELECT value FROM system_config WHERE key = 'setup_token_hash'"
        );

        $this->assertIsString($storedHash);
        $this->assertSame(64, \strlen($storedHash));   // SHA-256 hex = 64
        $this->assertNotSame($token, $storedHash);     // plaintext ≠ hash
    }

    public function test_generate_returns_null_when_token_already_exists(): void
    {
        $this->svc->generateAndStoreSetupToken();
        $second = $this->svc->generateAndStoreSetupToken();

        $this->assertNull($second);
    }

    // ------------------------------------------------------------------ //
    //  verifySetupToken()                                                 //
    // ------------------------------------------------------------------ //

    public function test_verify_correct_token_returns_true(): void
    {
        $token = $this->svc->generateAndStoreSetupToken();
        $this->assertNotNull($token);

        $this->assertTrue($this->svc->verifySetupToken($token));
    }

    public function test_verify_wrong_token_returns_false(): void
    {
        $this->svc->generateAndStoreSetupToken();

        $this->assertFalse($this->svc->verifySetupToken(\str_repeat('0', 64)));
    }

    public function test_verify_empty_token_returns_false(): void
    {
        $this->svc->generateAndStoreSetupToken();

        $this->assertFalse($this->svc->verifySetupToken(''));
    }

    public function test_verify_returns_false_when_no_stored_hash(): void
    {
        // Нет токена в БД
        $this->assertFalse($this->svc->verifySetupToken(\str_repeat('a', 64)));
    }

    // ------------------------------------------------------------------ //
    //  completeSetup()                                                    //
    // ------------------------------------------------------------------ //

    public function test_complete_setup_creates_admin_user(): void
    {
        $token = $this->svc->generateAndStoreSetupToken();
        $this->assertNotNull($token);

        $user = $this->svc->completeSetup($token, 'admin@example.com', 'Secure12', 'solo');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('admin@example.com', $user->email);
        $this->assertTrue($user->isActive);
        $this->assertTrue($user->emailVerified);
    }

    public function test_complete_setup_returns_user_object(): void
    {
        $token = $this->svc->generateAndStoreSetupToken();
        $this->assertNotNull($token);

        $user = $this->svc->completeSetup($token, 'admin@example.com', 'Secure12', 'solo');

        $found = User::findByEmail('admin@example.com');
        $this->assertNotNull($found);
        $this->assertSame($user->id, $found->id);
    }

    public function test_complete_setup_sets_setup_complete_flag(): void
    {
        $token = $this->svc->generateAndStoreSetupToken();
        $this->assertNotNull($token);

        $this->svc->completeSetup($token, 'admin@example.com', 'Secure12', 'solo');

        $this->assertTrue($this->svc->isSetupComplete());
    }

    public function test_complete_setup_stores_deploy_mode(): void
    {
        $token = $this->svc->generateAndStoreSetupToken();
        $this->assertNotNull($token);

        $this->svc->completeSetup($token, 'admin@example.com', 'Secure12', 'team');

        $mode = Database::getInstance()->fetchColumn(
            "SELECT value FROM system_config WHERE key = 'deploy_mode'"
        );
        $this->assertSame('team', $mode);
    }

    public function test_complete_setup_clears_token_hash(): void
    {
        $token = $this->svc->generateAndStoreSetupToken();
        $this->assertNotNull($token);

        $this->svc->completeSetup($token, 'admin@example.com', 'Secure12', 'solo');

        // Токен аннулирован — повторная верификация провалится
        $this->assertFalse($this->svc->verifySetupToken($token));
        $this->assertFalse($this->svc->hasSetupToken());
    }

    public function test_complete_setup_throws_on_wrong_token(): void
    {
        $this->svc->generateAndStoreSetupToken();

        $this->expectException(AuthException::class);
        $this->svc->completeSetup(\str_repeat('0', 64), 'admin@example.com', 'Secure12', 'solo');
    }

    public function test_complete_setup_throws_when_already_complete(): void
    {
        Database::getInstance()->query(
            "UPDATE system_config SET value = '1' WHERE key = 'setup_complete'"
        );

        $this->expectException(AuthException::class);
        $this->svc->completeSetup(\str_repeat('a', 64), 'admin@example.com', 'Secure12', 'solo');
    }

    // ------------------------------------------------------------------ //
    //  Валидация                                                          //
    // ------------------------------------------------------------------ //

    public function test_validate_email_throws_for_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->validateEmail('not-an-email');
    }

    public function test_validate_password_throws_when_too_short(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->validatePassword('abc123');
    }

    public function test_validate_password_throws_when_no_digit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->validatePassword('abcdefgh');
    }

    public function test_validate_password_throws_when_no_letter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->validatePassword('12345678');
    }

    public function test_validate_deploy_mode_throws_for_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->validateDeployMode('enterprise');
    }

    public function test_complete_setup_with_team_mode(): void
    {
        $token = $this->svc->generateAndStoreSetupToken();
        $this->assertNotNull($token);

        $user = $this->svc->completeSetup($token, 'admin@example.com', 'Secure12', 'team');
        $this->assertSame('admin@example.com', $user->email);
    }
}
