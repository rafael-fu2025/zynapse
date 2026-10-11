<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgDeviceRuns — one row per motor run the device reports through
 * `POST /api/v1/devices/bmg/runs`, scheduled or manual.
 *
 * Keyed by `run_uid` per tenant (UNIQUE) for at-least-once ingest: a
 * retried POST resolves to the original row, same posture as the
 * turn-session `session_uid`.
 *
 * `batch_id` is deliberately NULLABLE — the whole point over the older
 * turn-session surface is that a run is recorded even when the unit has
 * no active batch (staff see it on the dashboard and get notified),
 * instead of the ingest rejecting it and the board retrying forever.
 * The FK is SET NULL so a batch purge never strands device history.
 *
 * `origin` distinguishes schedule-driven runs from run-now commands the
 * operator triggered; `result` is the device's own outcome claim — the
 * server records what the board said, the dashboard flags failures.
 * Idempotent: re-runs are no-ops.
 */
final class BmgDeviceRuns extends Migration
{
    private const TABLE = 'facilities_bmg_device_runs';

    public function up(): void
    {
        if ($this->db->tableExists(self::TABLE)) {
            return;
        }

        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'device_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'unit_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'batch_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'run_uid'          => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'origin'           => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'started_at'       => ['type' => 'DATETIME', 'null' => true],
            'ended_at'         => ['type' => 'DATETIME', 'null' => true],
            'duration_seconds' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'result'           => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false],
            'turns_count'      => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'note'             => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['tenant_id', 'run_uid']);
        $this->forge->addKey(['tenant_id', 'unit_id', 'started_at'], false, false, 'idx_bmg_runs_unit_time');
        $this->forge->addForeignKey('device_id', 'facilities_bmg_devices', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('unit_id', 'facilities_bmg_units', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('batch_id', 'facilities_bmg_batches', 'id', '', 'SET NULL');
        $this->forge->createTable(self::TABLE);

        foreach ([
            'chk_bmg_run_origin'    => "`origin` IN ('scheduled','manual')",
            'chk_bmg_run_result'    => "`result` IN ('completed','failed','aborted')",
            'chk_bmg_run_duration'  => '`duration_seconds` BETWEEN 1 AND 86400',
        ] as $name => $check) {
            if ($this->constraintDoesNotExist(self::TABLE, $name)) {
                $this->db->query(
                    'ALTER TABLE `' . self::TABLE . '` ADD CONSTRAINT `' . $name . '` CHECK (' . $check . ')'
                );
            }
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }
        foreach (['chk_bmg_run_origin', 'chk_bmg_run_result', 'chk_bmg_run_duration'] as $name) {
            $this->db->query('ALTER TABLE `' . self::TABLE . '` DROP CONSTRAINT `' . $name . '`');
        }
        $this->forge->dropTable(self::TABLE, true);
    }

    private function constraintDoesNotExist(string $table, string $constraint): bool
    {
        $r = $this->db->query(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE()'
            . '   AND TABLE_NAME = ' . $this->db->escape($table)
            . '   AND CONSTRAINT_NAME = ' . $this->db->escape($constraint)
        );
        return $r->getNumRows() === 0;
    }
}
