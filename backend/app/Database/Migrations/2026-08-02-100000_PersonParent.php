<?php
/**
 * PersonParent — Phase 1.1 of the patient-registry consolidation.
 *
 * Introduces the `persons` table that will become the canonical source of
 * "person" data (the 13 columns currently duplicated between
 * patients_students and patients_employees).
 *
 * Key design points:
 *   - `kind` is the discriminator for the type of person (student, employee,
 *     contractor, alumni). Children tables (patients_students, patients_employees)
 *     keep their specific columns and gain a `persons_id` FK in a later phase.
 *   - `user_id` UNIQUE so a single user can be linked to at most one person
 *     across ALL types (stricter than the current per-table UNIQUE).
 *   - No FK to `users` (intentional — mirrors EmployeeUserLink/StudentUserLink
 *     so wipe-and-reseed of `users` doesn't break FK rules).
 *   - Idempotent: re-runs are no-ops.
 */
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class PersonParent extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('persons')) {
            return;
        }

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'kind'         => ['type' => 'ENUM', 'constraint' => ['student', 'employee', 'contractor', 'alumni'], 'null' => false],
            'user_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'kind'],
            'first_name'   => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'last_name'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
            'middle_name'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'qr_code'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'rfid_tag'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'date_of_birth'=> ['type' => 'DATE', 'null' => true],
            'gender'       => ['type' => 'ENUM', 'constraint' => ['male', 'female', 'other'], 'null' => true],
            'address'      => ['type' => 'TEXT', 'null' => true],
            'archived_at'  => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => false],
            'updated_at'   => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        // A single user can be linked to at most one person across all types.
        $this->forge->addUniqueKey('user_id', 'uniq_persons_user_id');
        $this->forge->addKey('kind', false, false, 'idx_persons_kind');
        $this->forge->addKey(['last_name', 'first_name'], false, false, 'idx_persons_name');
        $this->forge->addKey(['created_at', 'id'], false, false, 'idx_persons_created');
        $this->forge->createTable('persons');
    }

    public function down(): void
    {
        if ($this->db->tableExists('persons')) {
            $this->forge->dropTable('persons', true);
        }
    }
}
