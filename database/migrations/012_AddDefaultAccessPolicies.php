<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

final class AddDefaultAccessPolicies extends Migration
{
    public function up(): void
    {
        $this->addColumn('directories', 'default_read_access', "VARCHAR(20) NOT NULL DEFAULT 'inherit'");
        $this->addColumn('directories', 'default_write_access', "VARCHAR(20) NOT NULL DEFAULT 'inherit'");
        $this->addColumn('secrets', 'default_read_access', "VARCHAR(20) NOT NULL DEFAULT 'inherit'");
        $this->addColumn('secrets', 'default_write_access', "VARCHAR(20) NOT NULL DEFAULT 'inherit'");
    }

    public function down(): void
    {
        $this->exec('ALTER TABLE secrets DROP COLUMN default_write_access');
        $this->exec('ALTER TABLE secrets DROP COLUMN default_read_access');
        $this->exec('ALTER TABLE directories DROP COLUMN default_write_access');
        $this->exec('ALTER TABLE directories DROP COLUMN default_read_access');
    }
}
