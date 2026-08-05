<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * InventoryMovementEncounterRef — anchor supply dispenses to an open
 * clinic encounter (inventory audit fix).
 *
 * Previously only medicines were encounter-anchored (`clinic_medicine_
 * transactions.reference_type/reference_id`); a supply `dispense`
 * movement was a bare negative ledger row with no patient/visit link.
 * These columns let the supply ledger record which visit the stock
 * went to, matching the medicine ledger.
 */
final class InventoryMovementEncounterRef extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('clinic_inventory_movements', [
            'reference_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
                'after'      => 'reason_code',
            ],
            'reference_id' => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'reference_type',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('clinic_inventory_movements', ['reference_type', 'reference_id']);
    }
}
