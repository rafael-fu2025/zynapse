<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * UsersIdentityColumns — identity-unification (Phase A, step 1).
 *
 * Turns `users` into the SINGLE canonical person table by adding the
 * columns previously spread across `persons`, `patients_students`,
 * `patients_employees` and `patient_identifiers`.
 *
 *   - `kind` discriminator: student | employee | contractor | alumni.
 *     NULL means "operational/staff account" (not a patient).
 *   - Common person columns (names, qr/rfid handles, dob, gender, address).
 *   - Student-specific columns (nullable).
 *   - Employee-specific columns (nullable).
 *   - `tenant_id` for multi-tenant parity with the other domain tables.
 *
 * `person_id` (the old FK to `persons`) is KEPT through the transition
 * and removed in the final cleanup migration (M5) after the legacy
 * tables are dropped.
 *
 * Idempotent: re-runs are no-ops.
 */
final class UsersIdentityColumns extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('users')) {
            return;
        }

        $columns = [
            'tenant_id'          => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 1, 'after' => 'id'],
            'kind'               => ['type' => 'ENUM', 'constraint' => ['student', 'employee', 'contractor', 'alumni'], 'null' => true, 'after' => 'tenant_id'],
            'first_name'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'kind'],
            'last_name'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'first_name'],
            'middle_name'        => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'last_name'],
            'qr_code'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'middle_name'],
            'rfid_tag'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'qr_code'],
            'date_of_birth'      => ['type' => 'DATE', 'null' => true, 'after' => 'rfid_tag'],
            'gender'             => ['type' => 'ENUM', 'constraint' => ['male', 'female', 'other'], 'null' => true, 'after' => 'date_of_birth'],
            'address'            => ['type' => 'TEXT', 'null' => true, 'after' => 'gender'],
            'archived_at'        => ['type' => 'DATETIME', 'null' => true, 'after' => 'address'],

            // Student-specific
            'student_number'     => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'after' => 'archived_at'],
            'course'             => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'student_number'],
            'year_level'         => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'after' => 'course'],
            'section'            => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'year_level'],
            'blood_type'         => ['type' => 'VARCHAR', 'constraint' => 5, 'null' => true, 'after' => 'section'],
            'consecutive_no_shows' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0, 'after' => 'blood_type'],

            // Employee-specific
            'employee_number'    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'after' => 'consecutive_no_shows'],
            'department'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'employee_number'],
            'position'           => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'after' => 'department'],
            'date_hired'         => ['type' => 'DATE', 'null' => true, 'after' => 'position'],
            'employment_status'  => ['type' => 'ENUM', 'constraint' => ['active', 'inactive', 'on_leave'], 'null' => true, 'default' => 'active', 'after' => 'date_hired'],
            'hr_synced_at'       => ['type' => 'DATETIME', 'null' => true, 'after' => 'employment_status'],
            'emergency_contact_name'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'after' => 'hr_synced_at'],
            'emergency_contact_phone' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'emergency_contact_name'],
            'is_teaching'        => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true, 'after' => 'emergency_contact_phone'],
        ];

        foreach ($columns as $col => $def) {
            if (! $this->db->fieldExists($col, 'users')) {
                $this->forge->addColumn('users', [$col => $def]);
            }
        }

        // Handle uniqueness: identifiers must be unique across ALL users.
        // If existing data contains duplicates we degrade to a plain index
        // so the migration never fails on messy legacy data; the service
        // layer still enforces uniqueness on write.
        foreach ([
            'student_number' => 'uniq_users_student_number',
            'employee_number' => 'uniq_users_employee_number',
            'qr_code'         => 'uniq_users_qr_code',
            'rfid_tag'        => 'uniq_users_rfid_tag',
        ] as $column => $indexName) {
            if ($this->indexExists('users', $indexName)) {
                continue;
            }
            $dup = (int) $this->db->query(
                "SELECT `{$column}` FROM `users` WHERE `{$column}` IS NOT NULL GROUP BY `{$column}` HAVING COUNT(*) > 1 LIMIT 1"
            )->getNumRows();
            if ($dup > 0) {
                $this->db->query("ALTER TABLE `users` ADD INDEX `{$indexName}` (`{$column}`)");
            } else {
                $this->db->query("ALTER TABLE `users` ADD UNIQUE INDEX `{$indexName}` (`{$column}`)");
            }
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('users')) {
            return;
        }
        foreach ([
            'uniq_users_student_number', 'uniq_users_employee_number',
            'uniq_users_qr_code', 'uniq_users_rfid_tag',
        ] as $indexName) {
            if ($this->indexExists('users', $indexName)) {
                $this->forge->dropKey('users', $indexName);
            }
        }

        $columns = [
            'tenant_id', 'kind', 'first_name', 'last_name', 'middle_name',
            'qr_code', 'rfid_tag', 'date_of_birth', 'gender', 'address',
            'archived_at', 'student_number', 'course', 'year_level', 'section',
            'blood_type', 'consecutive_no_shows', 'employee_number', 'department',
            'position', 'date_hired', 'employment_status', 'hr_synced_at',
            'emergency_contact_name', 'emergency_contact_phone', 'is_teaching',
        ];
        foreach ($columns as $col) {
            if ($this->db->fieldExists($col, 'users')) {
                $this->forge->dropColumn('users', $col);
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->db->query("
            SELECT INDEX_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
              AND INDEX_NAME = '{$indexName}'
            LIMIT 1
        ")->getRow();
        return $row !== null;
    }
}
