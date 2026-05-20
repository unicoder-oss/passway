<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Services\PasskeyService;
use Passway\Tests\DatabaseTestCase;
use ReflectionClass;

/**
 * @requires extension pdo_sqlite
 */
final class PasskeyServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (\session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            \session_write_close();
        }
    }

    public function test_start_registration_returns_pub_key_credential_params_with_alg(): void
    {
        $_ENV['WEBAUTHN_RP_ID'] = 'localhost';
        $_ENV['APP_NAME'] = 'Passway';

        $user = $this->createTestUser('passkey@example.com');
        $service = new PasskeyService();

        $options = $service->startRegistration($user);

        $this->assertArrayHasKey('pubKeyCredParams', $options);
        $this->assertSame([
            ['type' => 'public-key', 'alg' => -7],
            ['type' => 'public-key', 'alg' => -257],
        ], $options['pubKeyCredParams']);
        $this->assertSame([
            'userVerification' => 'preferred',
            'residentKey' => 'preferred',
        ], $options['authenticatorSelection']);
        $this->assertArrayNotHasKey('extensions', $options);
        $this->assertArrayNotHasKey('hints', $options);
    }

    public function test_localhost_is_allowed_for_non_https_local_development(): void
    {
        $_ENV['WEBAUTHN_RP_ID'] = 'localhost';

        $service = new PasskeyService();
        $reflection = new ReflectionClass($service);
        $property = $reflection->getProperty('securedRelyingPartyIds');
        $property->setAccessible(true);

        $this->assertSame(['localhost'], $property->getValue($service));
    }
}
