<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgBatches — Facilities module.
 *
 * Invariants enforced at the DATABASE level:
 *
 *   1. Concurrency: a unit can have at most ONE unfinished batch
 *      (status ∈ {Processing, AwaitingOutput}). The generated column
 *      `active_unit_id` is populated ONLY for those statuses and carries
 *      a UNIQUE index. Attempting to start a second batch on the same
 *      unit fails with ER_DUP_ENTRY (1062).
 *
 *   2. Mass: output_weight_kg <= total_input_weight_kg. Enforced both
 *      via a BEFORE INSERT/UPDATE trigger AND by the application service.
 *      The trigger guarantees the invariant even if a service bypasses
 *      business logic.
 *
 * Soft delete via `archived_at`. Never DELETE.
 */
final class BmgBatches extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'unit_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'reference_code'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'status'             => [
                'type'       => 'ENUM',
                'constraint' => [
                    BMG_STATE_PROCESSING,
                    BMG_STATE_AWAITING_OUTPUT,
                    BMG_STATE_IDLE,
                    BMG_STATE_CANCELLED,
                ],
                'null'       => false,
                'default'    => BMG_STATE_PROCESSING,
            ],
            'total_input_weight_kg' => ['type' => 'DECIMAL', 'constraint' => '12,4', 'null' => false, 'default' => 0.0000],
            'output_weight_kg'      => ['type' => 'DECIMAL', 'constraint' => '12,4', 'null' => true],
            'input_items'           => ['type' => 'JSON', 'null' => false],
            'output_items'          => ['type' => 'JSON', 'null' => true],
            'notes'                 => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
            'started_by_user_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'finished_by_user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'started_at'            => ['type' => 'DATETIME', 'null' => false],
            'awaiting_output_at'    => ['type' => 'DATETIME', 'null' => true],
            'finished_at'           => ['type' => 'DATETIME', 'null' => true],
            'cancelled_at'          => ['type' => 'DATETIME', 'null' => true],
            'archived_at'           => ['type' => 'DATETIME', 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => false],
            'updated_at'            => ['type' => 'DATETIME', 'null' => false],

            // Generated column — populated ONLY when status is Processing
            // or AwaitingOutput. NULL otherwise. UNIQUE index on it makes
            // the "one active batch per unit" invariant a DB-level fact.
            'active_unit_id'   => [
                'type'       => 'BIGINT',
                'unsigned'   => true,
                'null'       => true,
                'generated'  => 'ALWAYS AS',
                'expression' => 'CASE WHEN status IN ("Processing", "AwaitingOutput") THEN unit_id ELSE NULL END',
                'stored'     => true,
            ],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('reference_code');
        // The invariant guard. NULLs don't collide in MySQL UNIQUE indexes,
        // so finished/cancelled rows are intentionally excluded.
        $this->forge->addUniqueKey('active_unit_id');
        $this->forge->addKey(['unit_id', 'status']);
        $this->forge->addKey('started_at');
        $this->forge->addForeignKey('unit_id', 'facilities_bmg_units', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('started_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('finished_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('facilities_bmg_batches');

        // Mass invariant trigger.
        $this->db->query(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_bmg_batches_mass_invariant_ins;
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TRIGGER trg_bmg_batches_mass_invariant_ins
            BEFORE INSERT ON facilities_bmg_batches
            FOR EACH ROW
            BEGIN
                IF NEW.output_weight_kg IS NOT NULL
                   AND NEW.output_weight_kg > NEW.total_input_weight_kg THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'BMG mass invariant violated: output_weight_kg > total_input_weight_kg';
                END IF;
            END
        SQL);

        $this->db->query(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_bmg_batches_mass_invariant_upd;
        SQL);
        $this->db->query(<<<'SQL'
            CREATE TRIGGER trg_bmg_batches_mass_invariant_upd
            BEFORE UPDATE ON facilities_bmg_batches
            FOR EACH ROW
            BEGIN
                IF NEW.output_weight_kg IS NOT NULL
                   AND NEW.output_weight_kg > NEW.total_input_weight_kg THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'BMG mass invariant violated: output_weight_kg > total_input_weight_kg';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TRIGGER IF EXISTS trg_bmg_batches_mass_invariant_ins');
        $this->db->query('DROP TRIGGER IF EXISTS trg_bmg_batches_mass_invariant_upd');
        $this->forge->dropTable('facilities_bmg_batches', true);
    }
}