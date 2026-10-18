<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class MisSyncJobs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'active_guard' => ['type' => 'TINYINT', 'null' => true],
            'actor_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'kind' => ['type' => 'VARCHAR', 'constraint' => 16],
            'dry_run' => ['type' => 'TINYINT'],
            'page_size' => ['type' => 'INT'],
            'max_pages' => ['type' => 'INT'],
            'source' => ['type' => 'VARCHAR', 'constraint' => 16],
            'status' => ['type' => 'VARCHAR', 'constraint' => 16],
            'kinds_json' => ['type' => 'TEXT'],
            'current_kind' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'current_page' => ['type' => 'INT', 'default' => 0],
            'total_pages' => ['type' => 'INT', 'null' => true],
            'owner_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'heartbeat_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'error_code' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'error_message' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'active_guard']);
        $this->forge->addKey(['tenant_id', 'created_at']);
        $this->forge->createTable('mis_sync_jobs', true, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('mis_sync_jobs', true);
    }
}
