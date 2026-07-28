<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicTriagePredictions — deterministic triage suggestion log
 * (Phase P2a, recycled from legacy synapse_ag ai_triage_predictions).
 *
 * `encounter_id` is a module-local FK. The prediction is advisory; a
 * staff member accepts or overrides it, and the decision is recorded
 * here while the chosen priority is written to the encounter.
 */
final class ClinicTriagePredictions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'encounter_id'       => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'patient_school_id'  => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'input_text'         => ['type' => 'TEXT', 'null' => false],
            'predicted_priority' => ['type' => 'ENUM', 'constraint' => ['low', 'medium', 'high', 'urgent'], 'null' => false],
            'confidence_score'   => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => false],
            'model_version'      => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
            'features_used'      => ['type' => 'JSON', 'null' => true],
            'staff_decision'     => ['type' => 'ENUM', 'constraint' => ['accepted', 'overridden'], 'null' => true],
            'staff_priority'     => ['type' => 'ENUM', 'constraint' => ['low', 'medium', 'high', 'urgent'], 'null' => true],
            'decided_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'decided_at'         => ['type' => 'DATETIME', 'null' => true],
            'created_at'         => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['encounter_id', 'created_at']);
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('decided_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_triage_predictions');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_triage_predictions', true);
    }
}
