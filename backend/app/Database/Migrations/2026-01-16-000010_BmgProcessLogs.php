<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgProcessLogs — process observations during decomposition, recycled
 * from legacy `synapse_ag` bmg_process_logs (Phase 16).
 *
 * Facilities staff log temperature, moisture level, and free-text
 * observations throughout the composting cycle. Used for debugging slow
 * or unusual batches. FK stays INSIDE the Facilities module
 * (facilities_bmg_batches) — domain separation is preserved.
 */
final class BmgProcessLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'batch_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'log_date'            => ['type' => 'DATE', 'null' => false],
            'observation_note'    => ['type' => 'TEXT', 'null' => true],
            'temperature_celsius' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'moisture_level'      => ['type' => 'ENUM', 'constraint' => ['low', 'normal', 'high'], 'null' => true],
            'recorded_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'          => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['batch_id', 'log_date']);
        $this->forge->addForeignKey('batch_id', 'facilities_bmg_batches', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('facilities_bmg_process_logs');

        // Legacy sanity CHECK (MySQL 8.4 enforces): plausible compost range.
        $this->db->query('ALTER TABLE `facilities_bmg_process_logs` ADD CONSTRAINT `chk_bpl_temp` CHECK (`temperature_celsius` IS NULL OR (`temperature_celsius` BETWEEN -20 AND 120))');
    }

    public function down(): void
    {
        $this->forge->dropTable('facilities_bmg_process_logs', true);
    }
}
