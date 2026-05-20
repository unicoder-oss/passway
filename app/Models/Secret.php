<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель секрета.
 *
 * Структура таблицы secrets:
 *   - id, uuid, directory_id, organization_id
 *   - name, type (static|template|dynamic)
 *   - encrypted_value (XChaCha20-Poly1305, base64), nonce (hex, 48 chars)
 *   - template_id (nullable), requires_approval (bool)
 *   - rotation_integration_id, rotation_schedule, last_rotated_at
 *   - version (для истории ротаций), created_by
 *   - created_at, updated_at, deleted_at (soft delete)
 */
final class Secret
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $uuid,
        public readonly string  $directoryId,
        public readonly string  $organizationId,
        public readonly string  $name,
        public readonly string  $type,
        public readonly string  $encryptedValue,
        public readonly string  $nonce,
        public readonly ?string $templateId,
        public readonly bool    $requiresApproval,
        public readonly ?string $rotationIntegrationId,
        public readonly ?string $rotationSchedule,
        public readonly ?string $lastRotatedAt,
        public readonly int     $version,
        public readonly ?string $createdBy,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly ?string $deletedAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Фабрика                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                    (string) $row['id'],
            uuid:                  (string) $row['uuid'],
            directoryId:           (string) $row['directory_id'],
            organizationId:        (string) $row['organization_id'],
            name:                  (string) $row['name'],
            type:                  (string) $row['type'],
            encryptedValue:        (string) $row['encrypted_value'],
            nonce:                 (string) $row['nonce'],
            templateId:            isset($row['template_id']) && $row['template_id'] !== null
                ? (string) $row['template_id'] : null,
            requiresApproval:      (bool) $row['requires_approval'],
            rotationIntegrationId: isset($row['rotation_integration_id']) && $row['rotation_integration_id'] !== null
                ? (string) $row['rotation_integration_id'] : null,
            rotationSchedule:      isset($row['rotation_schedule']) && $row['rotation_schedule'] !== null
                ? (string) $row['rotation_schedule'] : null,
            lastRotatedAt:         isset($row['last_rotated_at']) && $row['last_rotated_at'] !== null
                ? (string) $row['last_rotated_at'] : null,
            version:               (int) $row['version'],
            createdBy:             isset($row['created_by']) && $row['created_by'] !== null
                ? (string) $row['created_by'] : null,
            createdAt:             (string) $row['created_at'],
            updatedAt:             (string) $row['updated_at'],
            deletedAt:             isset($row['deleted_at']) && $row['deleted_at'] !== null
                ? (string) $row['deleted_at'] : null,
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM secrets WHERE id = ? AND deleted_at IS NULL',
            [(int) $id]
        );
        return $row ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM secrets WHERE uuid = ? AND deleted_at IS NULL',
            [$uuid]
        );
        return $row ? self::fromRow($row) : null;
    }

    /**
     * Все не удалённые секреты каталога (сортировка по имени).
     *
     * @return self[]
     */
    public static function findByDirId(string $dirId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM secrets WHERE directory_id = ? AND deleted_at IS NULL ORDER BY name',
            [(int) $dirId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    // ------------------------------------------------------------------ //
    //  Запись                                                             //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $data */
    public function update(array $data): void
    {
        Database::getInstance()->update('secrets', $data, ['id' => $this->id]);
    }
}
