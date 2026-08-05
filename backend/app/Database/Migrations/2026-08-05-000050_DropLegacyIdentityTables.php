<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * DropLegacyIdentityTables — identity-unification cleanup (Phase D).
 *
 * Removes the legacy identity/linking tables and columns now that
 * `users` is the single canonical identity:
 *
 *   1. Drop the dead `patient_identifier_id` FK columns on clinical
 *      tables (they referenced `patient_identifiers`; the app now uses
 *      `patient_user_id`).
 *   2. Drop `users.person_id` (the old FK to `persons`).
 *   3. Drop any remaining FK that references the legacy tables.
 *   4. Drop `persons`, `patient_identifiers`, `patients_students`,
 *      `patients_employees`.
 *
 * `patient_school_id` (free-text display identifier) is intentionally
 * KEPT on clinical rows — it is not a linking mechanism and is still
 * returned by DTOs / used by the queue display.
 *
 * Idempotent: re-runs are no-ops.
 */
final class DropLegacyIdentityTables extends Migration
{
    /** @var list<string> tables that still carry the dead identifier FK */
    private const CLINICAL_WITH_IDENTIFIER = [
        'clinic_encounters',
        'clinic_appointments',
        'clinic_checkins',
        'counselling_sessions',
        'referral_referrals',
    ];

    /** @var list<string> the legacy identity/linking tables */
    private const LEGACY_TABLES = [
        'persons',
        'patient_identifiers',
        'patients_students',
        'patients_employees',
    ];

    public function up(): void
    {
        // 1. Drop the dead patient_identifier_id FK columns.
        foreach (self::CLINICAL_WITH_IDENTIFIER as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if (! $this->db->fieldExists('patient_identifier_id', $table)) {
                continue;
            }
            $this->dropForeignKeysOnColumn($table, 'patient_identifier_id');
            $this->forge->dropColumn($table, 'patient_identifier_id');
        }

        // 2. Drop users.person_id (FK + unique index + column).
        if ($this->db->tableExists('users') && $this->db->fieldExists('person_id', 'users')) {
            $this->dropForeignKeysOnColumn('users', 'person_id');
            $this->dropIndex('users', 'uniq_users_person_id');
            $this->forge->dropColumn('users', 'person_id');
        }

        // 3. Drop any remaining FK that references the legacy tables
        //    (e.g. persons.user_id -> users, patient_identifiers -> persons).
        foreach (self::LEGACY_TABLES as $table) {
            $this->dropForeignKeysReferencing($table);
        }

        // 4. Drop the legacy tables.
        foreach (self::LEGACY_TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }

    public function down(): void
    {
        // Not reversible — the legacy schema is superseded by `users`.
    }

    private function dropForeignKeysOnColumn(string $table, string $column): void
    {
        $rows = $this->db->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$table, $column],
        )->getResultArray();
        foreach ($rows as $r) {
            $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$r['CONSTRAINT_NAME']}`");
        }
    }

    private function dropForeignKeysReferencing(string $referencedTable): void
    {
        $rows = $this->db->query(
            "SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?",
            [$referencedTable],
        )->getResultArray();
        foreach ($rows as $r) {
            $this->db->query("ALTER TABLE `{$r['TABLE_NAME']}` DROP FOREIGN KEY `{$r['CONSTRAINT_NAME']}`");
        }
    }

    private function dropIndex(string $table, string $indexName): void
    {
        $row = $this->db->query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
            [$table, $indexName],
        )->getRow();
        if ($row !== null) {
            $this->db->query("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        }
    }
}
