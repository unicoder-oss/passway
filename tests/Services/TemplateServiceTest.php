<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Core\Database;
use Passway\Services\TemplateService;
use Passway\Tests\DatabaseTestCase;

/**
 * @requires extension pdo_sqlite
 * @requires extension sodium
 */
final class TemplateServiceTest extends DatabaseTestCase
{
    private TemplateService $svc;

    public static function setUpBeforeClass(): void
    {
        $_ENV['MASTER_KEY'] = \bin2hex(\random_bytes(32));
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TemplateService();
    }

    public function test_list_available_returns_system_templates(): void
    {
        $templates = $this->svc->listAvailable();

        $this->assertGreaterThanOrEqual(4, \count($templates));
        $this->assertContains('Password', \array_map(fn($t) => $t->name, $templates));
    }

    public function test_generate_password_respects_overrides(): void
    {
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $password = $this->svc->generate((string) $template['uuid'], null, [
            'min_length'  => 24,
            'max_length'  => 24,
            'use_upper'   => true,
            'use_lower'   => true,
            'use_digits'  => true,
            'use_special' => false,
        ]);

        $this->assertSame(24, \strlen($password));
        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
        $this->assertMatchesRegularExpression('/[a-z]/', $password);
        $this->assertMatchesRegularExpression('/\d/', $password);
        $this->assertDoesNotMatchRegularExpression('/[^A-Za-z0-9]/', $password);
    }

    public function test_generate_ssh_key_returns_json_pair(): void
    {
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? AND name = ? LIMIT 1',
            ['ssh_key', 'SSH Key Ed25519']
        );

        $value = $this->svc->generate((string) $template['uuid']);
        $decoded = \json_decode($value, true);

        $this->assertIsArray($decoded);
        $this->assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', (string) $decoded['private_key']);
        $this->assertStringStartsWith('ssh-ed25519 ', (string) $decoded['public_key']);
        $this->assertSame('ed25519', $decoded['algorithm']);
    }
}
