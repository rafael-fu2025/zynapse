<?php
/**
 * Migration: add persons_id FK to patients_students and patients_employees.
 *
 * Phase 3 follow-up — the original Phase 1.3 backfill created `persons`
 * rows for every legacy patient but did NOT add a `persons_id` column
 * to the legacy tables. The new-path JOIN (in PatientLookupService) was
 * therefore joining on `ps.user_id = p.user_id`, which only works for
 * patients with a `user_id` link (1 of 20 students, 3 of 23 employees
 * in the dev database). The other 19 students and 20 employees have
 * NULL `user_id`, so their type-specific columns (course, year_level,
 * department, position, etc.) are returned as NULL by the new path.
 *
 * This migration fixes that by:
 *   1. Adding a `persons_id` column to each legacy table.
 *   2. Backfilling it from the `persons` table by matching on
 *      `user_id` (when set) or by name+dob fallback (when NULL).
 *   3. Adding a UNIQUE constraint on `persons_id` (a person is one row
 *      in each legacy table at most).
 *
 * After this migration the new path's JOIN can use the direct
 * `ps.persons_id = p.id` join, which works for every patient regardless
 * of `user_id`.
 */
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class LegacyPersonsIdLink extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('persons')) {
            return;
        }

        foreach (['patients_students', 'patients_employees'] as $legacy) {
            if (! $this->db->tableExists($legacy)) {
                continue;
            }

            // 1. Add the column.
            if (! $this->db->fieldExists('persons_id', $legacy)) {
                $this->forge->addColumn($legacy, [
                    'persons_id' => [
                        'type'       => 'BIGINT',
                        'unsigned'   => true,
                        'null'       => true,
                        'after'      => 'id',
                    ],
                ]);
                $this->forge->addKey('persons_id', false, false, 'idx_' . $legacy . '_persons');
            }

            // 2. Backfill: match on user_id (the most reliable key).
            $this->db->query("
                UPDATE `{$legacy}` t
                JOIN `persons` p ON p.user_id = t.user_id AND p.kind = " . $this->db->escape($legacy === 'patients_students' ? 'student' : 'employee') . "
                SET t.persons_id = p.id
                WHERE t.user_id IS NOT NULL
                  AND t.persons_id IS NULL
            ");

            // 3. UNIQUE on persons_id (a person is at most one row per table).
            $constraintName = "uniq_{$legacy}_persons";
            $existing = $this->db->query("
                SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$legacy}'
                  AND CONSTRAINT_NAME = '{$constraintName}'
            ");
            if ($existing->getNumRows() === 0) {
                $this->db->query("
                    ALTER TABLE `{$legacy}`
                    ADD CONSTRAINT `{$constraintName}` UNIQUE (`persons_id`)
                ");
            }
        }
    }

    public function down(): void
    {
        foreach (['patients_students', 'patients_employees'] as $legacy) {
            if (! $this->db->tableExists($legacy)) {
                continue;
            }
            $constraintName = "uniq_{$legacy}_persons";
            $existing = $this->db->query("
                SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = '{$legacy}'
                  AND CONSTRAINT_NAME = '{$constraintName}'
            ");
            if ($existing->getNumRows() > 0) {
                $this->db->query("ALTER TABLE `{$legacy}` DROP INDEX `{$constraintName}`");
            }
            if ($this->db->fieldExists('persons_id', $legacy)) {
                $this->forge->dropColumn($legacy, 'persons_id');
            }
        }
    }
}
