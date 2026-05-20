<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Services\TokenService;
use PHPUnit\Framework\TestCase;

final class TokenServiceTest extends TestCase
{
    private TokenService $svc;

    protected function setUp(): void
    {
        $this->svc = new TokenService();
    }

    public function test_generate_session_token_is_64_hex(): void
    {
        $token = $this->svc->generateSessionToken();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_session_tokens_are_unique(): void
    {
        $tokens = array_map(fn() => $this->svc->generateSessionToken(), range(1, 10));
        $this->assertSame(10, count(array_unique($tokens)));
    }

    public function test_generate_api_key_format(): void
    {
        $data = $this->svc->generateApiKey();

        $this->assertMatchesRegularExpression('/^sv_[0-9a-f]{64}$/', $data->fullKey);
        $this->assertSame(substr($data->fullKey, 0, 12), $data->keyPrefix);
        $this->assertSame(67, strlen($data->fullKey));
    }

    public function test_api_keys_are_unique(): void
    {
        $keys = array_map(
            fn() => $this->svc->generateApiKey()->fullKey,
            range(1, 10)
        );
        $this->assertSame(10, count(array_unique($keys)));
    }

    public function test_looks_like_api_key_valid(): void
    {
        $key = 'sv_' . str_repeat('ab', 32);
        $this->assertTrue($this->svc->looksLikeApiKey($key));
    }

    public function test_looks_like_api_key_invalid(): void
    {
        $this->assertFalse($this->svc->looksLikeApiKey('not-an-api-key'));
        $this->assertFalse($this->svc->looksLikeApiKey('sv_tooshort'));
        $this->assertFalse($this->svc->looksLikeApiKey('Bearer sv_' . str_repeat('ab', 32)));
    }

    public function test_generate_invite_token_is_64_hex(): void
    {
        $token = $this->svc->generateInviteToken();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_generate_setup_token_is_64_hex(): void
    {
        $token = $this->svc->generateSetupToken();

        $this->assertSame(64, strlen($token));
    }
}
