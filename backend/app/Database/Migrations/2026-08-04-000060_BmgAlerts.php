<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * BmgAlerts — panel revision (August 2026):
 *
 * In-process exception/alerts log for the BMG state machine. The alert
 * engine (`BmgAlertEngine`) inspects the latest process log of each
 * batch and persists any threshold-breach or staleness condition as a
 * row here. Operators acknowledge alerts on the drum detail page; the
 * `(batch_id, acknowledged_at)` index keeps the unread banner cheap.
 *
 * Codes are stable identifiers (e.g. `TEMP_PFRP_LOW`, `STALLED`) so the
 * frontend can map them to localised messages without parsing prose.
 *
 * `severity` is VARCHAR(16) + CHECK rather than a native ENUM so future
 * severity levels (e.g. `regulatory`) can be added without a schema
 * rewrite — same pattern as `BmgProcessLogObservability`.
 *
 * Tenant isolation: every row carries `tenant_id` so the alert listing
 * queries can filter at the DB level alongside the existing policy.
 * FK to `users(id)` is RESTRICT (ack note may outlive user, but we never
 * want to silently lose the audit trail by a user hard-delete).
 */
use CodeIgniter\Database\Migration;

final class BmgAlerts extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('facilities_bmg_alerts')) {
            $this->forge->addField([
                'id'                       => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'batch_id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'code'                     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'severity'                 => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
                'message'                  => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => false],
                'triggered_at'             => ['type' => 'DATETIME', 'null' => false],
                'acknowledged_at'          => ['type' => 'DATETIME', 'null' => true],
                'acknowledged_by_user_id'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'tenant_id'                => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'created_at'               => ['type' => 'DATETIME', 'null' => false],
                'updated_at'               => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['batch_id', 'acknowledged_at']);
            $this->forge->addKey('tenant_id');
            $this->forge->addForeignKey('batch_id', 'facilities_bmg_batches', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('acknowledged_by_user_id', 'users', 'id', 'RESTRICT', 'SET NULL');
            $this->forge->createTable('facilities_bmg_alerts');
        }

        // Idempotent: CHECKs are added unconditionally (ALTER … ADD
        // CHECK fails with "Duplicate key name" if rerun, so we DROP
        // first). Mirrors the same pattern used in
        // `BmgProcessLogObservability`.
        $this->db->query('ALTER TABLE `facilities_bmg_alerts` DROP CHECK `chk_alert_severity`');
        $this->db->query(<<<'SQL'
            ALTER TABLE `facilities_bmg_alerts`
                ADD CONSTRAINT `chk_alert_severity`
                CHECK (`severity` IN ('info','warning','critical'))
        SQL);
    }

    public function down(): void
    {
        if ($this->db->tableExists('facilities_bmg_alerts')) {
            $this->db->query('ALTER TABLE `facilities_bmg_alerts` DROP CHECK `chk_alert_severity`');
            $this->forge->dropTable('facilities_bmg_alerts', true);
        }
    }
}
