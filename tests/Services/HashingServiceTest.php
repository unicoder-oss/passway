<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Services\HashingService;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension sodium
 */
final class HashingServiceTest extends TestCase
{
    private HashingService $svc;

    protected function setUp(): void
    {
        $this->svc = new HashingService();
    }

    // ------------------------------------------------------------------ //
    //  Passwords                                                           //
    // ------------------------------------------------------------------ //

    public function test_hash_password_returns_argon2id_hash(): void
    {
        $hash = $this->svc->hashPassword('my-password');

        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function test_verify_password_correct(): void
    {
        $hash = $this->svc->hashPassword('correct-horse-battery-staple');

        $this->assertTrue($this->svc->verifyPassword('correct-horse-battery-staple', $hash));
    }

    public function test_verify_password_wrong(): void
    {
        $hash = $this->svc->hashPassword('real-password');

        $this->assertFalse($this->svc->verifyPassword('wrong-password', $hash));
    }

    public function test_same_password_produces_different_hashes(): void
    {
        // Argon2id uses a random salt; each hash is unique
        $hash1 = $this->svc->hashPassword('password');
        $hash2 = $this->svc->hashPassword('password');

        $this->assertNotSame($hash1, $hash2);
        // But both verify successfully
        $this->assertTrue($this->svc->verifyPassword('password', $hash1));
        $this->assertTrue($this->svc->verifyPassword('password', $hash2));
    }

    public function test_needs_rehash_returns_false_for_current_params(): void
    {
        $hash = $this->svc->hashPassword('test-password');

        $this->assertFalse($this->svc->needsRehash($hash));
    }

    // ------------------------------------------------------------------ //
    //  Tokens (SHA-256)                                                     //
    // ------------------------------------------------------------------ //

    public function test_hash_token_returns_64_hex_chars(): void
    {
        $token = bin2hex(random_bytes(32));
        $hash  = $this->svc->hashToken($token);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_hash_token_is_deterministic(): void
    {
        $token = 'same-token-value';

        $this->assertSame(
            $this->svc->hashToken($token),
            $this->svc->hashToken($token)
        );
    }

    public function test_different_tokens_produce_different_hashes(): void
    {
        $this->assertNotSame(
            $this->svc->hashToken('token-a'),
            $this->svc->hashToken('token-b')
        );
    }

    public function test_hash_api_key_returns_64_hex_chars(): void
    {
        $apiKey = 'sv_' . bin2hex(random_bytes(32));
        $hash   = $this->svc->hashApiKey($apiKey);

        $this->assertSame(64, strlen($hash));
    }

    // ------------------------------------------------------------------ //
    //  Timing-safe comparison                                               //
    // ------------------------------------------------------------------ //

    public function test_timing_safe_equals_same_strings(): void
    {
        $this->assertTrue($this->svc->timingSafeEquals('abc123', 'abc123'));
    }

    public function test_timing_safe_equals_different_strings(): void
    {
        $this->assertFalse($this->svc->timingSafeEquals('abc123', 'xyz789'));
    }

    public function test_timing_safe_equals_different_lengths(): void
    {
        $this->assertFalse($this->svc->timingSafeEquals('short', 'much-longer-string'));
    }
}
