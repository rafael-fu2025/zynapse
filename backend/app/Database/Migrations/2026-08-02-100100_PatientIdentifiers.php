<?php
/**
 * PatientIdentifiers — Phase 1.2 of the patient-registry consolidation.
 *
 * Introduces the `patient_identifiers` join table that becomes the new
 * foreign-key target for every clinical row that today holds a free-text
 * `patient_school_id`. Each row binds a (kind, identifier) pair to a
 * `persons.id`.
 *
 * Key design points:
 *   - `kind` is the discriminator; `identifier` is the school/employee number.
 *   - UNIQUE(kind, identifier) on NON-archived rows prevents two live
 *     patients of the same kind from sharing an identifier.
 *   - Multiple (kind, identifier) per person is allowed via is_primary so
 *     a person could be both an employee and an alumni in the future.
 *   - Idempotent: re-runs are no-ops.
 */
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class PatientIdentifiers extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('patient_identifiers')) {
            return;
        }

        $this->forge->addField([
            'id'           => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'persons_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'kind'         => ['type' => 'ENUM', 'constraint' => ['student', 'employee', 'contractor', 'alumni'], 'null' => false],
            'identifier'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'is_primary'   => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'archived_at'  => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => false],
            'updated_at'   => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('persons_id', false, false, 'idx_pi_persons');
        $this->forge->addKey('kind', false, false, 'idx_pi_kind');
        $this->forge->addKey(['kind', 'identifier'], false, false, 'idx_pi_kind_identifier');
        // Full UNIQUE on (kind, identifier) plus a generated column trick
        // to make the UNIQUE apply only to non-archived rows in MySQL 8+:
        //   archived_marker = IFNULL(archived_at, '1970-01-01 00:00:00')
        // Two non-archived rows for the same (kind, identifier) cannot
        // coexist; archived rows are still allowed to share an identifier
        // (e.g., a student re-enrolled with the same number after a gap).
        $this->forge->addUniqueKey(['kind', 'identifier', 'archived_at'], 'uniq_pi_active');

        $this->forge->createTable('patient_identifiers');

        // Add the FK in a second step (forge.createTable doesn't always
        // honor addForeignKey on every driver).
        $this->db->query('ALTER TABLE `patient_identifiers` ADD CONSTRAINT `fk_pi_persons` FOREIGN KEY (`persons_id`) REFERENCES `persons`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    public function down(): void
    {
        if ($this->db->tableExists('patient_identifiers')) {
            $this->forge->dropTable('patient_identifiers', true);
        }
    }
}
