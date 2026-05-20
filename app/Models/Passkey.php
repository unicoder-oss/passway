<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель WebAuthn-ключа (passkey / FIDO2 credential).
 * public_key хранит полный JSON PublicKeyCredentialSource от webauthn-lib.
 */
final class Passkey
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $userId,
        public readonly string  $credentialId,   // base64url-encoded bytes
        public readonly string  $publicKey,       // JSON (PublicKeyCredentialSource)
        public readonly int     $signCount,
        public readonly string  $name,
        public readonly ?string $aaguid,
        public readonly ?string $transports,      // JSON array
        public readonly string  $createdAt,
        public readonly ?string $lastUsedAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Фабрика из строки БД                                               //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:           (string) $row['id'],
            uuid:         (string) $row['uuid'],
            userId:       (string) $row['user_id'],
            credentialId: (string) $row['credential_id'],
            publicKey:    (string) $row['public_key'],
            signCount:    (int) ($row['sign_count'] ?? 0),
            name:         (string) ($row['name'] ?? 'Passkey'),
            aaguid:       isset($row['aaguid']) ? (string) $row['aaguid'] : null,
            transports:   isset($row['transports']) ? (string) $row['transports'] : null,
            createdAt:    (string) $row['created_at'],
            lastUsedAt:   isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    public static function findByCredentialId(string $credentialId): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM passkeys WHERE credential_id = ?',
            [$credentialId]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * @return self[]
     */
    public static function findByUserId(string $userId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM passkeys WHERE user_id = ? ORDER BY created_at DESC',
            [$userId]
        );
        return \array_map(fn($row) => self::fromRow($row), $rows);
    }
}
