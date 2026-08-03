<?php
/**
 * Migration: tighten patient_identifiers uniqueness via a generated marker.
 *
 * Phase 3 follow-up — the original Phase 1.2 migration used a UNIQUE
 * index on (kind, identifier, archived_at). MySQL/MariaDB treat
 * multiple NULL values in a UNIQUE index as DISTINCT, so two non-
 * archived rows (both archived_at IS NULL) for the same (kind,
 * identifier) can coexist. This is the opposite of what we want for
 * live patient rows.
 *
 * The fix: add a generated column `is_archived` (0 when archived_at is
 * NULL, 1 otherwise), and a UNIQUE index on (kind, identifier, is_archived)
 * restricted to `is_archived = 0` via a partial check. MariaDB 10.4
 * doesn't support partial indexes, so we use a non-partial UNIQUE on
 * (kind, identifier, is_archived_marker) where the marker replaces
 * NULL with a sentinel value.
 *
 * Implementation:
 *   1. Drop the old UNIQUE(kind, identifier, archived_at).
 *   2. Add a generated column `is_archived` (TINYINT(1) NOT NULL).
 *   3. Add UNIQUE(kind, identifier, is_archived) so two non-archived
 *      rows (both is_archived=0) cannot coexist.
 *
 * This migration requires MySQL 8.0+ or MariaDB 10.2+ (for generated
 * columns). The dev environment is MariaDB 10.4 which supports it.
 */
declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class PatientIdentifierUniqueness extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('patient_identifiers')) {
            return;
        }

        // 1. Drop the old UNIQUE on archived_at (allows duplicate NULLs).
        $existing = $this->db->query("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'patient_identifiers'
              AND CONSTRAINT_NAME = 'uniq_pi_active'
        ");
        if ($existing->getNumRows() > 0) {
            $this->db->query("ALTER TABLE `patient_identifiers` DROP INDEX `uniq_pi_active`");
        }

        // 2. Add the generated column. MariaDB 10.4 and MySQL 8.0+ both
        // support VIRTUAL generated columns.
        if (! $this->db->fieldExists('is_archived', 'patient_identifiers')) {
            $this->db->query("
                ALTER TABLE `patient_identifiers`
                ADD COLUMN `is_archived` TINYINT(1) GENERATED ALWAYS AS (IFNULL(archived_at, '0000-00-00 00:00:00') <> '0000-00-00 00:00:00') VIRTUAL
            ");
        }

        // 3. Add the tighter UNIQUE.
        $existing = $this->db->query("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'patient_identifiers'
              AND CONSTRAINT_NAME = 'uniq_pi_live'
        ");
        if ($existing->getNumRows() === 0) {
            $this->db->query("
                ALTER TABLE `patient_identifiers`
                ADD CONSTRAINT `uniq_pi_live` UNIQUE (`kind`, `identifier`, `is_archived`)
            ");
        }
    }

    public function down(): void
    {
        $existing = $this->db->query("
            SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = 'patient_identifiers'
              AND CONSTRAINT_NAME = 'uniq_pi_live'
        ");
        if ($existing->getNumRows() > 0) {
            $this->db->query("ALTER TABLE `patient_identifiers` DROP INDEX `uniq_pi_live`");
        }
        if ($this->db->fieldExists('is_archived', 'patient_identifiers')) {
            $this->db->query("ALTER TABLE `patient_identifiers` DROP COLUMN `is_archived`");
        }
        // Restore the old (weaker) UNIQUE.
        $this->db->query("
            ALTER TABLE `patient_identifiers`
            ADD CONSTRAINT `uniq_pi_active` UNIQUE (`kind`, `identifier`, `archived_at`)
        ");
    }
}
