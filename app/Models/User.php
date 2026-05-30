<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Thin user model - read/write only through Database.
 * Business logic lives in Services.
 */
final class User
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $email,
        public readonly ?string $nickname,
        public readonly ?string $avatarColor,
        public readonly ?string $avatarPath,
        public readonly ?string $passwordHash,
        public readonly ?string $totpSecret,
        public readonly ?string $totpNonce,
        public readonly bool    $totpEnabled,
        public readonly bool    $isActive,
        public readonly bool    $emailVerified,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly ?string $lastLoginAt,
        public readonly ?string $lastLoginIp,
    ) {}

    // ------------------------------------------------------------------ //
    //  Factory from row DB                                               //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:            (string) $row['id'],
            uuid:          (string) $row['uuid'],
            email:         (string) $row['email'],
            nickname:      isset($row['nickname']) ? (string) $row['nickname'] : null,
            avatarColor:   isset($row['avatar_color']) ? (string) $row['avatar_color'] : null,
            avatarPath:    isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
            passwordHash:  isset($row['password_hash']) ? (string) $row['password_hash'] : null,
            totpSecret:    isset($row['totp_secret']) ? (string) $row['totp_secret'] : null,
            totpNonce:     isset($row['totp_nonce']) ? (string) $row['totp_nonce'] : null,
            totpEnabled:   (bool) ($row['totp_enabled'] ?? false),
            isActive:      (bool) ($row['is_active'] ?? true),
            emailVerified: (bool) ($row['email_verified'] ?? false),
            createdAt:     (string) $row['created_at'],
            updatedAt:     (string) $row['updated_at'],
            lastLoginAt:   isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            lastLoginIp:   isset($row['last_login_ip']) ? (string) $row['last_login_ip'] : null,
        );
    }

    // ------------------------------------------------------------------ //
    //  Queries                                                            //
    // ------------------------------------------------------------------ //

    public static function findById(int|string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM users WHERE id = ?',
            [(int) $id]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<string, self>
     */
    public static function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map(static fn(int|string $id): int => (int) $id, $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM users WHERE id IN (' . $placeholders . ')',
            $ids,
        );

        $users = [];
        foreach ($rows as $row) {
            $user = self::fromRow($row);
            $users[$user->id] = $user;
        }

        return $users;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM users WHERE uuid = ?',
            [$uuid]
        );
        return $row ? self::fromRow($row) : null;
    }

    public static function findByEmail(string $email): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM users WHERE email = ?',
            [\strtolower(\trim($email))]
        );
        return $row ? self::fromRow($row) : null;
    }

    // ------------------------------------------------------------------ //
    //  Writes                                                             //
    // ------------------------------------------------------------------ //

    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): self
    {
        $db = Database::getInstance();
        $id = $db->insert('users', $data);
        $user = self::findById((int) $id);
        if ($user === null) {
            throw new \RuntimeException('Failed to load created user from DB');
        }
        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): void
    {
        Database::getInstance()->update('users', $data, ['id' => $this->id]);
    }

    /**
     * Number of users in the system (for solo mode).
     */
    public static function count(): int
    {
        return (int) Database::getInstance()->fetchColumn('SELECT COUNT(*) FROM users');
    }
}
