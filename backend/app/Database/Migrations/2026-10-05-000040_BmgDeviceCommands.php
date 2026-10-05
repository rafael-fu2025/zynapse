<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgDeviceCommands — outbound command queue for the integrated ESP32s.
 *
 * Batch start is what physically starts the unit: when an operator
 * starts a batch on a drum, BmgService enqueues a `start` command for
 * the drum's bound board here. The device FETCHES its pending commands
 * (`GET /api/v1/devices/bmg/commands`, device-token auth) on its
 * polling cadence and ACKs each one after actuating — the same
 * pull-based posture as its ingest, so no device needs an inbound
 * listener.
 *
 * Delivery is at-least-once: fetching stamps `delivered_at` but leaves
 * the row `pending` until the device ACKs, so a board that crashes
 * mid-cycle re-receives the command and must dedupe by id.
 *
 * `status` / `command` use VARCHAR + CHECK (the LowercaseStatusEnums
 * pattern) so future commands don't require a schema rewrite.
 * Idempotent: re-runs are no-ops.
 */
final class BmgDeviceCommands extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('facilities_bmg_device_commands')) {
            return;
        }

        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'device_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'command'        => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'payload'        => ['type' => 'TEXT', 'null' => true],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => false, 'default' => 'pending'],
            'delivered_at'   => ['type' => 'DATETIME', 'null' => true],
            'acknowledged_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => false],
            'updated_at'     => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['device_id', 'status'], false, false, 'idx_bmg_device_cmds_device');
        $this->forge->addKey('tenant_id', false, false, 'idx_bmg_device_cmds_tenant');
        // A device row is retired (archived), never hard-deleted in
        // production; CASCADE keeps purge tooling orphan-free.
        $this->forge->addForeignKey('device_id', 'facilities_bmg_devices', 'id', '', 'CASCADE');
        $this->forge->createTable('facilities_bmg_device_commands');

        foreach ([
            'chk_bmg_dev_cmd_command' => "`command` IN ('start','stop')",
            'chk_bmg_dev_cmd_status'  => "`status` IN ('pending','acknowledged','failed')",
        ] as $name => $check) {
            if ($this->constraintDoesNotExist('facilities_bmg_device_commands', $name)) {
                $this->db->query(
                    'ALTER TABLE `facilities_bmg_device_commands` ADD CONSTRAINT `' . $name . '` CHECK (' . $check . ')'
                );
            }
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('facilities_bmg_device_commands')) {
            return;
        }
        foreach (['chk_bmg_dev_cmd_command', 'chk_bmg_dev_cmd_status'] as $name) {
            $this->db->query('ALTER TABLE `facilities_bmg_device_commands` DROP CONSTRAINT `' . $name . '`');
        }
        $this->forge->dropTable('facilities_bmg_device_commands', true);
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
