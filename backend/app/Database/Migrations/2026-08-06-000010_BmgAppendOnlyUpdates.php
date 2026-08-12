<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgAppendOnlyUpdates — batch updates as immutable, timestamped entries.
 *
 * User-facing simplification (plain 6-action flow) preserved data integrity
 * by moving the batch "update" surface onto an APPEND-ONLY ledger:
 *
 *   - Every output / curing / log entry is a SEPARATE immutable row. No
 *     in-place edit of the batch row (previously `output_weight_kg` was
 *     overwritten on each record-output).
 *   - The batch row keeps ONLY denormalized aggregates for fast reads
 *     (`output_weight_kg` = cumulative output; status = current phase),
 *     but the ledger is the source of truth for history.
 *   - Output ≤ cumulative input is enforced at write time against the
 *     ledger sum AND by the existing `bmg_mass_invariant` guard.
 *
 * Also adds `expected_completion_date` to the batch so Start can show a
 * progress indicator (reference_duration_days from the waste category).
 */
final class BmgAppendOnlyUpdates extends Migration
{
    public function up(): void
    {
        $db = $this->db;

        if (! $db->tableExists('facilities_bmg_batch_updates')) {
            $this->forge->addField([
                'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'tenant_id'           => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
                'batch_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'update_type'         => [
                    'type'       => 'ENUM',
                    'constraint' => ['output', 'curing', 'log'],
                    'null'       => false,
                ],
                // output entry
                'output_weight_kg'    => ['type' => 'DECIMAL', 'constraint' => '12,4', 'null' => true],
                // curing entry (records the transition)
                'curing_note'         => ['type' => 'VARCHAR', 'constraint' => 512, 'null' => true],
                // log entry
                'event_type'          => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
                'observation_note'    => ['type' => 'TEXT', 'null' => true],
                'temperature_celsius' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
                'moisture_level'      => ['type' => 'ENUM', 'constraint' => ['low', 'normal', 'high'], 'null' => true],
                'recorded_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
                'created_at'          => ['type' => 'DATETIME', 'null' => false],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addKey(['batch_id', 'created_at']);
            $this->forge->addKey(['batch_id', 'update_type']);
            $this->forge->addForeignKey('batch_id', 'facilities_bmg_batches', 'id', '', 'CASCADE');
            $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', '', 'RESTRICT');
            $this->forge->createTable('facilities_bmg_batch_updates');
        }

        if (! $db->fieldExists('expected_completion_date', 'facilities_bmg_batches')) {
            $db->query('ALTER TABLE `facilities_bmg_batches` ADD COLUMN `expected_completion_date` DATETIME NULL DEFAULT NULL AFTER `awaiting_output_at`');
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('facilities_bmg_batch_updates', true);
        $db = $this->db;
        if ($db->fieldExists('expected_completion_date', 'facilities_bmg_batches')) {
            $db->query('ALTER TABLE `facilities_bmg_batches` DROP COLUMN `expected_completion_date`');
        }
    }
}
