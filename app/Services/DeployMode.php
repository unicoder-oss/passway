<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;

final class DeployMode
{
    public const SOLO = 'solo';
    public const TEAM = 'team';

    public static function current(): string
    {
        $mode = Database::getInstance()->fetchColumn(
            "SELECT value FROM system_config WHERE key = 'deploy_mode'"
        );

        return $mode === self::TEAM ? self::TEAM : self::SOLO;
    }

    public static function isSolo(): bool
    {
        return self::current() === self::SOLO;
    }

    public static function isTeam(): bool
    {
        return self::current() === self::TEAM;
    }
}
