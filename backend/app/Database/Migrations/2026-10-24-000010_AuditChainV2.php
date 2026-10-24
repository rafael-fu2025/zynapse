<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

use const SYNAPSE_AUDIT_EVENTS;
use const SYNAPSE_AUDIT_OUTBOX;

/**
 * AuditChainV2 — per-tenant, versioned hash chains (2026-10 security audit G-2/G-9).
 *
 * v1 (unchanged, frozen): one GLOBAL chain; commit_hash = SHA-256(prev_hash || payload_json).
 * v2 (this migration onward): one chain PER TENANT; commit_hash = SHA-256(canonical
 * fixed-order JSON that includes tenant_id, actor, request_id and occurred_at — see
 * App\Services\Audit\AuditHashV2). Existing v1 rows keep hash_version = 1 and remain
 * verifiable by the frozen v1 walk.
 *
 * Also introduces `audit_wipe_markers`: synapse:audit-clear records the final chain
 * state BEFORE truncating, so a wiped log leaves externally-checkable evidence of the
 * wipe that itself cannot be truncated away with the log.
 */
final class AuditChainV2 extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('tenant_id', SYNAPSE_AUDIT_OUTBOX)) {
            $this->forge->addColumn(SYNAPSE_AUDIT_OUTBOX, [
                'tenant_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => false,
                    'default'  => 1,
                    'after'    => 'id',
                ],
            ]);
        }
        if (! $this->db->fieldExists('hash_version', SYNAPSE_AUDIT_EVENTS)) {
            $this->forge->addColumn(SYNAPSE_AUDIT_EVENTS, [
                'hash_version' => [
                    'type'     => 'TINYINT',
                    'unsigned' => true,
                    'null'     => false,
                    'default'  => 1,
                    'after'    => 'id',
                ],
            ]);
        }
        // forge->addKey() only takes effect on the next createTable(), so
        // indexes on existing tables are created directly.
        $this->db->query('CREATE INDEX ix_audit_outbox_tenant ON ' . SYNAPSE_AUDIT_OUTBOX . ' (tenant_id, processed_at)');
        $this->db->query('CREATE INDEX ix_audit_events_tenant ON ' . SYNAPSE_AUDIT_EVENTS . ' (tenant_id, id)');

        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'wiped_at'         => ['type' => 'DATETIME(6)', 'null' => false],
            'last_event_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'last_commit_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'event_count'      => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'outbox_count'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'reason'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('audit_wipe_markers');
    }

    public function down(): void
    {
        $this->forge->dropTable('audit_wipe_markers', true);
        $this->db->query('DROP INDEX ix_audit_events_tenant ON ' . SYNAPSE_AUDIT_EVENTS);
        $this->db->query('DROP INDEX ix_audit_outbox_tenant ON ' . SYNAPSE_AUDIT_OUTBOX);
        $this->forge->dropColumn(SYNAPSE_AUDIT_EVENTS, 'hash_version');
        $this->forge->dropColumn(SYNAPSE_AUDIT_OUTBOX, 'tenant_id');
    }
}
