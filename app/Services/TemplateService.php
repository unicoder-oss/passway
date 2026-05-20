<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Models\Template;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

/**
 * Value generation from built-in secret templates.
 */
final class TemplateService
{
    /** @return Template[] */
    public function listAvailable(?string $orgId = null, ?string $type = null): array
    {
        $templates = Template::findAvailableForOrg($orgId, $type);

        usort($templates, static function (Template $left, Template $right): int {
            if ($left->isSystem !== $right->isSystem) {
                return $left->isSystem ? -1 : 1;
            }

            return strcasecmp($left->displayName(), $right->displayName());
        });

        return $templates;
    }

    /**
     * Generate a value from a template.
     *
     * @param array<string, mixed> $overrides
     */
    public function generate(string $templateUuid, ?string $orgId = null, array $overrides = []): string
    {
        $preview = $this->preview($templateUuid, $orgId, $overrides);
        return $preview['value'];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{
     *   value:string,
     *   display_value:string,
     *   extra_fields:array<int, array{key:string, label:string, value:string}>,
     *   parameter_schema:array<int, array<string, mixed>>,
     *   overrides:array<string, mixed>,
     *   template:Template
     * }
     */
    public function preview(string $templateUuid, ?string $orgId = null, array $overrides = []): array
    {
        $template = $this->resolveTemplate($templateUuid, $orgId);
        $config = \array_replace($template->config(), $this->normalizeOverrides($template, $overrides));

        $value = match ($template->type) {
            'password' => $this->generatePassword($config),
            'ssh_key'  => $this->generateSshPrivateKey($config),
            default    => throw new \InvalidArgumentException(__('ui.backend.template.unsupported_type', ['type' => $template->type])),
        };

        return $this->buildDescription($template, $config, $value, true);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{
     *   value:string,
     *   display_value:string,
     *   extra_fields:array<int, array{key:string, label:string, value:string}>,
     *   parameter_schema:array<int, array<string, mixed>>,
     *   overrides:array<string, mixed>,
     *   template:Template
     * }
     */
    public function describeValue(string $templateUuid, string $value, ?string $orgId = null, array $overrides = []): array
    {
        $template = $this->resolveTemplate($templateUuid, $orgId);
        $config = \array_replace($template->config(), $this->normalizeOverrides($template, $overrides));

        return $this->buildDescription($template, $config, $value, false);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{
     *   value:string,
     *   display_value:string,
     *   extra_fields:array<int, array{key:string, label:string, value:string}>,
     *   parameter_schema:array<int, array<string, mixed>>,
     *   overrides:array<string, mixed>,
     *   template:Template
     * }
     */
    public function describeProvidedValue(string $templateUuid, string $value, ?string $orgId = null, array $overrides = []): array
    {
        $template = $this->resolveTemplate($templateUuid, $orgId);
        $config = \array_replace($template->config(), $this->normalizeOverrides($template, $overrides));

        return $this->buildDescription($template, $config, $value, false);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{
     *   value:string,
     *   display_value:string,
     *   extra_fields:array<int, array{key:string, label:string, value:string}>,
     *   parameter_schema:array<int, array<string, mixed>>,
     *   overrides:array<string, mixed>,
     *   template:Template
     * }
     */
    public function describeUploadedValue(string $templateUuid, string $value, ?string $orgId = null, array $overrides = []): array
    {
        $template = $this->resolveTemplate($templateUuid, $orgId);
        $config = \array_replace($template->config(), $this->normalizeOverrides($template, $overrides));

        return $this->buildDescription($template, $config, $value, true);
    }

    private function resolveTemplate(string $templateUuid, ?string $orgId = null): Template
    {
        $template = Template::findByUuid($templateUuid);
        if ($template === null) {
            throw new \RuntimeException(__('ui.backend.template.not_found'));
        }

        if ($template->organizationId !== null && $orgId !== null && $template->organizationId !== $orgId) {
            throw new \RuntimeException(__('ui.backend.template.wrong_org'));
        }

        return $template;
    }

    /** @param array<string, mixed> $overrides
     *  @return array<string, mixed>
     */
    private function normalizeOverrides(Template $template, array $overrides): array
    {
        if ($template->type !== 'password') {
            return $overrides;
        }

        if (isset($overrides['length']) && $overrides['length'] !== '') {
            $length = (int) $overrides['length'];
            $overrides['min_length'] = $length;
            $overrides['max_length'] = $length;
            unset($overrides['length']);
        }

        foreach (['min_length', 'max_length'] as $field) {
            if (isset($overrides[$field]) && $overrides[$field] !== '') {
                $overrides[$field] = (int) $overrides[$field];
            }
        }

        foreach (['use_upper', 'use_lower', 'use_digits', 'use_special'] as $field) {
            if (isset($overrides[$field])) {
                $overrides[$field] = $this->toBool($overrides[$field]);
            }
        }

        if (isset($overrides['special_chars'])) {
            $overrides['special_chars'] = (string) $overrides['special_chars'];
        }

        return $overrides;
    }

    /** @param array<string, mixed> $config
     *  @return array<int, array<string, mixed>>
     */
    private function buildParameterSchema(Template $template, array $config): array
    {
        if ($template->type !== 'password') {
            return [];
        }

        return [
            [
                'name' => 'length',
                'type' => 'number',
                'label' => __('ui.secret.template_length'),
                'min' => 8,
                'max' => 256,
                'value' => (int) ($config['min_length'] ?? 16),
            ],
            [
                'name' => 'use_upper',
                'type' => 'boolean',
                'label' => __('ui.secret.template_use_upper'),
                'value' => (bool) ($config['use_upper'] ?? true),
            ],
            [
                'name' => 'use_lower',
                'type' => 'boolean',
                'label' => __('ui.secret.template_use_lower'),
                'value' => (bool) ($config['use_lower'] ?? true),
            ],
            [
                'name' => 'use_digits',
                'type' => 'boolean',
                'label' => __('ui.secret.template_use_digits'),
                'value' => (bool) ($config['use_digits'] ?? true),
            ],
            [
                'name' => 'use_special',
                'type' => 'boolean',
                'label' => __('ui.secret.template_use_special'),
                'value' => (bool) ($config['use_special'] ?? true),
            ],
            [
                'name' => 'special_chars',
                'type' => 'text',
                'label' => __('ui.secret.template_special_chars'),
                'value' => (string) ($config['special_chars'] ?? '!@#$%^&*()-_=+[]{}|;:,.<>?'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   value:string,
     *   display_value:string,
     *   extra_fields:array<int, array{key:string, label:string, value:string}>,
     *   parameter_schema:array<int, array<string, mixed>>,
     *   overrides:array<string, mixed>,
     *   template:Template
     * }
     */
    private function buildDescription(Template $template, array $config, string $value, bool $normalizeValue): array
    {
        $describedValue = match ($template->type) {
            'password' => $this->describePasswordValue($value),
            'ssh_key' => $this->describeSshValue($value, $config, $normalizeValue),
            default => throw new \InvalidArgumentException(__('ui.backend.template.unsupported_type', ['type' => $template->type])),
        };

        return [
            'value' => $describedValue['value'],
            'display_value' => $describedValue['display_value'],
            'extra_fields' => $describedValue['extra_fields'],
            'parameter_schema' => $this->buildParameterSchema($template, $config),
            'overrides' => $config,
            'template' => $template,
        ];
    }

    /** @return array{value:string, display_value:string, extra_fields:array<int, array{key:string, label:string, value:string}>} */
    private function describePasswordValue(string $value): array
    {
        $length = \strlen($value);
        if ($length < 8 || $length > 256) {
            throw new \InvalidArgumentException(__('ui.backend.template.password_length'));
        }

        return [
            'value' => $value,
            'display_value' => $value,
            'extra_fields' => [],
        ];
    }

    /** @return array{value:string, display_value:string, extra_fields:array<int, array{key:string, label:string, value:string}>} */
    private function describeSshValue(string $value, array $config, bool $normalizeValue): array
    {
        $legacyDecoded = \json_decode($value, true);
        if (\is_array($legacyDecoded) && isset($legacyDecoded['private_key'], $legacyDecoded['public_key'])) {
            $privateKey = (string) $legacyDecoded['private_key'];
            $publicKey = (string) $legacyDecoded['public_key'];

            return [
                'value' => $normalizeValue ? $privateKey : $value,
                'display_value' => $privateKey,
                'extra_fields' => [[
                    'key' => 'public_key',
                    'label' => __('ui.secret.public_key'),
                    'value' => $publicKey,
                ]],
            ];
        }

        $comment = (string) ($config['comment'] ?? 'passway-generated');

        try {
            $privateKey = PublicKeyLoader::loadPrivateKey($value);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(__('ui.backend.template.ssh_private_key_invalid'));
        }

        $normalizedPrivateKey = $privateKey->toString('OpenSSH', ['comment' => $comment]);
        $publicKey = $privateKey->getPublicKey()->toString('OpenSSH', ['comment' => $comment]);

        return [
            'value' => $normalizeValue ? $normalizedPrivateKey : $value,
            'display_value' => $normalizeValue ? $normalizedPrivateKey : $value,
            'extra_fields' => [[
                'key' => 'public_key',
                'label' => __('ui.secret.public_key'),
                'value' => $publicKey,
            ]],
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_string($value)) {
            return \in_array(\strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }

    /** @param array<string, mixed> $config */
    private function generatePassword(array $config): string
    {
        $minLength = (int) ($config['min_length'] ?? 16);
        $maxLength = (int) ($config['max_length'] ?? $minLength);
        $useUpper = (bool) ($config['use_upper'] ?? true);
        $useLower = (bool) ($config['use_lower'] ?? true);
        $useDigits = (bool) ($config['use_digits'] ?? true);
        $useSpecial = (bool) ($config['use_special'] ?? true);
        $specialChars = (string) ($config['special_chars'] ?? '!@#$%^&*()-_=+[]{}|;:,.<>?');

        if ($minLength < 8 || $maxLength > 256 || $minLength > $maxLength) {
            throw new \InvalidArgumentException(__('ui.backend.template.password_length'));
        }

        $charsets = [];
        if ($useUpper) {
            $charsets[] = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }
        if ($useLower) {
            $charsets[] = 'abcdefghijklmnopqrstuvwxyz';
        }
        if ($useDigits) {
            $charsets[] = '0123456789';
        }
        if ($useSpecial) {
            if ($specialChars === '') {
                throw new \InvalidArgumentException(__('ui.backend.template.special_chars_empty'));
            }
            $charsets[] = $specialChars;
        }

        if ($charsets === []) {
            throw new \InvalidArgumentException(__('ui.backend.template.charset_required'));
        }

        $length = $minLength === $maxLength ? $minLength : \random_int($minLength, $maxLength);
        $allChars = \implode('', $charsets);
        $passwordChars = [];

        foreach ($charsets as $charset) {
            $passwordChars[] = $charset[\random_int(0, \strlen($charset) - 1)];
        }

        while (\count($passwordChars) < $length) {
            $passwordChars[] = $allChars[\random_int(0, \strlen($allChars) - 1)];
        }

        for ($i = \count($passwordChars) - 1; $i > 0; $i--) {
            $j = \random_int(0, $i);
            [$passwordChars[$i], $passwordChars[$j]] = [$passwordChars[$j], $passwordChars[$i]];
        }

        return \implode('', $passwordChars);
    }

    /** @param array<string, mixed> $config */
    private function generateSshPrivateKey(array $config): string
    {
        $algorithm = \strtolower((string) ($config['algorithm'] ?? 'ed25519'));

        if ($algorithm === 'rsa') {
            $bits = (int) ($config['bits'] ?? 4096);
            if (!\in_array($bits, [2048, 4096], true)) {
                throw new \InvalidArgumentException(__('ui.backend.template.rsa_bits_invalid'));
            }

            $privateKey = RSA::createKey($bits);
        } elseif ($algorithm === 'ed25519') {
            $privateKey = EC::createKey('Ed25519');
        } else {
            throw new \InvalidArgumentException(__('ui.backend.template.ssh_algorithm_invalid', ['algorithm' => $algorithm]));
        }

        return $privateKey->toString('OpenSSH', ['comment' => (string) ($config['comment'] ?? 'passway-generated')]);
    }
}
