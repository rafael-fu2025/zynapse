<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgUnitMaintenanceState — widen the `status` ENUM on
 * `facilities_bmg_units` to include `Maintenance`.
 *
 * Rationale:
 *   The Facilities state machine is `Idle → Processing → AwaitingOutput
 *   → Idle` (or → Cancelled). Operators also need a way to park a unit
 *   when it is being serviced. The `setUnitMaintenance` endpoint and
 *   `BmgService::setUnitMaintenance` already exist; the table-level
 *   ENUM was the only piece missing.
 *
 * Behaviour:
 *   - Existing rows are unchanged.
 *   - The ENUM grows by one value (`Maintenance`).
 *   - The migration is idempotent: it inspects the live column and
 *     skips the ALTER if `Maintenance` is already present. Re-running
 *     `spark migrate` is therefore a no-op on upgraded databases.
 */
final class BmgUnitMaintenanceState extends Migration
{
    public function up(): void
    {
        if ($this->alreadyHasMaintenance()) {
            return;
        }

        $this->db->query(<<<'SQL'
            ALTER TABLE facilities_bmg_units
            MODIFY COLUMN status ENUM(
                'Idle',
                'Processing',
                'AwaitingOutput',
                'Cancelled',
                'Maintenance'
            ) NOT NULL DEFAULT 'Idle'
        SQL);
    }

    public function down(): void
    {
        // Refuse to roll back while any row is in Maintenance — the
        // service code would still try to write it and the column would
        // silently truncate to ''. Operator must move rows back to Idle
        // first.
        $rows = (int) $this->db->table('facilities_bmg_units')
            ->where('status', 'Maintenance')
            ->countAllResults();
        if ($rows > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$rows} BMG unit(s) are still in 'Maintenance'. "
                . "Move them back to 'Idle' before downgrading the schema."
            );
        }

        $this->db->query(<<<'SQL'
            ALTER TABLE facilities_bmg_units
            MODIFY COLUMN status ENUM(
                'Idle',
                'Processing',
                'AwaitingOutput',
                'Cancelled'
            ) NOT NULL DEFAULT 'Idle'
        SQL);
    }

    private function alreadyHasMaintenance(): bool
    {
        $row = $this->db->query(
            "SHOW COLUMNS FROM facilities_bmg_units WHERE Field = 'status'"
        )->getRowArray();

        if ($row === null || ! isset($row['Type'])) {
            return false;
        }

        // MySQL/MariaDB report the ENUM definition as
        // `enum('Idle','Processing',...)`. Case-sensitive substring
        // check is sufficient.
        return str_contains((string) $row['Type'], "'Maintenance'");
    }
}
