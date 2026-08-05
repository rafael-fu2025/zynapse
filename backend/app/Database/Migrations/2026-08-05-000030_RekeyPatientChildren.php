<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RekeyPatientChildren — identity-unification (Phase A, step 3).
 *
 * `patient_allergies` and `patient_contacts` are student-specific child
 * tables that currently FK to `patients_students.id`. After the users
 * consolidation they must point at the consolidated user instead:
 *
 *   - add `user_id`, backfill from `patients_students.user_id` (or an
 *     identifier match against `users.student_number`),
 *   - drop the old `student_id` FK + column,
 *   - add the `user_id` FK to `users.id`.
 *
 * Idempotent: re-runs are no-ops.
 */
final class RekeyPatientChildren extends Migration
{
    /** @var list<string> */
    private const TABLES = ['patient_allergies', 'patient_contacts'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }

            // 1. Add user_id.
            if (! $this->db->fieldExists('user_id', $table)) {
                $this->forge->addColumn($table, [
                    'user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'id'],
                ]);
            }

            // 2. Backfill.
            if ($this->db->tableExists('patients_students') && $this->db->fieldExists('student_number', 'users')) {
                $this->db->query("
                    UPDATE `{$table}` c
                    JOIN `patients_students` s ON s.id = c.student_id
                    LEFT JOIN `users` u ON u.student_number = s.student_number
                    SET c.user_id = COALESCE(s.user_id, u.id)
                    WHERE c.user_id IS NULL
                ");
            }

            // 3. Drop legacy FK + column.
            $this->dropForeignKey($table, 'student_id');
            if ($this->db->fieldExists('student_id', $table)) {
                $this->forge->dropColumn($table, 'student_id');
            }

            // 4. FK + index on user_id.
            $fkName = "fk_{$table}_users";
            if (! $this->foreignKeyExists($table, $fkName)) {
                $this->db->query("
                    ALTER TABLE `{$table}`
                    ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`user_id`)
                    REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
                ");
            }
            if (! $this->indexExists($table, 'idx_' . $table . '_user')) {
                $this->db->query("ALTER TABLE `{$table}` ADD INDEX `idx_{$table}_user` (`user_id`)");
            }
        }
    }

    public function down(): void
    {
        // Legacy tables are dropped in M5; not reversible.
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

    private function dropForeignKey(string $table, string $column): void
    {
        $row = $this->db->query("
            SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
              AND COLUMN_NAME = '{$column}'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ")->getRow();
        if ($row !== null) {
            $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$row->CONSTRAINT_NAME}`");
        }
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
