<?php

declare(strict_types=1);

namespace Passway\Database\Migrations;

use Passway\Database\Migration;

final class AddTemplateSystemKeys extends Migration
{
    public function up(): void
    {
        if (!$this->hasSystemKeyColumn()) {
            $this->addColumn('templates', 'system_key', 'VARCHAR(120)');
        }

        $updates = [
            'password.default' => ['password', 'Password'],
            'password.strong_no_special' => ['password', 'Strong Password (No Special)'],
            'ssh_key.rsa_4096' => ['ssh_key', 'SSH Key RSA-4096'],
            'ssh_key.ed25519' => ['ssh_key', 'SSH Key Ed25519'],
        ];

        foreach ($updates as $systemKey => [$type, $name]) {
            $this->db->getPdo()->prepare(
                'UPDATE templates SET system_key = ? WHERE is_system = 1 AND organization_id IS NULL AND type = ? AND name = ?'
            )->execute([$systemKey, $type, $name]);
        }
    }

    public function down(): void
    {
        if ($this->hasSystemKeyColumn()) {
            $this->exec('ALTER TABLE templates DROP COLUMN system_key');
        }
    }

    private function hasSystemKeyColumn(): bool
    {
        if ($this->driver === 'pgsql') {
            $exists = $this->db->fetchColumn(
                "SELECT 1 FROM information_schema.columns WHERE table_name = 'templates' AND column_name = 'system_key'"
            );

            return $exists !== null;
        }

        $columns = $this->db->fetchAll("PRAGMA table_info('templates')");
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === 'system_key') {
                return true;
            }
        }

        return false;
    }
}
