<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Шаблон генерации секрета.
 *
 * Системные шаблоны имеют `organization_id = NULL` и `is_system = true`.
 */
final class Template
{
    public function __construct(
        public readonly string $id,
        public readonly string $uuid,
        public readonly ?string $organizationId,
        public readonly string $name,
        public readonly ?string $systemKey,
        public readonly string $type,
        public readonly ?string $description,
        public readonly string $configJson,
        public readonly bool $isSystem,
        public readonly ?string $createdBy,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (string) $row['id'],
            uuid:           (string) $row['uuid'],
            organizationId: $row['organization_id'] !== null ? (string) $row['organization_id'] : null,
            name:           (string) $row['name'],
            systemKey:      $row['system_key'] !== null ? (string) $row['system_key'] : null,
            type:           (string) $row['type'],
            description:    $row['description'] !== null ? (string) $row['description'] : null,
            configJson:     (string) $row['config_json'],
            isSystem:       (bool) $row['is_system'],
            createdBy:      $row['created_by'] !== null ? (string) $row['created_by'] : null,
            createdAt:      (string) $row['created_at'],
            updatedAt:      (string) $row['updated_at'],
        );
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        $decoded = \json_decode($this->configJson, true);
        return \is_array($decoded) ? $decoded : [];
    }

    public function displayName(): string
    {
        if ($this->systemKey === null || $this->systemKey === '') {
            return $this->name;
        }

        $key = 'ui.templates.system.' . $this->systemKey . '.name';
        $translated = __($key);

        return $translated !== $key ? $translated : $this->name;
    }

    public function displayDescription(): ?string
    {
        if ($this->systemKey === null || $this->systemKey === '') {
            return $this->description;
        }

        $key = 'ui.templates.system.' . $this->systemKey . '.description';
        $translated = __($key);

        return $translated !== $key ? $translated : $this->description;
    }

    public static function findById(string $id): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM templates WHERE id = ?',
            [(int) $id]
        );

        return $row !== null ? self::fromRow($row) : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        $row = Database::getInstance()->fetchOne(
            'SELECT * FROM templates WHERE uuid = ?',
            [$uuid]
        );

        return $row !== null ? self::fromRow($row) : null;
    }

    /** @return self[] */
    public static function findAvailableForOrg(?string $orgId = null, ?string $type = null): array
    {
        $sql = 'SELECT * FROM templates WHERE (organization_id IS NULL';
        $params = [];

        if ($orgId !== null) {
            $sql .= ' OR organization_id = ?';
            $params[] = (int) $orgId;
        }

        $sql .= ')';

        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }

        $sql .= ' ORDER BY is_system DESC, name ASC';

        $rows = Database::getInstance()->fetchAll($sql, $params);
        return \array_map(fn($row) => self::fromRow($row), $rows);
    }
}
