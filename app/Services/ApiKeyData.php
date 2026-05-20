<?php

declare(strict_types=1);

namespace Passway\Services;

/**
 * Value Object - API key generation result.
 *
 * fullKey is given to the user ONCE and is not stored afterward.
 * keyPrefix is stored in the DB to identify the key in the UI.
 */
final readonly class ApiKeyData
{
    public function __construct(
        /** Full API key: sv_{64hex} - is shown once */
        public string $fullKey,

        /** Visible key prefix - stored in the DB */
        public string $keyPrefix,
    ) {}
}
