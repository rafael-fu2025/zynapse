<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * EncounterAndQueueOutcome — August 2026 panel revision:
 *
 * Adds an `outcome` discriminator to the clinic encounter and
 * queue tables so the redesign (auto check-in, encounter-level
 * no-show cascade, auto-close of stale open encounters) can
 * audit the *why* of a closed encounter, not just *when* it
 * closed.
 *
 *   - `clinic_encounters.outcome` — VARCHAR(16) NULL,
 *     CHECK IN ('no_show','auto_closed')
 *   - `clinic_queue_entries.outcome` — VARCHAR(16) NULL,
 *     CHECK IN ('no_show','auto_closed')
 *   - Index `idx_ce_status_outcome` on
 *     `clinic_encounters(status, outcome)` — powers the
 *     "auto-close stale open encounter" scan performed by
 *     `ClinicService::autoCloseStaleEncounter()`.
 *
 * Stored as VARCHAR + CHECK (matches the `BmgProcessLogObservability`
 * pattern that avoids `ENUM(...)` literals so future additions don't
 * require a schema rewrite). `outcome` is `NULL` for in-flight
 * encounters / queue entries and for encounters closed by the
 * existing manual close path (which doesn't carry a reason code).
 */
use CodeIgniter\Database\Migration;

class EncounterAndQueueOutcome extends Migration
{
    /**
     * Whitelist of `outcome` values. Public so the
     * `ClinicOutcomeEnumContractTest` can assert the migration
     * stays in sync with the service-layer string literals.
     *
     * @var list<string>
     */
    public const OUTCOME_CODES = ['no_show', 'auto_closed'];

    public function up(): void
    {
        $inList = "'" . implode("','", self::OUTCOME_CODES) . "'";

        if ($this->db->tableExists('clinic_encounters')) {
            if (! $this->db->fieldExists('outcome', 'clinic_encounters')) {
                $this->forge->addColumn('clinic_encounters', [
                    'outcome' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 16,
                        'null'       => true,
                        'after'      => 'closed_at',
                    ],
                ]);
            }

            if ($this->constraintDoesNotExist('clinic_encounters', 'chk_ce_outcome')) {
                $this->db->query(
                    "ALTER TABLE `clinic_encounters`"
                    . " ADD CONSTRAINT `chk_ce_outcome`"
                    . " CHECK (`outcome` IS NULL OR `outcome` IN ({$inList}))"
                );
            }
        }

        if ($this->db->tableExists('clinic_queue_entries')) {
            if (! $this->db->fieldExists('outcome', 'clinic_queue_entries')) {
                $this->forge->addColumn('clinic_queue_entries', [
                    'outcome' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 16,
                        'null'       => true,
                        'after'      => 'finished_at',
                    ],
                ]);
            }

            if ($this->constraintDoesNotExist('clinic_queue_entries', 'chk_cqe_outcome')) {
                $this->db->query(
                    "ALTER TABLE `clinic_queue_entries`"
                    . " ADD CONSTRAINT `chk_cqe_outcome`"
                    . " CHECK (`outcome` IS NULL OR `outcome` IN ({$inList}))"
                );
            }
        }

        // Powers the auto-close scan:
        //   SELECT id FROM clinic_encounters
        //   WHERE status='open' AND (outcome IS NULL)
        //     AND started_at < today_start
        //
        // Guarded so a re-run (e.g. on a DB restored from a snapshot
        // without the `migrations` row) doesn't trip "Duplicate key name".
        //
        // IMPORTANT: forge->addKey() only applies during createTable();
        // it is a no-op when called against an already-existing table.
        // For an ALTER on a live table we need raw DDL — see the
        // AuditReaderIndexes pattern. MariaDB 10.4 / MySQL 8 both
        // accept `CREATE INDEX` but neither supports the IF NOT EXISTS
        // clause for indexes (it was added in MySQL 8.0.29+ and is not
        // yet in MariaDB 10.4), so we gate on information_schema first.
        if ($this->db->tableExists('clinic_encounters')
            && ! $this->indexExists('clinic_encounters', 'idx_ce_status_outcome')) {
            $this->db->query(
                'CREATE INDEX `idx_ce_status_outcome`'
                . ' ON `clinic_encounters` (`status`, `outcome`)'
            );
        }
    }

    public function down(): void
    {
        // Drop the raw-DDL index first; mirrors the raw-DDL create in up().
        if ($this->db->tableExists('clinic_encounters')
            && $this->indexExists('clinic_encounters', 'idx_ce_status_outcome')) {
            $this->db->query('DROP INDEX `idx_ce_status_outcome` ON `clinic_encounters`');
        }

        $this->dropConstraintIfExists('clinic_encounters', 'chk_ce_outcome');
        $this->dropConstraintIfExists('clinic_queue_entries', 'chk_cqe_outcome');

        if ($this->db->tableExists('clinic_encounters')
            && $this->db->fieldExists('outcome', 'clinic_encounters')) {
            $this->forge->dropColumn('clinic_encounters', 'outcome');
        }
        if ($this->db->tableExists('clinic_queue_entries')
            && $this->db->fieldExists('outcome', 'clinic_queue_entries')) {
            $this->forge->dropColumn('clinic_queue_entries', 'outcome');
        }
    }

    private function constraintDoesNotExist(string $table, string $constraint): bool
    {
        $r = $this->db->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS"
            . " WHERE CONSTRAINT_SCHEMA = DATABASE()"
            . "   AND TABLE_NAME = " . $this->db->escape($table)
            . "   AND CONSTRAINT_NAME = " . $this->db->escape($constraint)
        );
        return $r->getNumRows() === 0;
    }

    /**
     * True iff `$index` exists on `$table` in the current database.
     * Used to keep `addKey` idempotent across re-runs that don't
     * have a corresponding `migrations` row yet.
     */
    private function indexExists(string $table, string $index): bool
    {
        // MariaDB 10.4 / MySQL 8: information_schema.STATISTICS exposes
        // INDEX_SCHEMA + TABLE_SCHEMA. CONSTRAINT_SCHEMA does not exist
        // on STATISTICS in either engine. TABLE_CONSTRAINTS, by contrast,
        // does have CONSTRAINT_SCHEMA — keep that path untouched above.
        $r = $this->db->query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS"
            . " WHERE TABLE_SCHEMA = DATABASE()"
            . "   AND TABLE_NAME = " . $this->db->escape($table)
            . "   AND INDEX_NAME = " . $this->db->escape($index)
            . " LIMIT 1"
        );
        return $r->getNumRows() > 0;
    }

    /**
     * Drop a CHECK constraint only if it exists. Required because
     * MariaDB 10.4 doesn't support `DROP CHECK` and bare `DROP
     * CONSTRAINT chk_*` throws "check that it exists" when the
     * constraint was already removed by a prior run.
     */
    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        $r = $this->db->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS"
            . " WHERE CONSTRAINT_SCHEMA = DATABASE()"
            . "   AND TABLE_NAME = " . $this->db->escape($table)
            . "   AND CONSTRAINT_NAME = " . $this->db->escape($constraint)
        );
        if ($r->getNumRows() > 0) {
            $this->db->query(
                "ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`"
            );
        }
    }
}