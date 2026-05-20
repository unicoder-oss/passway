<?php

declare(strict_types=1);

namespace Passway\Services;

/**
 * Value Object - encryption result.
 *
 * Contains the encrypted value and nonce needed for decryption.
 * Safely serializes for storage in the DB.
 *
 * @property-read string $value  base64(ciphertext + auth tag)
 * @property-read string $nonce  hex(24 bytes) = 48 characters
 */
final readonly class EncryptedData
{
    public function __construct(
        /** base64(XChaCha20-Poly1305 ciphertext + 16-byte MAC) */
        public string $value,

        /** hex-encoded nonce (24 bytes = 48 characters) */
        public string $nonce,
    ) {}

    /**
     * Create from an array (for example, from a row DB).
     *
     * @param array{value: string, nonce: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            value: $data['value'] ?? $data['encrypted_value'] ?? '',
            nonce: $data['nonce'] ?? '',
        );
    }

    /**
     * Serialize to an array for writing to the DB.
     *
     * @return array{encrypted_value: string, nonce: string}
     */
    public function toArray(): array
    {
        return [
            'encrypted_value' => $this->value,
            'nonce'           => $this->nonce,
        ];
    }
}
