<?php
/**
 * Migration: fix patient_identifiers uniqueness for re-enrollment scenarios.
 *
 * Phase 3 follow-up #2 — the previous follow-up migration
 * (PatientIdentifierUniqueness) added a UNIQUE(kind, identifier,
 * is_archived) where `is_archived` is a generated column. That
 * design DOES enforce "one live row per (kind, identifier)" but
 * BREAKS the re-enrollment scenario: a student graduates
 * (archived_at = T1), then re-enrolls (new row with archived_at =
 * NULL) — both rows have is_archived=0 and the UNIQUE rejects the
 * second insert.
 *
 * The original Phase 1.2 UNIQUE(kind, identifier, archived_at) ALLOWS
 * re-enrollment (the first row has archived_at=T1, the second has
 * archived_at=NULL — different values, no collision). The original
 * UNIQUE does NOT enforce "one live row" because MySQL allows
 * multiple NULLs in a UNIQUE index.
 *
 * The fix: restore the original UNIQUE and enforce "one live row" at
 * the application layer. The PatientService CRUD methods check for an
 * existing live row before insert and either update or archive as
 * appropriate. This is the same pattern used by EmployeeUserLink /
 * StudentUserLink for the per-table user_id uniqueness.
 *
 * Application invariant: at most one row with (kind, identifier)
 * may have archived_at IS NULL. The service layer MUST enforce this
 * before insert.
 */
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class PatientIdentifierUniquenessFix extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('patient_identifiers')) {
            return;
        }

        // 1. Drop the broken uniq_pi_live UNIQUE.
        $existing = $this->db->query("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'patient_identifiers'
              AND CONSTRAINT_NAME = 'uniq_pi_live'
        ");
        if ($existing->getNumRows() > 0) {
            $this->db->query("ALTER TABLE `patient_identifiers` DROP INDEX `uniq_pi_live`");
        }

        // 2. Drop the is_archived generated column (we don't need it).
        if ($this->db->fieldExists('is_archived', 'patient_identifiers')) {
            $this->db->query("ALTER TABLE `patient_identifiers` DROP COLUMN `is_archived`");
        }

        // 3. Restore the original UNIQUE(kind, identifier, archived_at).
        $existing = $this->db->query("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'patient_identifiers'
              AND CONSTRAINT_NAME = 'uniq_pi_active'
        ");
        if ($existing->getNumRows() === 0) {
            $this->db->query("
                ALTER TABLE `patient_identifiers`
                ADD CONSTRAINT `uniq_pi_active` UNIQUE (`kind`, `identifier`, `archived_at`)
            ");
        }
    }

    public function down(): void
    {
        // Roll back to the broken state. Useful only for diagnostics.
        $existing = $this->db->query("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'patient_identifiers'
              AND CONSTRAINT_NAME = 'uniq_pi_active'
        ");
        if ($existing->getNumRows() > 0) {
            $this->db->query("ALTER TABLE `patient_identifiers` DROP INDEX `uniq_pi_active`");
        }
        $this->db->query("
            ALTER TABLE `patient_identifiers`
            ADD COLUMN `is_archived` TINYINT(1) GENERATED ALWAYS AS (IFNULL(archived_at, '0000-00-00 00:00:00') <> '0000-00-00 00:00:00') VIRTUAL
        ");
        $this->db->query("
            ALTER TABLE `patient_identifiers`
            ADD CONSTRAINT `uniq_pi_live` UNIQUE (`kind`, `identifier`, `is_archived`)
        ");
    }
}
