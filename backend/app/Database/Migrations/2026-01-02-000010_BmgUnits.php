<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgUnits — Facilities module.
 *
 * Each BMG (Bio-Medical Generator) Unit is a physical machine. It has a
 * lifecycle state (see SYNAPSE constants). The `active_unit_id` generated
 * column on `facilities_bmg_batches` is what enforces the "one unfinished
 * batch per unit" invariant (UNIQUE index on it).
 *
 * InnoDB / utf8mb4 / strict mode.
 */
final class BmgUnits extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'code'           => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'display_name'   => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
            'location_code'  => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'status'         => [
                'type'       => 'ENUM',
                'constraint' => [
                    BMG_STATE_IDLE,
                    BMG_STATE_PROCESSING,
                    BMG_STATE_AWAITING_OUTPUT,
                    BMG_STATE_CANCELLED,
                ],
                'null'       => false,
                'default'    => BMG_STATE_IDLE,
            ],
            'spec_capacity_kg'=> ['type' => 'DECIMAL', 'constraint' => '12,4', 'null' => true],
            'notes'          => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'archived_at'    => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => false],
            'updated_at'     => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('facilities_bmg_units');
    }

    public function down(): void
    {
        $this->forge->dropTable('facilities_bmg_units', true);
    }
}