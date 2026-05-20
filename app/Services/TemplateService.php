<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Models\Template;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

/**
 * Генерация значений по встроенным шаблонам секрета.
 */
final class TemplateService
{
    /** @return Template[] */
    public function listAvailable(?string $orgId = null, ?string $type = null): array
    {
        return Template::findAvailableForOrg($orgId, $type);
    }

    /**
     * Сгенерировать значение по шаблону.
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
            'ssh_key'  => $this->generateSshKeyPair($config),
            default    => throw new \InvalidArgumentException(__('ui.backend.template.unsupported_type', ['type' => $template->type])),
        };

        return [
            'value' => $value,
            'display_value' => $this->extractDisplayValue($template, $value),
            'extra_fields' => $this->extractExtraFields($template, $value),
            'parameter_schema' => $this->buildParameterSchema($template, $config),
            'overrides' => $config,
            'template' => $template,
        ];
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

        return [
            'value' => $value,
            'display_value' => $this->extractDisplayValue($template, $value),
            'extra_fields' => $this->extractExtraFields($template, $value),
            'parameter_schema' => $this->buildParameterSchema($template, $config),
            'overrides' => $config,
            'template' => $template,
        ];
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

    /** @return array<int, array{key:string, label:string, value:string}> */
    private function extractExtraFields(Template $template, string $value): array
    {
        if ($template->type !== 'ssh_key') {
            return [];
        }

        $decoded = \json_decode($value, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $publicKey = $decoded['public_key'] ?? null;
        if (!\is_string($publicKey) || $publicKey === '') {
            return [];
        }

        return [[
            'key' => 'public_key',
            'label' => __('ui.secret.public_key'),
            'value' => $publicKey,
        ]];
    }

    private function extractDisplayValue(Template $template, string $value): string
    {
        if ($template->type !== 'ssh_key') {
            return $value;
        }

        $decoded = \json_decode($value, true);
        if (!\is_array($decoded) || !isset($decoded['private_key']) || !\is_string($decoded['private_key'])) {
            return $value;
        }

        return $decoded['private_key'];
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
    private function generateSshKeyPair(array $config): string
    {
        $algorithm = \strtolower((string) ($config['algorithm'] ?? 'ed25519'));
        $comment = (string) ($config['comment'] ?? 'passway-generated');

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

        $publicKey = $privateKey->getPublicKey();

        return \json_encode([
            'private_key' => $privateKey->toString('OpenSSH', ['comment' => $comment]),
            'public_key'  => $publicKey->toString('OpenSSH', ['comment' => $comment]),
            'algorithm'   => $algorithm,
            'comment'     => $comment,
        ], \JSON_UNESCAPED_SLASHES)
            ?: throw new \RuntimeException(__('ui.backend.template.ssh_encode_failed'));
    }
}
