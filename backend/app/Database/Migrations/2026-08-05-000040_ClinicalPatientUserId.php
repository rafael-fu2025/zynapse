<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicalPatientUserId — identity-unification (Phase A, step 4).
 *
 * Every clinical table that references a patient now carries a single
 * `patient_user_id` FK pointing at `users.id`, replacing the dual
 * `patient_school_id` (free text) + `patient_identifier_id` (join-table
 * FK) representation. This is the change that kills the per-module
 * inconsistency in referrals, check-ins, appointments and portals.
 *
 * Backfill resolution order:
 *   1. `patient_identifier_id` → `patient_identifiers` → `persons` →
 *      `persons.user_id` (most reliable; skipped for tables that never
 *      had the identifier FK, e.g. counselling_appointments).
 *   2. `patient_school_id` → `users.student_number` (kind=student) or
 *      `users.employee_number` (kind=employee).
 *
 * The new column is NULLABLE during the transition; the cleanup
 * migration (M5) tightens it to NOT NULL and drops the legacy columns.
 *
 * Idempotent: re-runs are no-ops.
 */
final class ClinicalPatientUserId extends Migration
{
    /**
     * @var array<string, bool> table => has patient_identifier_id?
     */
    private const TABLES = [
        'clinic_encounters'        => true,
        'clinic_appointments'      => true,
        'clinic_checkins'          => true,
        'counselling_sessions'     => true,
        'counselling_appointments' => false,
        'referral_referrals'       => true,
    ];

    public function up(): void
    {
        if ($this->db->fieldExists('student_number', 'users') === false) {
            return; // M1/M2 must have run first.
        }

        foreach (self::TABLES as $table => $hasIdentifierId) {
            if (! $this->db->tableExists($table)) {
                continue;
            }

            // 1. Add patient_user_id.
            if (! $this->db->fieldExists('patient_user_id', $table)) {
                $after = $this->db->fieldExists('patient_identifier_id', $table)
                    ? 'patient_identifier_id'
                    : ($this->db->fieldExists('patient_school_id', $table) ? 'patient_school_id' : 'id');
                $this->forge->addColumn($table, [
                    'patient_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => $after],
                ]);
            }

            // 2a. Backfill via patient_identifier_id -> persons -> users.
            if ($hasIdentifierId
                && $this->db->fieldExists('patient_identifier_id', $table)
                && $this->db->tableExists('patient_identifiers')
                && $this->db->tableExists('persons')
            ) {
                $this->db->query("
                    UPDATE `{$table}` t
                    JOIN `patient_identifiers` pi ON pi.id = t.patient_identifier_id
                    JOIN `persons` p ON p.id = pi.persons_id
                    SET t.patient_user_id = p.user_id
                    WHERE t.patient_user_id IS NULL
                      AND t.patient_identifier_id IS NOT NULL
                      AND p.user_id IS NOT NULL
                ");
            }

            // 2b. Backfill via patient_school_id -> users identifier.
            if ($this->db->fieldExists('patient_school_id', $table)) {
                $this->db->query("
                    UPDATE `{$table}` t
                    LEFT JOIN `users` us ON us.student_number = t.patient_school_id AND us.kind = 'student'
                    LEFT JOIN `users` ue ON ue.employee_number = t.patient_school_id AND ue.kind = 'employee'
                    SET t.patient_user_id = COALESCE(us.id, ue.id)
                    WHERE t.patient_user_id IS NULL
                      AND t.patient_school_id IS NOT NULL
                ");
            }

            // 3. Index + FK.
            if (! $this->indexExists($table, 'idx_' . $table . '_patient_user')) {
                $this->db->query("ALTER TABLE `{$table}` ADD INDEX `idx_{$table}_patient_user` (`patient_user_id`)");
            }
            $fkName = "fk_{$table}_patient_user";
            if (! $this->foreignKeyExists($table, $fkName)) {
                $this->db->query("
                    ALTER TABLE `{$table}`
                    ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`patient_user_id`)
                    REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
                ");
            }
        }
    }

    public function down(): void
    {
        // Not reversible — legacy columns remain until M5.
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

    private function foreignKeyExists(string $table, string $fkName): bool
    {
        $row = $this->db->query("
            SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
              AND CONSTRAINT_NAME = '{$fkName}'
        ")->getRow();
        return $row !== null;
    }
}
