<?php
/**
 * ClinicalForeignKeys — Phase 2 of the patient-registry consolidation.
 *
 * For every clinical table that today holds a free-text `patient_school_id`,
 * adds a new `patient_identifier_id` BIGINT UNSIGNED column that becomes
 * the canonical FK target.
 *
 * For each table:
 *   1. Add `patient_identifier_id` column (nullable, no FK yet).
 *   2. Backfill: look up the matching (kind, identifier) row in
 *      `patient_identifiers` and set the new column.
 *   3. After backfill, add the FK constraint.
 *
 * The new column is NULLABLE for the transition window. A future
 * migration (Phase 7) will tighten it to NOT NULL once the legacy
 * patient_school_id columns are dropped.
 *
 * Idempotent: re-runs are no-ops.
 */
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class ClinicalForeignKeys extends Migration
{
    /**
     * Map of clinical table -> (kind discriminator, has_archived_at).
     * The kind is used to disambiguate which patient_identifiers row to
     * join to. clinic_appointments, clinic_encounters, clinic_checkins,
     * counselling_sessions, counselling_appointments, referral_referrals
     * all use 'student' or 'employee' — for now we try 'student' first,
     * falling back to 'employee'. (A future migration can introduce a
     * `patient_kind` discriminator on each clinical table if needed.)
     *
     * @var array<string, array{kind: string|null, has_archived_at: bool}>
     */
    private const TABLES = [
        'clinic_encounters'      => ['kind' => null,         'has_archived_at' => true],
        'clinic_appointments'     => ['kind' => null,         'has_archived_at' => true],
        'counselling_sessions'   => ['kind' => null,         'has_archived_at' => true],
        // clinic_checkins was created without an archived_at column (legacy
        // schema from the Iot\CheckinController migration). The security
        // spec requires soft-delete via archived_at on every clinical table;
        // tracked as a follow-up — for now we skip the t.archived_at
        // predicate so the backfill SQL stays valid. New clinical tables
        // (added after Phase 18) MUST include archived_at.
        'clinic_checkins'         => ['kind' => null,         'has_archived_at' => false],
        'referral_referrals'      => ['kind' => null,         'has_archived_at' => true],
        // clinic_queue_entries and clinic_treatments do not have
        // patient_school_id; they are linked via encounter_id and need
        // a separate treatment (handled in Phase 2.2 below).
    ];

    public function up(): void
    {
        if (! $this->db->tableExists('patient_identifiers')) {
            // Phase 1.2 must run first.
            return;
        }

        foreach (self::TABLES as $table => $meta) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if (! $this->db->fieldExists('patient_school_id', $table)) {
                continue;
            }
            if (! $this->db->fieldExists('patient_identifier_id', $table)) {
                $this->forge->addColumn($table, [
                    'patient_identifier_id' => [
                        'type'       => 'BIGINT',
                        'unsigned'   => true,
                        'null'       => true,
                        'after'      => 'patient_school_id',
                    ],
                ]);
                $this->forge->addKey('patient_identifier_id', false, false, 'idx_' . $table . '_pi');
            }

            // Backfill — for each row, try to resolve patient_school_id
            // to a patient_identifiers row. We try 'student' first then
            // 'employee' (matches the existing CheckinService heuristic).
            $archivedClause = $meta['has_archived_at']
                ? "AND t.archived_at IS NULL AND pi.archived_at IS NULL"
                : "AND pi.archived_at IS NULL";

            $sql = "
                UPDATE `{$table}` t
                LEFT JOIN `patient_identifiers` pi
                    ON pi.identifier = t.patient_school_id
                    AND pi.kind = 'student'
                    {$archivedClause}
                LEFT JOIN `patient_identifiers` pi2
                    ON pi2.identifier = t.patient_school_id
                    AND pi2.kind = 'employee'
                    {$archivedClause}
                SET t.patient_identifier_id = COALESCE(pi.id, pi2.id)
                WHERE t.patient_school_id IS NOT NULL
                  AND t.patient_identifier_id IS NULL
            ";
            $this->db->query($sql);

            // Add the FK constraint if not present.
            $constraintName = "fk_{$table}_pi";
            $existing = $this->db->query("
                SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$table}'
                  AND CONSTRAINT_NAME = '{$constraintName}'
            ");
            if ($existing->getNumRows() === 0) {
                $this->db->query("
                    ALTER TABLE `{$table}`
                    ADD CONSTRAINT `{$constraintName}`
                    FOREIGN KEY (`patient_identifier_id`)
                    REFERENCES `patient_identifiers`(`id`)
                    ON DELETE RESTRICT ON UPDATE CASCADE
                ");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $meta) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            $constraintName = "fk_{$table}_pi";
            $existing = $this->db->query("
                SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$table}'
                  AND CONSTRAINT_NAME = '{$constraintName}'
            ");
            if ($existing->getNumRows() > 0) {
                $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraintName}`");
            }
            if ($this->db->fieldExists('patient_identifier_id', $table)) {
                $this->forge->dropColumn($table, 'patient_identifier_id');
            }
        }
    }
}
