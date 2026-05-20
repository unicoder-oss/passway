<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Thin model invite link.
 *
 * Types:
 *   create_org - register (new user) and create an organization
 *   join_org   - register or log in and join an organization
 */
final class InviteLink
{
    public const TYPE_CREATE_ORG = 'create_org';
    public const TYPE_JOIN_ORG   = 'join_org';

    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $token,
        public readonly string  $type,
        public readonly ?string $organizationId,
        public readonly string  $role,
        public readonly ?string $createdBy,
        public readonly ?string $usedBy,
        public readonly string  $expiresAt,
        public readonly ?string $usedAt,
        public readonly string  $createdAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Factory                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (string) $row['id'],
            uuid:           (string) $row['uuid'],
            token:          (string) $row['token'],
            type:           (string) $row['type'],
            organizationId: isset($row['organization_id']) && $row['organization_id'] !== null
                ? (string) $row['organization_id'] : null,
            role:           (string) $row['role'],
            createdBy:      isset($row['created_by']) && $row['created_by'] !== null
                ? (string) $row['created_by'] : null,
            usedBy:         isset($row['used_by']) && $row['used_by'] !== null
                ? (string) $row['used_by'] : null,
            expiresAt:      (string) $row['expires_at'],
            usedAt:         isset($row['used_at']) && $row['used_at'] !== null
                ? (string) $row['used_at'] : null,
            createdAt:      (string) $row['created_at'],
        );
    }

    // ------------------------------------------------------------------ //
    //  Queries                                                            //
    // ------------------------------------------------------------------ //

    public static function findByToken(string $token): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM invite_links WHERE token = ?',
            [$token]
        );
        return $row ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM invite_links WHERE uuid = ?',
            [$uuid]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Active (not expired, unused) organization invites.
     *
     * @return self[]
     */
    public static function findActiveByOrgId(string $orgId): array
    {
        $now  = now()->format('Y-m-d H:i:s');
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM invite_links
             WHERE organization_id = ? AND used_at IS NULL AND expires_at > ?
             ORDER BY created_at DESC',
            [(int) $orgId, $now]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    // ------------------------------------------------------------------ //
    //  Helpers                                                    //
    // ------------------------------------------------------------------ //

    public function isExpired(): bool
    {
        return $this->expiresAt <= now()->format('Y-m-d H:i:s');
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
    }
}
