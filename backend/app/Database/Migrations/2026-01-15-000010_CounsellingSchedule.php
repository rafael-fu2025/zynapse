<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CounsellingSchedule — availability + appointments recycled from
 * legacy `synapse_ag` counsellor_availability / counselling_appointments
 * (Phase 15).
 *
 * Domain separation preserved: appointments reference patients by the
 * free-text `patient_school_id` (same convention as counselling
 * sessions) — NO foreign keys into clinic tables. The patient registry
 * is treated as shared reference data for the three-strike no-show
 * counter only (separate UPDATE, never a JOIN).
 */
final class CounsellingSchedule extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------- availability
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'counsellor_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'day_of_week'        => ['type' => 'TINYINT', 'unsigned' => true, 'null' => false, 'comment' => '0=Sun ... 6=Sat'],
            'start_time'         => ['type' => 'TIME', 'null' => false],
            'end_time'           => ['type' => 'TIME', 'null' => false],
            'max_slots'          => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1],
            'is_active'          => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'created_at'         => ['type' => 'DATETIME', 'null' => false],
            'updated_at'         => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['counsellor_user_id', 'day_of_week']);
        $this->forge->addForeignKey('counsellor_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('counselling_availability');

        // ---------------------------------------------- appointments
        $this->forge->addField([
            'id'                  => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'patient_school_id'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'counsellor_user_id'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'appointment_date'    => ['type' => 'DATE', 'null' => false],
            'start_time'          => ['type' => 'TIME', 'null' => false],
            'end_time'            => ['type' => 'TIME', 'null' => false],
            'type'                => ['type' => 'ENUM', 'constraint' => ['initial', 'follow_up', 'crisis', 'referral_based'], 'null' => false, 'default' => 'initial'],
            'status'              => ['type' => 'ENUM', 'constraint' => ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'], 'null' => false, 'default' => 'scheduled'],
            'reason'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'cancellation_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_by_user_id'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'          => ['type' => 'DATETIME', 'null' => false],
            'updated_at'          => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['counsellor_user_id', 'appointment_date']);
        $this->forge->addKey(['patient_school_id', 'appointment_date']);
        $this->forge->addKey('status');
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->addForeignKey('counsellor_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('created_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('counselling_appointments');

        // Legacy CHECKs (MySQL 8.4 enforces).
        $this->db->query('ALTER TABLE `counselling_availability` ADD CONSTRAINT `chk_ca_dow` CHECK (`day_of_week` BETWEEN 0 AND 6)');
        $this->db->query('ALTER TABLE `counselling_availability` ADD CONSTRAINT `chk_ca_time_order` CHECK (`start_time` < `end_time`)');
        $this->db->query('ALTER TABLE `counselling_availability` ADD CONSTRAINT `chk_ca_max_slots` CHECK (`max_slots` >= 1)');
        $this->db->query('ALTER TABLE `counselling_appointments` ADD CONSTRAINT `chk_cap_time_order` CHECK (`start_time` < `end_time`)');
    }

    public function down(): void
    {
        $this->forge->dropTable('counselling_appointments', true);
        $this->forge->dropTable('counselling_availability', true);
    }
}
