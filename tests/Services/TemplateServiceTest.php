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

    public function test_generate_password_allows_length_up_to_256(): void
    {
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $password = $this->svc->generate((string) $template['uuid'], null, [
            'min_length' => 256,
            'max_length' => 256,
            'use_special' => false,
        ]);

        $this->assertSame(256, \strlen($password));
    }

    public function test_generate_password_rejects_length_above_256(): void
    {
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->generate((string) $template['uuid'], null, [
            'min_length' => 257,
            'max_length' => 257,
        ]);
    }

    public function test_generate_ssh_key_returns_private_key_string(): void
    {
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? AND name = ? LIMIT 1',
            ['ssh_key', 'SSH Key Ed25519']
        );

        $value = $this->svc->generate((string) $template['uuid']);

        $this->assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', $value);
    }

    public function test_generate_ssh_key_returns_new_value_each_time(): void
    {
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? AND name = ? LIMIT 1',
            ['ssh_key', 'SSH Key Ed25519']
        );

        $first = $this->svc->generate((string) $template['uuid']);
        $second = $this->svc->generate((string) $template['uuid']);

        $this->assertNotSame($first, $second);
    }

    public function test_preview_password_returns_parameter_schema(): void
    {
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? ORDER BY id ASC LIMIT 1',
            ['password']
        );

        $preview = $this->svc->preview((string) $template['uuid'], null, ['length' => 24, 'use_special' => false]);

        $this->assertSame(24, $preview['overrides']['min_length']);
        $this->assertSame(24, $preview['overrides']['max_length']);
        $this->assertFalse($preview['overrides']['use_special']);
        $this->assertNotEmpty($preview['parameter_schema']);
    }

    public function test_preview_ssh_key_exposes_public_key_extra_field(): void
    {
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? AND name = ? LIMIT 1',
            ['ssh_key', 'SSH Key Ed25519']
        );

        $preview = $this->svc->preview((string) $template['uuid']);

        $this->assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', $preview['display_value']);
        $this->assertCount(1, $preview['extra_fields']);
        $this->assertSame('public_key', $preview['extra_fields'][0]['key']);
        $this->assertStringStartsWith('ssh-ed25519 ', $preview['extra_fields'][0]['value']);
    }

    public function test_describe_provided_ssh_private_key_normalizes_and_derives_public_key(): void
    {
        $template = Database::getInstance()->fetchOne(
            'SELECT uuid FROM templates WHERE type = ? AND name = ? LIMIT 1',
            ['ssh_key', 'SSH Key Ed25519']
        );
        $generated = $this->svc->generate((string) $template['uuid']);

        $described = $this->svc->describeProvidedValue((string) $template['uuid'], $generated);

        $this->assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', $described['value']);
        $this->assertStringStartsWith('ssh-ed25519 ', $described['extra_fields'][0]['value']);
    }
}
