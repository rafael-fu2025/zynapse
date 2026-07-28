<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicTreatments — consultation depth recycled from legacy synapse_ag
 * (Phase P1): triage priority + diagnosis on encounters, and a clinical
 * treatment log that can consume medicine stock (FEFO) as a side effect
 * of care.
 *
 * Domain separation preserved: `batch_id` is a module-local FK into
 * clinic_medicine_batches; no cross-module references.
 */
final class ClinicTreatments extends Migration
{
    public function up(): void
    {
        // ---- encounters: triage + diagnosis -----------------------------
        $this->forge->addColumn('clinic_encounters', [
            'triage_priority' => ['type' => 'ENUM', 'constraint' => ['low', 'medium', 'high', 'urgent'], 'null' => true, 'after' => 'chief_complaint'],
            'triage_override' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0, 'after' => 'triage_priority'],
            'diagnosis'       => ['type' => 'TEXT', 'null' => true, 'after' => 'triage_override'],
        ]);

        // ---- treatments --------------------------------------------------
        $this->forge->addField([
            'id'                      => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'encounter_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'treatment_type'          => ['type' => 'ENUM', 'constraint' => ['medication', 'first_aid', 'procedure', 'referral', 'other'], 'null' => false],
            'description'             => ['type' => 'TEXT', 'null' => false],
            'batch_id'                => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'medicine_id'             => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'quantity_used'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'administered_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'administered_at'         => ['type' => 'DATETIME', 'null' => false],
            'created_at'              => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['encounter_id', 'created_at']);
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('batch_id', 'clinic_medicine_batches', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('medicine_id', 'clinic_medicines', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('administered_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_treatments');

        // Legacy invariant: medication treatments must record a positive qty.
        $this->db->query(
            'ALTER TABLE `clinic_treatments` ADD CONSTRAINT `chk_ct_med_qty` '
            . "CHECK (`treatment_type` <> 'medication' OR (`quantity_used` IS NOT NULL AND `quantity_used` > 0))",
        );
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_treatments', true);
        $this->forge->dropColumn('clinic_encounters', ['triage_priority', 'triage_override', 'diagnosis']);
    }
}
