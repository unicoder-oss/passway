<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Гранулярные права API-ключа на ресурсы.
 *
 * resource_id = NULL означает права на все ресурсы указанного типа.
 */
final class ApiKeyPermission
{
    /** @var array<string> */
    public const VALID_RESOURCE_TYPES = ['directory', 'secret', 'organization'];

    /** @var array<string> */
    public const VALID_PERMISSIONS = ['read', 'write', 'delete', 'create_subdirectories'];

    public function __construct(
        public readonly string  $id,
        public readonly string  $apiKeyId,
        public readonly string  $resourceType,
        public readonly ?string $resourceId,
        public readonly string  $permission,
        public readonly string  $createdAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Поиск                                                              //
    // ------------------------------------------------------------------ //

    /** @return self[] */
    public static function findByKeyId(string $keyId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM api_key_permissions WHERE api_key_id = ? ORDER BY id ASC',
            [$keyId]
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Проверяет, есть ли у ключа указанное право на ресурс.
     * Запись с resource_id = NULL действует как wildcard (все ресурсы данного типа).
     */
    public static function canDo(
        string  $keyId,
        string  $permission,
        string  $resourceType,
        ?string $resourceId = null
    ): bool {
        $db  = Database::getInstance();
        $row = $db->fetchOne(
            'SELECT id FROM api_key_permissions
              WHERE api_key_id    = ?
                AND permission    = ?
                AND resource_type = ?
                AND (resource_id IS NULL OR resource_id = ?)',
            [$keyId, $permission, $resourceType, $resourceId]
        );
        return $row !== null;
    }

    // ------------------------------------------------------------------ //
    //  Гидрация                                                           //
    // ------------------------------------------------------------------ //

    private static function fromRow(array $row): self
    {
        return new self(
            id:           (string) $row['id'],
            apiKeyId:     (string) $row['api_key_id'],
            resourceType: (string) $row['resource_type'],
            resourceId:   $row['resource_id'] !== null ? (string) $row['resource_id'] : null,
            permission:   (string) $row['permission'],
            createdAt:    (string) $row['created_at'],
        );
    }
}
