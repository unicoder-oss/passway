<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

final class AddApprovalRequesterActors extends Migration
{
    public function up(): void
    {
        $this->addColumn('approval_requests', 'requester_type', "VARCHAR(20) NOT NULL DEFAULT 'user'");
        $this->addColumn('approval_requests', 'requester_id', 'BIGINT');
        $this->createIndex('approval_requests', ['requester_type', 'requester_id']);

        $this->exec("UPDATE approval_requests SET requester_type = 'user' WHERE requester_type IS NULL OR requester_type = ''");
        $this->exec('UPDATE approval_requests SET requester_id = requested_by WHERE requester_id IS NULL');
    }

    public function down(): void
    {
        $this->dropIndex('approval_requests', ['requester_type', 'requester_id']);
        $this->exec('ALTER TABLE approval_requests DROP COLUMN requester_id');
        $this->exec('ALTER TABLE approval_requests DROP COLUMN requester_type');
    }
}
