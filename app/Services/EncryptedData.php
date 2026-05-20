<?php

declare(strict_types=1);

namespace Passway\Services;

/**
 * Value Object — результат шифрования.
 *
 * Содержит зашифрованное значение и nonce, необходимые для расшифровки.
 * Безопасно сериализуется для хранения в БД.
 *
 * @property-read string $value  base64(ciphertext + auth tag)
 * @property-read string $nonce  hex(24 bytes) = 48 символов
 */
final readonly class EncryptedData
{
    public function __construct(
        /** base64(XChaCha20-Poly1305 ciphertext + 16-byte MAC) */
        public string $value,

        /** hex-encoded nonce (24 байта = 48 символов) */
        public string $nonce,
    ) {}

    /**
     * Создать из массива (например, из строки БД).
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
     * Сериализовать в массив для записи в БД.
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
