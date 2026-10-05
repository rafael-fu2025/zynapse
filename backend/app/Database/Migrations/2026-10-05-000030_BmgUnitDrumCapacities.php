<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgUnitDrumCapacities — a BMG unit houses TWO drums; the unit's rated
 * capacity (`spec_capacity_kg`) is the SUM of the two drums' individual
 * capacities, not a free-form number.
 *
 * The per-drum values are persisted so the create/edit forms can
 * collect and re-edit them while the sum stays the single source the
 * capacity checks (batch input ceiling, utilization) read. Nullable:
 * legacy rows keep their hand-set `spec_capacity_kg` until the drums
 * are re-profiled through the edit form.
 *
 * The service recomputes `spec_capacity_kg = one + two` whenever the
 * per-drum values are written; it never trusts a client-supplied total
 * alongside them. Idempotent: re-runs are no-ops.
 */
final class BmgUnitDrumCapacities extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('facilities_bmg_units')
            || $this->db->fieldExists('drum_one_capacity_kg', 'facilities_bmg_units')) {
            return;
        }

        $this->forge->addColumn('facilities_bmg_units', [
            'drum_one_capacity_kg' => ['type' => 'DECIMAL', 'constraint' => '12,4', 'null' => true, 'after' => 'spec_capacity_kg'],
            'drum_two_capacity_kg' => ['type' => 'DECIMAL', 'constraint' => '12,4', 'null' => true, 'after' => 'drum_one_capacity_kg'],
        ]);
    }

    public function down(): void
    {
        if (! $this->db->tableExists('facilities_bmg_units')
            || ! $this->db->fieldExists('drum_one_capacity_kg', 'facilities_bmg_units')) {
            return;
        }
        $this->forge->dropColumn('facilities_bmg_units', ['drum_one_capacity_kg', 'drum_two_capacity_kg']);
    }
}
