<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

final class RemoveApiKeyEnvironment extends Migration
{
    public function up(): void
    {
        $this->exec('ALTER TABLE api_keys DROP COLUMN environment');
    }

    public function down(): void
    {
        $this->exec("ALTER TABLE api_keys ADD COLUMN environment VARCHAR(50) NOT NULL DEFAULT 'production'");
    }
}
