<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель записи истории ротации секрета.
 *
 * Структура таблицы secret_rotation_history:
 *   - id, secret_id
 *   - encrypted_value (предыдущее зашифрованное значение), nonce
 *   - version (номер версии на момент ротации)
 *   - rotated_by (id пользователя или null)
 *   - rotation_type (manual|scheduled|api)
 *   - status (success|failed|rolled_back)
 *   - error_message (nullable), created_at
 */
final class SecretVersion
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $secretId,
        public readonly string  $encryptedValue,
        public readonly string  $nonce,
        public readonly int     $version,
        public readonly ?string $rotatedBy,
        public readonly string  $rotationType,
        public readonly string  $status,
        public readonly ?string $errorMessage,
        public readonly string  $createdAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Фабрика                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (string) $row['id'],
            secretId:       (string) $row['secret_id'],
            encryptedValue: (string) $row['encrypted_value'],
            nonce:          (string) $row['nonce'],
            version:        (int) $row['version'],
            rotatedBy:      isset($row['rotated_by']) && $row['rotated_by'] !== null
                ? (string) $row['rotated_by'] : null,
            rotationType:   (string) $row['rotation_type'],
            status:         (string) $row['status'],
            errorMessage:   isset($row['error_message']) && $row['error_message'] !== null
                ? (string) $row['error_message'] : null,
            createdAt:      (string) $row['created_at'],
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Последние версии секрета (по убыванию version), не более $limit записей.
     *
     * @return self[]
     */
    public static function findBySecretId(string $secretId, int $limit = 10): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM secret_rotation_history
             WHERE secret_id = ?
             ORDER BY version DESC
             LIMIT ?',
            [(int) $secretId, $limit]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }
}
