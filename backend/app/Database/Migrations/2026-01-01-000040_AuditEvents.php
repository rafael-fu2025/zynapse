<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AuditEvents — append-only, immutable audit log.
 *
 * Hardening:
 *   - `comitted_hash` is SHA-256(prev_hash + payload_json). A SELECT-by-id
 *     validator (Phase 2) refuses to serve tampered rows.
 *   - No UPDATE / DELETE triggers are installed; revocations are written
 *     as a NEW row with `action_code = 'audit.row_revoked'` and a
 *     reference back to the original event id.
 */
final class AuditEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'prev_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'action_code'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'entity_type'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'entity_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'actor_user_id'=> ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'payload_json' => ['type' => 'JSON', 'null' => false],
            'request_id'   => ['type' => 'CHAR', 'constraint' => 32, 'null' => true],
            'commited_at'  => ['type' => 'DATETIME(6)', 'null' => false],
            'commit_hash'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['entity_type', 'entity_id']);
        $this->forge->addKey('action_code');
        $this->forge->addKey('commited_at');
        $this->forge->createTable(SYNAPSE_AUDIT_EVENTS);
    }

    public function down(): void
    {
        $this->forge->dropTable(SYNAPSE_AUDIT_EVENTS, true);
    }
}