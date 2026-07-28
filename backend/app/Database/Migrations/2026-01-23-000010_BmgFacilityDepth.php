<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgFacilityDepth — recycled from legacy synapse_ag (Phase P4):
 *   - facilities_waste_categories (expected yield %, reference duration)
 *   - facilities_bmg_inputs / _outputs (structured incremental records,
 *     the latter with a quality grade)
 *   - batches gain an optional waste-category tag
 *   - units gain a `Maintenance` status value
 *
 * `spec_capacity_kg` already exists on units, so capacity is unchanged.
 * All FKs stay inside the Facilities module.
 */
final class BmgFacilityDepth extends Migration
{
    public function up(): void
    {
        // ---- waste categories -------------------------------------------
        $this->forge->addField([
            'id'                      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'code'                    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
            'name'                    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'description'             => ['type' => 'TEXT', 'null' => true],
            'expected_yield_pct'      => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'reference_duration_days' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'is_active'               => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'created_at'              => ['type' => 'DATETIME', 'null' => false],
            'updated_at'              => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('code');
        $this->forge->createTable('facilities_waste_categories');

        // ---- structured inputs ------------------------------------------
        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'batch_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'weight_kg'           => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => false],
            'note'                => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'recorded_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'recorded_at'         => ['type' => 'DATETIME', 'null' => false],
            'created_at'          => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['batch_id', 'created_at']);
        $this->forge->addForeignKey('batch_id', 'facilities_bmg_batches', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('facilities_bmg_inputs');

        // ---- structured outputs (with quality grade) --------------------
        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'batch_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'output_weight_kg'    => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => false],
            'harvest_date'        => ['type' => 'DATE', 'null' => true],
            'quality_grade'       => ['type' => 'ENUM', 'constraint' => ['excellent', 'good', 'fair'], 'null' => true],
            'note'                => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'recorded_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'          => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['batch_id', 'created_at']);
        $this->forge->addForeignKey('batch_id', 'facilities_bmg_batches', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('facilities_bmg_outputs');

        // ---- batch waste-category tag (optional) ------------------------
        $this->forge->addColumn('facilities_bmg_batches', [
            'category_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'unit_id'],
        ]);
        $this->forge->addForeignKey('category_id', 'facilities_waste_categories', 'id', '', 'SET NULL', 'facilities_bmg_batches');

        // ---- unit Maintenance status ------------------------------------
        $this->db->query(
            "ALTER TABLE `facilities_bmg_units` MODIFY `status` "
            . "ENUM('Idle','Processing','AwaitingOutput','Cancelled','Maintenance') NOT NULL DEFAULT 'Idle'",
        );

        // Positive-weight invariants.
        $this->db->query('ALTER TABLE `facilities_bmg_inputs` ADD CONSTRAINT `chk_fbi_pos` CHECK (`weight_kg` > 0)');
        $this->db->query('ALTER TABLE `facilities_bmg_outputs` ADD CONSTRAINT `chk_fbo_pos` CHECK (`output_weight_kg` > 0)');
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE `facilities_bmg_units` MODIFY `status` ENUM('Idle','Processing','AwaitingOutput','Cancelled') NOT NULL DEFAULT 'Idle'");
        $this->forge->dropForeignKey('facilities_bmg_batches', 'facilities_bmg_batches_category_id_foreign');
        $this->forge->dropColumn('facilities_bmg_batches', 'category_id');
        $this->forge->dropTable('facilities_bmg_outputs', true);
        $this->forge->dropTable('facilities_bmg_inputs', true);
        $this->forge->dropTable('facilities_waste_categories', true);
    }
}
