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

    public function test_generate_api_key_production_format(): void
    {
        $data = $this->svc->generateApiKey('production');

        $this->assertStringStartsWith('sv_prod_', $data->fullKey);
        $this->assertSame('sv_prod_', $data->keyPrefix);
        $this->assertSame('production', $data->environment);
        $this->assertSame(72, strlen($data->fullKey)); // sv_prod_ (8) + 64 hex
    }

    public function test_generate_api_key_staging_format(): void
    {
        $data = $this->svc->generateApiKey('staging');

        $this->assertStringStartsWith('sv_stg_', $data->fullKey);
        $this->assertSame('sv_stg_', $data->keyPrefix);
    }

    public function test_generate_api_key_development_format(): void
    {
        $data = $this->svc->generateApiKey('development');

        $this->assertStringStartsWith('sv_dev_', $data->fullKey);
    }

    public function test_generate_api_key_unknown_env_defaults_to_prod(): void
    {
        $data = $this->svc->generateApiKey('unknown');

        $this->assertStringStartsWith('sv_prod_', $data->fullKey);
    }

    public function test_api_keys_are_unique(): void
    {
        $keys = array_map(
            fn() => $this->svc->generateApiKey('production')->fullKey,
            range(1, 10)
        );
        $this->assertSame(10, count(array_unique($keys)));
    }

    public function test_looks_like_api_key_valid(): void
    {
        $key = 'sv_prod_' . str_repeat('ab', 32);
        $this->assertTrue($this->svc->looksLikeApiKey($key));
    }

    public function test_looks_like_api_key_invalid(): void
    {
        $this->assertFalse($this->svc->looksLikeApiKey('not-an-api-key'));
        $this->assertFalse($this->svc->looksLikeApiKey('sv_prod_tooshort'));
        $this->assertFalse($this->svc->looksLikeApiKey('Bearer sv_prod_' . str_repeat('ab', 32)));
    }

    public function test_extract_environment_from_api_key(): void
    {
        $this->assertSame('production',  $this->svc->extractEnvironmentFromApiKey('sv_prod_' . str_repeat('a', 64)));
        $this->assertSame('staging',     $this->svc->extractEnvironmentFromApiKey('sv_stg_' . str_repeat('a', 64)));
        $this->assertSame('development', $this->svc->extractEnvironmentFromApiKey('sv_dev_' . str_repeat('a', 64)));
        $this->assertNull($this->svc->extractEnvironmentFromApiKey('sv_unknown_xxx'));
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
