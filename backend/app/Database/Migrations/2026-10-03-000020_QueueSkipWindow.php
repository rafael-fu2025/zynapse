<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * QueueSkipWindow — October 2026 panel revision: "Skip" stops being a
 * dead-end and becomes a 60-minute grace window.
 *
 * Before this migration, `transition('skip')` flipped a queue entry to
 * `skipped` and left it there forever: the patient was stranded in the
 * queue list with no timer and no way back. The clinic wanted a
 * recall workflow — skip a patient who hasn't arrived, keep them for an
 * hour, and only then mark them no-show automatically.
 *
 * Three columns on `clinic_queue_entries` carry the window. They live
 * on the QUEUE row (not the encounter) because they describe this
 * specific visit's operational wait, and a re-opened encounter gets a
 * fresh queue row:
 *
 *   - `skipped_at`        — when staff clicked Skip (UTC). Drives the
 *                           "time skipped" column in the module.
 *   - `skip_deadline_at`  — `skipped_at + 60 min` (UTC). THE authoritative
 *                           deadline: the frontend countdown renders the
 *                           remainder against it, and the sweep selects
 *                           `skip_deadline_at <= now`. Stored rather than
 *                           computed so the window length can change
 *                           without retroactively moving live deadlines.
 *   - `returned_at`       — when staff returned the patient to the queue
 *                           (UTC). NULL while the skip is still open;
 *                           set on return/recall so the module can show
 *                           "Returned" instead of a live countdown.
 *
 * `idx_cqe_skip_deadline` powers the sweep's hot query
 * (`status='skipped' AND skip_deadline_at <= ?`). Guarded with the same
 * information_schema checks the EncounterAndQueueOutcome migration uses,
 * because MariaDB 10.4 has no `CREATE INDEX IF NOT EXISTS` and re-runs
 * on restored snapshots must not trip "Duplicate key name".
 *
 * Down() drops the index first (raw DDL, mirroring up()) then the
 * columns, so the reverse is always safe.
 */
use CodeIgniter\Database\Migration;

class QueueSkipWindow extends Migration
{
    /**
     * The recall grace window, in minutes. Public so the feature test
     * and the sweep service can reference one number instead of three
     * drifting literals (60 here, 60 in QueueService, 60 in the SPA).
     */
    public const SKIP_WINDOW_MINUTES = 60;

    public function up(): void
    {
        if (! $this->db->tableExists('clinic_queue_entries')) {
            return;
        }

        if (! $this->db->fieldExists('skipped_at', 'clinic_queue_entries')) {
            $this->forge->addColumn('clinic_queue_entries', [
                'skipped_at' => [
                    'type'  => 'DATETIME',
                    'null'  => true,
                    'after' => 'finished_at',
                ],
            ]);
        }

        if (! $this->db->fieldExists('skip_deadline_at', 'clinic_queue_entries')) {
            $this->forge->addColumn('clinic_queue_entries', [
                'skip_deadline_at' => [
                    'type'  => 'DATETIME',
                    'null'  => true,
                    'after' => 'skipped_at',
                ],
            ]);
        }

        if (! $this->db->fieldExists('returned_at', 'clinic_queue_entries')) {
            $this->forge->addColumn('clinic_queue_entries', [
                'returned_at' => [
                    'type'  => 'DATETIME',
                    'null'  => true,
                    'after' => 'skip_deadline_at',
                ],
            ]);
        }

        if (! $this->indexExists('clinic_queue_entries', 'idx_cqe_skip_deadline')) {
            $this->db->query(
                'CREATE INDEX `idx_cqe_skip_deadline`'
                . ' ON `clinic_queue_entries` (`status`, `skip_deadline_at`)'
            );
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('clinic_queue_entries')) {
            return;
        }

        if ($this->indexExists('clinic_queue_entries', 'idx_cqe_skip_deadline')) {
            $this->db->query('DROP INDEX `idx_cqe_skip_deadline` ON `clinic_queue_entries`');
        }

        foreach (['returned_at', 'skip_deadline_at', 'skipped_at'] as $column) {
            if ($this->db->fieldExists($column, 'clinic_queue_entries')) {
                $this->forge->dropColumn('clinic_queue_entries', $column);
            }
        }
    }

    /**
     * True iff `$index` exists on `$table` in the current database.
     * Duplicated from EncounterAndQueueOutcome (that class is not
     * autoloadable as a helper — migrations are one-shot scripts).
     */
    private function indexExists(string $table, string $index): bool
    {
        $r = $this->db->query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS"
            . " WHERE TABLE_SCHEMA = DATABASE()"
            . "   AND TABLE_NAME = " . $this->db->escape($table)
            . "   AND INDEX_NAME = " . $this->db->escape($index)
            . " LIMIT 1"
        );
        return $r->getNumRows() > 0;
    }
}
