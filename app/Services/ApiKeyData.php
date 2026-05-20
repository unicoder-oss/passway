<?php

declare(strict_types=1);

namespace Passway\Services;

/**
 * Value Object — результат генерации API-ключа.
 *
 * fullKey передаётся пользователю ОДИН раз и больше не хранится.
 * keyPrefix хранится в БД для идентификации ключа в UI.
 */
final readonly class ApiKeyData
{
    public function __construct(
        /** Полный API-ключ: sv_{env}_{64hex} — показывается один раз */
        public string $fullKey,

        /** Видимый префикс: sv_prod_ — хранится в БД */
        public string $keyPrefix,

        /** Окружение: production | staging | development */
        public string $environment,
    ) {}
}
