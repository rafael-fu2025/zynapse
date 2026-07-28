<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgUnitDefaultCategory — Phase P5c.
 *
 * Adds an optional `default_category_id` to `facilities_bmg_units` that
 * pre-fills the waste category on a new batch when the operator starts
 * one on the unit. Mirrors the legacy rule "the drum carries a default
 * waste type" so the operator doesn't have to re-pick the most common
 * category on every Start Batch action.
 *
 * FK → `facilities_waste_categories.id` with `SET NULL` on category
 * delete so a retired category doesn't break unit history.
 */
final class BmgUnitDefaultCategory extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('facilities_bmg_units', [
            'default_category_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'spec_capacity_kg',
            ],
        ]);
        $this->forge->addForeignKey(
            'default_category_id',
            'facilities_waste_categories',
            'id',
            '',
            'SET NULL',
            'facilities_bmg_units',
        );
    }

    public function down(): void
    {
        $this->forge->dropForeignKey('facilities_bmg_units', 'facilities_bmg_units_default_category_id_foreign');
        $this->forge->dropColumn('facilities_bmg_units', 'default_category_id');
    }
}
