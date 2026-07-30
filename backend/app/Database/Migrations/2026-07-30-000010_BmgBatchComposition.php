<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgBatchComposition — panel revision (July 2026):
 *
 * Segregated waste tracking per drum batch. Instead of the free-form
 * `input_items` JSON, every batch records a structured per-category
 * mix: one row per (batch, waste category) with the loaded weight.
 * The weight RATIO of each component drives the batch's expected
 * composting duration (weighted by the category's historical average
 * or, when no trials exist yet, its manual reference days).
 *
 * `input_items` stays on the batch for backward compatibility — the
 * composition rows are the authoritative structured source going
 * forward. No backfill: legacy batches keep their JSON only.
 */
final class BmgBatchComposition extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'batch_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'category_id' => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'weight_kg'   => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => false],
            'created_at'  => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['batch_id', 'category_id']);
        $this->forge->addKey('category_id');
        $this->forge->addForeignKey('batch_id', 'facilities_bmg_batches', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('category_id', 'facilities_waste_categories', 'id', '', 'RESTRICT');
        $this->forge->createTable('facilities_bmg_composition');

        // Positive-weight invariant (MySQL 8.4 enforces CHECKs).
        $this->db->query('ALTER TABLE `facilities_bmg_composition` ADD CONSTRAINT `chk_fbc_pos` CHECK (`weight_kg` > 0)');
    }

    public function down(): void
    {
        $this->forge->dropTable('facilities_bmg_composition', true);
    }
}
