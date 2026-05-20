<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

final class RenameRolesAddResourceOwners extends Migration
{
    public function up(): void
    {
        $this->addColumn('directories', 'owner_user_id', 'BIGINT');
        $this->addColumn('secrets', 'owner_user_id', 'BIGINT');

        $this->exec('UPDATE directories SET owner_user_id = created_by WHERE owner_user_id IS NULL');
        $this->exec('UPDATE secrets SET owner_user_id = created_by WHERE owner_user_id IS NULL');

        $this->exec("UPDATE organization_members SET role = 'reader' WHERE role IN ('observer', 'user')");
        $this->exec("UPDATE organization_members SET role = 'editor' WHERE role = 'moderator'");

        $this->exec("UPDATE invite_links SET role = 'reader' WHERE role IN ('observer', 'user')");
        $this->exec("UPDATE invite_links SET role = 'editor' WHERE role = 'moderator'");
    }

    public function down(): void
    {
        $this->exec("UPDATE organization_members SET role = 'moderator' WHERE role = 'editor'");
        $this->exec("UPDATE organization_members SET role = 'user' WHERE role = 'reader'");

        $this->exec("UPDATE invite_links SET role = 'moderator' WHERE role = 'editor'");
        $this->exec("UPDATE invite_links SET role = 'user' WHERE role = 'reader'");

        $this->exec('ALTER TABLE secrets DROP COLUMN owner_user_id');
        $this->exec('ALTER TABLE directories DROP COLUMN owner_user_id');
    }
}
