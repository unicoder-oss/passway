<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Thin model session.
 * token_hash - SHA-256 of the raw token (64 hex characters); plaintext is NEVER stored.
 */
final class Session
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $userId,
        public readonly string  $tokenHash,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly string  $expiresAt,
        public readonly string  $createdAt,
        public readonly string  $lastActivityAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Factory from row DB                                               //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (string) $row['id'],
            uuid:           (string) $row['uuid'],
            userId:         (string) $row['user_id'],
            tokenHash:      (string) $row['token_hash'],
            ipAddress:      isset($row['ip_address']) ? (string) $row['ip_address'] : null,
            userAgent:      isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            expiresAt:      (string) $row['expires_at'],
            createdAt:      (string) $row['created_at'],
            lastActivityAt: (string) $row['last_activity_at'],
        );
    }

    // ------------------------------------------------------------------ //
    //  Queries                                                            //
    // ------------------------------------------------------------------ //

    public static function findByTokenHash(string $hash): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM sessions WHERE token_hash = ?',
            [$hash]
        );
        return $row ? self::fromRow($row) : null;
    }
}
