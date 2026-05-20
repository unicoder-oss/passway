<?php

declare(strict_types=1);

namespace Passway\Services;

final class RotationSchemaValidator
{
    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function normalizeValues(array $fields, array $values): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            $name = isset($field['name']) && \is_string($field['name']) ? \trim($field['name']) : '';
            if ($name === '') {
                continue;
            }

            $label = isset($field['label']) && \is_string($field['label']) && \trim($field['label']) !== ''
                ? \trim($field['label'])
                : $name;
            $hasValue = \array_key_exists($name, $values);
            $value = $hasValue ? $values[$name] : ($field['default'] ?? null);

            if (($field['required'] ?? false) && self::isMissing($value)) {
                throw new \InvalidArgumentException(__('ui.backend.rotation.field_required', ['field' => $label]));
            }

            if (self::isMissing($value)) {
                continue;
            }

            $normalized[$name] = self::normalizeFieldValue($field, $value, $label);
        }

        return $normalized;
    }

    private static function isMissing(mixed $value): bool
    {
        return $value === null || (\is_string($value) && \trim($value) === '');
    }

    /** @param array<string, mixed> $field */
    private static function normalizeFieldValue(array $field, mixed $value, string $label): mixed
    {
        $type = isset($field['type']) && \is_string($field['type']) ? $field['type'] : 'string';

        return match ($type) {
            'integer' => self::normalizeInteger($value, $label),
            'boolean' => self::normalizeBoolean($value, $label),
            'enum' => self::normalizeEnum($field, $value, $label),
            default => self::normalizeStringLike($value),
        };
    }

    private static function normalizeInteger(mixed $value, string $label): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^-?\d+$/', \trim($value)) === 1) {
            return (int) \trim($value);
        }

        throw new \InvalidArgumentException(__('ui.backend.rotation.field_integer', ['field' => $label]));
    }

    private static function normalizeBoolean(mixed $value, string $label): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_string($value)) {
            $normalized = \strtolower(\trim($value));
            return match ($normalized) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,
                default => throw new \InvalidArgumentException(__('ui.backend.rotation.field_boolean', ['field' => $label])),
            };
        }

        throw new \InvalidArgumentException(__('ui.backend.rotation.field_boolean', ['field' => $label]));
    }

    /** @param array<string, mixed> $field */
    private static function normalizeEnum(array $field, mixed $value, string $label): string
    {
        $normalized = self::normalizeStringLike($value);
        $options = [];

        foreach (($field['options'] ?? []) as $option) {
            if (\is_array($option) && isset($option['value']) && \is_scalar($option['value'])) {
                $options[] = (string) $option['value'];
                continue;
            }
            if (\is_scalar($option)) {
                $options[] = (string) $option;
            }
        }

        if ($options !== [] && !\in_array($normalized, $options, true)) {
            throw new \InvalidArgumentException(__('ui.backend.rotation.field_option', ['field' => $label]));
        }

        return $normalized;
    }

    private static function normalizeStringLike(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(__('ui.backend.rotation.field_string'));
    }
}
