<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * PatientRegistry — recycled from legacy `synapse_ag` (Phase 11).
 *
 * Tables (adapted from old students/employees/allergies/emergency_contacts):
 *   - patients_students   (registry keyed by school id; QR/RFID handles)
 *   - patients_employees  (HR-integrated patients)
 *   - patient_allergies   (safety-critical clinical data, students)
 *   - patient_contacts    (emergency contacts, students)
 *
 * Adaptations from the legacy schema:
 *   - No `user_id` link: patients are NOT login users in SYNAPSE ZCode
 *     (staff-only SPA), so names live on the patient rows.
 *   - `student_number` matches the free-text `patient_school_id` already
 *     used by clinic/counselling/referrals (logical join key; no cross-
 *     module FK per the domain-separation directive).
 *   - Soft archive via `archived_at` (accounts/records are never deleted).
 *   - Legacy CHECKs (year_level 1..6, consecutive_no_shows >= 0) are
 *     enforced at the service layer; MySQL 8.4 CHECKs added via raw SQL
 *     where forge lacks support.
 */
final class PatientRegistry extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------- students
        $this->forge->addField([
            'id'                   => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'student_number'       => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
            'first_name'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'last_name'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'middle_name'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'qr_code'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'rfid_tag'             => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'course'               => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'year_level'           => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'section'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'date_of_birth'        => ['type' => 'DATE', 'null' => true],
            'gender'               => ['type' => 'ENUM', 'constraint' => ['male', 'female', 'other'], 'null' => true],
            'address'              => ['type' => 'TEXT', 'null' => true],
            'blood_type'           => ['type' => 'VARCHAR', 'constraint' => 5, 'null' => true],
            'consecutive_no_shows' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'archived_at'          => ['type' => 'DATETIME', 'null' => true],
            'created_at'           => ['type' => 'DATETIME', 'null' => false],
            'updated_at'           => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('student_number');
        $this->forge->addUniqueKey('qr_code');
        $this->forge->addUniqueKey('rfid_tag');
        $this->forge->addKey(['last_name', 'first_name']);
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->createTable('patients_students');

        // ------------------------------------------------- employees
        $this->forge->addField([
            'id'                      => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'employee_number'         => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
            'first_name'              => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'last_name'               => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'middle_name'             => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'qr_code'                 => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'rfid_tag'                => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'department'              => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'position'                => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'date_hired'              => ['type' => 'DATE', 'null' => true],
            'employment_status'       => ['type' => 'ENUM', 'constraint' => ['active', 'inactive', 'on_leave'], 'null' => false, 'default' => 'active'],
            'hr_synced_at'            => ['type' => 'DATETIME', 'null' => true],
            'emergency_contact_name'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
            'emergency_contact_phone' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'date_of_birth'           => ['type' => 'DATE', 'null' => true],
            'gender'                  => ['type' => 'ENUM', 'constraint' => ['male', 'female', 'other'], 'null' => true],
            'address'                 => ['type' => 'TEXT', 'null' => true],
            'archived_at'             => ['type' => 'DATETIME', 'null' => true],
            'created_at'              => ['type' => 'DATETIME', 'null' => false],
            'updated_at'              => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('employee_number');
        $this->forge->addUniqueKey('qr_code');
        $this->forge->addUniqueKey('rfid_tag');
        $this->forge->addKey('employment_status');
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->createTable('patients_employees');

        // -------------------------------------------------- allergies
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'student_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'allergen'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => false],
            'severity'   => ['type' => 'ENUM', 'constraint' => ['mild', 'moderate', 'severe'], 'null' => false, 'default' => 'mild'],
            'reaction'   => ['type' => 'TEXT', 'null' => true],
            'noted_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at' => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('student_id');
        $this->forge->addForeignKey('student_id', 'patients_students', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('noted_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('patient_allergies');

        // ------------------------------------------- emergency contacts
        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'student_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'contact_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
            'relationship' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
            'phone'        => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false],
            'is_primary'   => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'created_at'   => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('student_id');
        $this->forge->addForeignKey('student_id', 'patients_students', 'id', '', 'CASCADE');
        $this->forge->createTable('patient_contacts');

        // Legacy CHECK constraints (MySQL 8.4 enforces these).
        $this->db->query('ALTER TABLE `patients_students` ADD CONSTRAINT `chk_ps_year` CHECK (`year_level` BETWEEN 1 AND 6 OR `year_level` IS NULL)');
    }

    public function down(): void
    {
        $this->forge->dropTable('patient_contacts', true);
        $this->forge->dropTable('patient_allergies', true);
        $this->forge->dropTable('patients_employees', true);
        $this->forge->dropTable('patients_students', true);
    }
}
