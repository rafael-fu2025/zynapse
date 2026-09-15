<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicEquipment — durable-asset tracking, the third inventory catalog
 * beside medicines (batch-tracked) and supplies (quantity-tracked).
 *
 * Tables:
 *   - clinic_equipment            (catalog: name, category, location)
 *   - clinic_equipment_units      (per-unit rows with a status)
 *   - clinic_equipment_status_log (append-only from→to trail per unit)
 *
 * Invariants:
 *   - A unit's status is one of working / for_repair / for_replacement /
 *     retired; ALL transitions are permitted — the append-only log is the
 *     control (every change records who, when, from, to, and why).
 *   - `status_changed_at` on the unit mirrors the latest log row's time so
 *     reports can show how long an item has been flagged for replacement.
 *   - The initial `working` state from addUnits is also logged
 *     (from_status NULL → working), so unit history is complete.
 */
final class ClinicEquipment extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'category'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'location'    => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'notes'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'archived_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => false],
            'updated_at'  => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->addKey('tenant_id', false, false, 'idx_clinic_equipment_tenant');
        $this->forge->createTable('clinic_equipment');

        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'        => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'equipment_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'status'           => ['type' => 'ENUM', 'constraint' => ['working', 'for_repair', 'for_replacement', 'retired'], 'null' => false, 'default' => 'working'],
            'condition_note'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'acquired_date'    => ['type' => 'DATE', 'null' => true],
            'status_changed_at' => ['type' => 'DATETIME', 'null' => false],
            'created_at'       => ['type' => 'DATETIME', 'null' => false],
            'updated_at'       => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['equipment_id', 'status']);
        $this->forge->addKey('tenant_id', false, false, 'idx_clinic_equipment_units_tenant');
        $this->forge->addForeignKey('equipment_id', 'clinic_equipment', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_equipment_units');

        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'unit_id'             => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'from_status'         => ['type' => 'ENUM', 'constraint' => ['working', 'for_repair', 'for_replacement', 'retired'], 'null' => true],
            'to_status'           => ['type' => 'ENUM', 'constraint' => ['working', 'for_repair', 'for_replacement', 'retired'], 'null' => false],
            'note'                => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'changed_by_user_id'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'          => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['unit_id', 'id']);
        $this->forge->addKey('tenant_id', false, false, 'idx_clinic_equipment_status_log_tenant');
        $this->forge->addForeignKey('unit_id', 'clinic_equipment_units', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('changed_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_equipment_status_log');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_equipment_status_log', true);
        $this->forge->dropTable('clinic_equipment_units', true);
        $this->forge->dropTable('clinic_equipment', true);
    }
}
