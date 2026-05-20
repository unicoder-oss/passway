<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

final class SecretMetadata
{
    public function __construct(
        public readonly string $id,
        public readonly string $secretId,
        public readonly string $key,
        public readonly ?string $value,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (string) $row['id'],
            secretId: (string) $row['secret_id'],
            key: (string) $row['key'],
            value: $row['value'] !== null ? (string) $row['value'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }

    public static function findBySecretIdAndKey(string $secretId, string $key): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM secret_metadata WHERE secret_id = ? AND key = ?',
            [(int) $secretId, $key]
        );

        return $row !== null ? self::fromRow($row) : null;
    }

    /** @return array<string, string|null> */
    public static function findMapBySecretId(string $secretId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT key, value FROM secret_metadata WHERE secret_id = ? ORDER BY key ASC',
            [(int) $secretId]
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['key']] = $row['value'] !== null ? (string) $row['value'] : null;
        }

        return $map;
    }

    public static function upsert(string $secretId, string $key, ?string $value): void
    {
        $existing = self::findBySecretIdAndKey($secretId, $key);
        $now = now()->format('Y-m-d H:i:s');

        if ($existing !== null) {
            Database::getInstance()->update('secret_metadata', [
                'value' => $value,
                'updated_at' => $now,
            ], ['id' => $existing->id]);

            return;
        }

        Database::getInstance()->insert('secret_metadata', [
            'secret_id' => (int) $secretId,
            'key' => $key,
            'value' => $value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
