<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * DropCounsellingAvailabilityMaxSlots — capacity leaves the availability
 * model (2026-09-24).
 *
 * `counselling_availability.max_slots` let a counsellor declare how many
 * patients could hold the same instant. It was the only source of the
 * "Capacity" figure the desk saw, and it disagreed with itself: the portal
 * slot list collapsed overlapping windows of one counsellor to their
 * largest `max_slots`, while the booking transaction summed every covering
 * row, so the number on screen and the number enforced could differ.
 *
 * A bookable time is now **one appointment per staff member covering it** —
 * the rule the clinic path already used (`PortalAppointmentService::
 * clinicSlots` keys coverage by staff id and contributes a single place
 * each). Availability becomes a set of cover, not a count, so no column
 * remains to hold it.
 *
 * The CHECK must go first: MariaDB refuses to drop a column that a CHECK
 * constraint still references, and `DROP CHECK` is not available on the
 * 10.4 baseline CI runs on, so the constraint is dropped by name through
 * information_schema (same helper as the BMG migrations).
 *
 * The applied migration is never rewritten — history is append-only.
 *
 * @see \App\Modules\Counselling\Services\ScheduleService::updateSlot()
 */

final class DropCounsellingAvailabilityMaxSlots extends Migration
{
    private const TABLE = 'counselling_availability';

    private const CAPACITY_CHECK = 'chk_ca_max_slots';

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        $this->dropConstraintIfExists(self::TABLE, self::CAPACITY_CHECK);

        if ($this->db->fieldExists('max_slots', self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, 'max_slots');
        }
    }

    /**
     * Structure only — the per-row capacities are gone, and deliberately not
     * reconstructed. Every row comes back at the default of 1, which is the
     * value the model now implies anyway.
     */
    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }

        if (! $this->db->fieldExists('max_slots', self::TABLE)) {
            $this->forge->addColumn(self::TABLE, [
                'max_slots' => [
                    'type'       => 'INT',
                    'unsigned'   => true,
                    'null'       => false,
                    'default'    => 1,
                    'after'      => 'end_time',
                ],
            ]);
        }

        if ($this->constraintDoesNotExist(self::TABLE, self::CAPACITY_CHECK)) {
            $this->db->query(
                'ALTER TABLE `' . self::TABLE . '` ADD CONSTRAINT `' . self::CAPACITY_CHECK . '`'
                . ' CHECK (`max_slots` >= 1)'
            );
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
