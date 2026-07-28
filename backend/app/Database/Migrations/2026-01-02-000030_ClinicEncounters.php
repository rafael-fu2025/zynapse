<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicEncounters — clinic module.
 *
 * Strict isolation: NO foreign keys to counselling_* tables. The
 * `referral_referrals` bridge is the only contract for cross-module
 * communication.
 */
final class ClinicEncounters extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'patient_school_id'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'chief_complaint'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'status'                => ['type' => 'ENUM', 'constraint' => ['Open','Closed','Referred'], 'null' => false, 'default' => 'Open'],
            'attending_user_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'started_at'            => ['type' => 'DATETIME', 'null' => false],
            'closed_at'             => ['type' => 'DATETIME', 'null' => true],
            'archived_at'           => ['type' => 'DATETIME', 'null' => true],
            'created_at'            => ['type' => 'DATETIME', 'null' => false],
            'updated_at'            => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['patient_school_id', 'status']);
        $this->forge->addKey('started_at');
        $this->forge->addForeignKey('attending_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_encounters');

        // Vitals — strictly clinic-internal.
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'encounter_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'bp_systolic'     => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'bp_diastolic'    => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'pulse_bpm'       => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'temp_c'          => ['type' => 'DECIMAL', 'constraint' => '4,2', 'null' => true],
            'spo2_pct'        => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'weight_kg'       => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'height_cm'       => ['type' => 'DECIMAL', 'constraint' => '6,2', 'null' => true],
            'recorded_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'recorded_at'     => ['type' => 'DATETIME', 'null' => false],
            'created_at'      => ['type' => 'DATETIME', 'null' => false],
            'updated_at'      => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('encounter_id');
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_vitals');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_vitals', true);
        $this->forge->dropTable('clinic_encounters', true);
    }
}