<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Модель API-ключа.
 *
 * Формат ключа: sv_{envPrefix}_{64 random hex chars}
 * В БД хранится только SHA-256 хэш; сам ключ показывается ОДИН раз при создании.
 */
final class ApiKey
{
    /** @var array<string> */
    public const VALID_ENVIRONMENTS = ['production', 'staging', 'development'];

    /** @var array<string,string> */
    public const ENV_PREFIXES = [
        'production'  => 'prod',
        'staging'     => 'stg',
        'development' => 'dev',
    ];

    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $organizationId,
        public readonly ?string $userId,
        public readonly string  $name,
        public readonly string  $keyHash,
        public readonly string  $keyPrefix,
        public readonly string  $environment,
        public readonly bool    $isActive,
        public readonly ?string $lastUsedAt,
        public readonly ?string $expiresAt,
        public readonly string  $createdAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Проверка валидности                                                //
    // ------------------------------------------------------------------ //

    public function isValid(): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->expiresAt !== null && strtotime($this->expiresAt) <= time()) {
            return false;
        }

        return true;
    }

    public function touchLastUsed(): void
    {
        Database::getInstance()->update(
            'api_keys',
            ['last_used_at' => date('Y-m-d H:i:s')],
            ['id' => $this->id]
        );
    }

    // ------------------------------------------------------------------ //
    //  Поиск                                                              //
    // ------------------------------------------------------------------ //

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM api_keys WHERE id = ?',
            [$id]
        );
        return $row !== null ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM api_keys WHERE uuid = ?',
            [$uuid]
        );
        return $row !== null ? self::fromRow($row) : null;
    }

    public static function findByHash(string $hash): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM api_keys WHERE key_hash = ?',
            [$hash]
        );
        return $row !== null ? self::fromRow($row) : null;
    }

    /** @return self[] */
    public static function findByOrgId(string $orgId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM api_keys WHERE organization_id = ? ORDER BY created_at DESC',
            [$orgId]
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    // ------------------------------------------------------------------ //
    //  Гидрация                                                           //
    // ------------------------------------------------------------------ //

    private static function fromRow(array $row): self
    {
        return new self(
            id:             (string) $row['id'],
            uuid:           (string) $row['uuid'],
            organizationId: (string) $row['organization_id'],
            userId:         $row['user_id'] !== null ? (string) $row['user_id'] : null,
            name:           (string) $row['name'],
            keyHash:        (string) $row['key_hash'],
            keyPrefix:      (string) $row['key_prefix'],
            environment:    (string) $row['environment'],
            isActive:       (bool)(int) $row['is_active'],
            lastUsedAt:     $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            expiresAt:      $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            createdAt:      (string) $row['created_at'],
        );
    }
}
