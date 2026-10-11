<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgDeviceAssignments — append-only history of which ESP32 was bolted
 * to which drum, when, and by whom.
 *
 * The UNIQUE `unit_id` on `facilities_bmg_devices` enforces the 1:1
 * invariant, but overwriting `unit_id` erases the previous binding.
 * This ledger keeps every transition: an active assignment is
 * `unassigned_at IS NULL` (exactly one per device, written in the same
 * transaction that moves `unit_id`); closing one is a timestamped
 * update, never a delete.
 *
 * Idempotent: re-runs are no-ops.
 */
final class BmgDeviceAssignments extends Migration
{
    private const TABLE = 'facilities_bmg_device_assignments';

    public function up(): void
    {
        if ($this->db->tableExists(self::TABLE)) {
            return;
        }

        $this->forge->addField([
            'id'                    => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'             => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'device_id'             => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'unit_id'               => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'assigned_by_user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'unassigned_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'assigned_at'           => ['type' => 'DATETIME', 'null' => false],
            'unassigned_at'         => ['type' => 'DATETIME', 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['device_id', 'assigned_at'], false, false, 'idx_bmg_assign_device');
        $this->forge->addKey(['unit_id', 'assigned_at'], false, false, 'idx_bmg_assign_unit');
        $this->forge->addKey('tenant_id', false, false, 'idx_bmg_assign_tenant');
        $this->forge->addForeignKey('device_id', 'facilities_bmg_devices', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('unit_id', 'facilities_bmg_units', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('assigned_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('unassigned_by_user_id', 'users', 'id', '', 'SET NULL');
        $this->forge->createTable(self::TABLE);
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }
        $this->forge->dropTable(self::TABLE, true);
    }
}
