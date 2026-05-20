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
        $template = Template::findByUuid($templateUuid);
        if ($template === null) {
            throw new \RuntimeException('Template not found.');
        }

        if ($template->organizationId !== null && $orgId !== null && $template->organizationId !== $orgId) {
            throw new \RuntimeException('Template does not belong to this organization.');
        }

        $config = \array_replace($template->config(), $overrides);

        return match ($template->type) {
            'password' => $this->generatePassword($config),
            'ssh_key'  => $this->generateSshKeyPair($config),
            default    => throw new \InvalidArgumentException('Unsupported template type: ' . $template->type),
        };
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

        if ($minLength < 8 || $maxLength > 128 || $minLength > $maxLength) {
            throw new \InvalidArgumentException('Password template length must be between 8 and 128 characters.');
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
                throw new \InvalidArgumentException('special_chars cannot be empty when use_special is enabled.');
            }
            $charsets[] = $specialChars;
        }

        if ($charsets === []) {
            throw new \InvalidArgumentException('At least one character set must be enabled.');
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
                throw new \InvalidArgumentException('RSA template bits must be 2048 or 4096.');
            }

            $privateKey = RSA::createKey($bits);
        } elseif ($algorithm === 'ed25519') {
            $privateKey = EC::createKey('Ed25519');
        } else {
            throw new \InvalidArgumentException('Unsupported SSH key algorithm: ' . $algorithm);
        }

        $publicKey = $privateKey->getPublicKey();

        return \json_encode([
            'private_key' => $privateKey->toString('OpenSSH', ['comment' => $comment]),
            'public_key'  => $publicKey->toString('OpenSSH', ['comment' => $comment]),
            'algorithm'   => $algorithm,
            'comment'     => $comment,
        ], \JSON_UNESCAPED_SLASHES)
            ?: throw new \RuntimeException('Failed to encode SSH key pair as JSON.');
    }
}
