<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * MedicineInventory — recycled from legacy `synapse_ag` (Phase 12).
 *
 * Tables (adapted from old medicines/medicine_batches/inventory_transactions):
 *   - clinic_medicines             (catalog: generic/brand, dosage, threshold)
 *   - clinic_medicine_batches      (lots: expiry, received/remaining, status)
 *   - clinic_medicine_transactions (append-only typed ledger)
 *
 * Invariants carried over from the legacy schema (MySQL 8.4 CHECKs +
 * service-layer enforcement under SELECT ... FOR UPDATE):
 *   - quantity_remaining <= quantity_received, both >= 0
 *   - expiration_date > received_date
 *   - transaction quantity > 0 (type carries direction)
 *
 * Dispensing is FEFO (first-expiry-first-out) at the service layer.
 * The generic `clinic_inventory_items` supplies ledger is unchanged —
 * medicines are the batch-tracked catalog beside it.
 */
final class MedicineInventory extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------- medicines
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'generic_name'      => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'brand_name'        => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'category'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'dosage_form'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'dosage_strength'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'unit'              => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false, 'default' => 'pc'],
            'reorder_threshold' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 10],
            'description'       => ['type' => 'TEXT', 'null' => true],
            'archived_at'       => ['type' => 'DATETIME', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('generic_name');
        $this->forge->addKey('category');
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->createTable('clinic_medicines');

        // ---------------------------------------------------- batches
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'medicine_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'batch_number'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'quantity_received'  => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'quantity_remaining' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'expiration_date'    => ['type' => 'DATE', 'null' => false],
            'received_date'      => ['type' => 'DATE', 'null' => false],
            'supplier'           => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'status'             => ['type' => 'ENUM', 'constraint' => ['active', 'depleted', 'expired', 'recalled'], 'null' => false, 'default' => 'active'],
            'created_at'         => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['medicine_id', 'batch_number']);
        $this->forge->addKey(['medicine_id', 'status']);
        $this->forge->addKey('expiration_date');
        $this->forge->addForeignKey('medicine_id', 'clinic_medicines', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_medicine_batches');

        // ----------------------------------------------- transactions
        $this->forge->addField([
            'id'                   => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'medicine_id'          => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'batch_id'             => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'type'                 => ['type' => 'ENUM', 'constraint' => ['received', 'dispensed', 'expired', 'adjusted', 'returned'], 'null' => false],
            'quantity'             => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'reference_type'       => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'reference_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'performed_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'note'                 => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['medicine_id', 'created_at']);
        $this->forge->addKey(['batch_id', 'created_at']);
        $this->forge->addForeignKey('medicine_id', 'clinic_medicines', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('batch_id', 'clinic_medicine_batches', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('performed_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_medicine_transactions');

        // Legacy CHECK constraints (MySQL 8.4 enforces these).
        $this->db->query('ALTER TABLE `clinic_medicine_batches` ADD CONSTRAINT `chk_cmb_qty_logic` CHECK (`quantity_remaining` <= `quantity_received`)');
        $this->db->query('ALTER TABLE `clinic_medicine_batches` ADD CONSTRAINT `chk_cmb_date_order` CHECK (`expiration_date` > `received_date`)');
        $this->db->query('ALTER TABLE `clinic_medicine_transactions` ADD CONSTRAINT `chk_cmt_qty_positive` CHECK (`quantity` > 0)');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_medicine_transactions', true);
        $this->forge->dropTable('clinic_medicine_batches', true);
        $this->forge->dropTable('clinic_medicines', true);
    }
}
