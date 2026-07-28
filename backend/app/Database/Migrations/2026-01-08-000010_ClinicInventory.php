<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicInventory — clinic supply stock with movement ledger (Phase 8).
 *
 * Tables:
 *   - clinic_inventory_items     (catalog + on-hand quantity)
 *   - clinic_inventory_movements (append-only ledger; qty_delta signed)
 *
 * Invariants:
 *   - `quantity_on_hand >= 0` enforced at the service layer under
 *     `SELECT ... FOR UPDATE` (directive §8.2) AND by a CHECK constraint.
 *   - Movements are never updated or deleted; corrections are new rows
 *     with `reason_code = 'adjustment'`.
 */
final class ClinicInventory extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'sku'              => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'name'             => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'unit'             => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false, 'default' => 'pc'],
            'quantity_on_hand' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'reorder_level'    => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'archived_at'      => ['type' => 'DATETIME', 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => false],
            'updated_at'       => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('sku');
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->createTable('clinic_inventory_items');

        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'item_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'qty_delta'          => ['type' => 'INT', 'null' => false],
            'reason_code'        => ['type' => 'ENUM', 'constraint' => ['receive', 'dispense', 'adjustment'], 'null' => false],
            'moved_by_user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'note'               => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['item_id', 'created_at']);
        $this->forge->addForeignKey('item_id', 'clinic_inventory_items', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('moved_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_inventory_movements');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_inventory_movements', true);
        $this->forge->dropTable('clinic_inventory_items', true);
    }
}
