<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AuditOutbox — transactional outbox writer.
 *
 * Same-transaction writer; `audit_events` is the immutable, append-only
 * landing zone. The background drain command lives in Phase 2.
 */
final class AuditOutbox extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'action_code'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'entity_type'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'entity_id'       => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'actor_user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'context_json'    => ['type' => 'JSON', 'null' => true],
            'request_id'      => ['type' => 'CHAR', 'constraint' => 32, 'null' => true],
            'created_at'      => ['type' => 'DATETIME(6)', 'null' => false],
            'processed_at'    => ['type' => 'DATETIME(6)', 'null' => true],
            'attempt_count'   => ['type' => 'TINYINT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'last_error'      => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['processed_at', 'created_at'], false, false, 'ix_outbox_unsent');
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->addKey('action_code');
        $this->forge->createTable(SYNAPSE_AUDIT_OUTBOX);
    }

    public function down(): void
    {
        $this->forge->dropTable(SYNAPSE_AUDIT_OUTBOX, true);
    }
}