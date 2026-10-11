<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgUnitSchedules — per-unit run schedule the ESP32 pulls, plus the
 * append-only change history behind it.
 *
 * `facilities_bmg_unit_schedules` holds the LIVE configuration, one row
 * per unit (UNIQUE unit_id): up to 8 daily run times as "HH:MM" strings
 * in Asia/Manila (sorted ascending at write time), run duration in
 * seconds, motor PWM duty (0–255), an enable switch, and a monotonic
 * `version`. The device stores its copy in flash and re-applies ONLY
 * when the version it polls differs, so every staff edit must bump the
 * version — that is the whole change-distribution channel.
 *
 * `facilities_bmg_schedule_history` is the append-only audit of every
 * published configuration: one row per version with the actor. The live
 * row answers "what does the drum run now"; the history answers "who
 * changed what when" without replaying the audit outbox.
 *
 * `enabled = 0` is the safe default — a seeded unit does not spin its
 * motor until an operator publishes a schedule and switches it on.
 * Idempotent: re-runs are no-ops.
 */
final class BmgUnitSchedules extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('facilities_bmg_unit_schedules')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'unit_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'times'            => ['type' => 'JSON', 'null' => false],
                'duration_seconds' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 600],
                'pwm'              => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => false, 'default' => 200],
                'enabled'          => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
                'version'          => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'created_at'       => ['type' => 'DATETIME', 'null' => false],
                'updated_at'       => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey('unit_id');
            $this->forge->addKey('tenant_id', false, false, 'idx_bmg_schedules_tenant');
            $this->forge->addForeignKey('unit_id', 'facilities_bmg_units', 'id', '', 'RESTRICT');
            $this->forge->createTable('facilities_bmg_unit_schedules');
        }

        if (! $this->db->tableExists('facilities_bmg_schedule_history')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'unit_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'version'          => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'times'            => ['type' => 'JSON', 'null' => false],
                'duration_seconds' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
                'pwm'              => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => false],
                'enabled'          => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false],
                'changed_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'created_at'       => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['unit_id', 'version'], false, false, 'idx_bmg_sched_hist_unit');
            $this->forge->addKey('tenant_id', false, false, 'idx_bmg_sched_hist_tenant');
            $this->forge->addForeignKey('unit_id', 'facilities_bmg_units', 'id', '', 'RESTRICT');
            $this->forge->addForeignKey('changed_by_user_id', 'users', 'id', '', 'RESTRICT');
            $this->forge->createTable('facilities_bmg_schedule_history');
        }

        foreach ([
            ['facilities_bmg_unit_schedules', 'chk_bmg_sched_duration', '`duration_seconds` BETWEEN 1 AND 3600'],
            ['facilities_bmg_unit_schedules', 'chk_bmg_sched_pwm', '`pwm` BETWEEN 0 AND 255'],
            ['facilities_bmg_unit_schedules', 'chk_bmg_sched_version', '`version` >= 1'],
            ['facilities_bmg_schedule_history', 'chk_bmg_sched_hist_duration', '`duration_seconds` BETWEEN 1 AND 3600'],
            ['facilities_bmg_schedule_history', 'chk_bmg_sched_hist_pwm', '`pwm` BETWEEN 0 AND 255'],
        ] as [$table, $name, $check]) {
            if ($this->constraintDoesNotExist($table, $name)) {
                $this->db->query(
                    'ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $name . '` CHECK (' . $check . ')'
                );
            }
        }
    }

    public function down(): void
    {
        foreach (['facilities_bmg_schedule_history', 'facilities_bmg_unit_schedules'] as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            $this->forge->dropTable($table, true);
        }
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
