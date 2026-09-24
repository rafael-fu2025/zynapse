<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * UnassignedAppointments — a patient books a TIME, not a person
 * (2026-09-23).
 *
 * Booking used to pin a provider: `clinic_appointments.provider_user_id`
 * and `counselling_appointments.counsellor_user_id` were both NOT NULL,
 * and the portal's slot list offered "9:00 AM · Nurse Reyes" — one row
 * per free staff member. Patients were choosing staff, which they have
 * no basis to do, and the desk then had to honour a pairing that made
 * no operational sense.
 *
 * The model is now: the patient picks a department and a time derived
 * from the staff schedules, and **the staff member who approves the
 * appointment becomes its provider**. Availability becomes a pooled
 * count per time rather than a per-provider flag, so three free nurses
 * at 09:00 means three bookable 09:00 slots.
 *
 * Hence: both columns become nullable. NULL means "booked, not yet
 * approved" — the same convention `referral_referrals.provider_user_id`
 * already uses ("a freshly submitted referral has no provider until
 * someone on the target module acknowledges it").
 *
 * The FKs are dropped and re-added explicitly so the constraint names
 * are stable and greppable; the ON DELETE RESTRICT behaviour is
 * unchanged, since a user who has ever been assigned an appointment
 * still must not be deletable out from under it.
 *
 * Note the columns are left indexed: MySQL keeps the FK's backing index
 * when the constraint is dropped, and re-adding the FK reuses it.
 */
final class UnassignedAppointments extends Migration
{
    /** @var list<array{0:string,1:string,2:string}> table, column, constraint name */
    private const COLUMNS = [
        ['clinic_appointments', 'provider_user_id', 'fk_clinic_appointments_provider'],
        ['counselling_appointments', 'counsellor_user_id', 'fk_counselling_appointments_counsellor'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column, $constraint]) {
            // Drop the legacy auto-named FK before relaxing the column.
            $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$table}_{$column}_foreign`");
            $this->db->query(
                "ALTER TABLE `{$table}` MODIFY `{$column}` BIGINT UNSIGNED NULL"
            );
            $this->db->query(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` "
                . "FOREIGN KEY (`{$column}`) REFERENCES `users`(`id`) ON DELETE RESTRICT"
            );
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as [$table, $column, $constraint]) {
            $this->db->query("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
            $this->db->query(
                "ALTER TABLE `{$table}` MODIFY `{$column}` BIGINT UNSIGNED NOT NULL"
            );
            $this->db->query(
                "ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_{$column}_foreign` "
                . "FOREIGN KEY (`{$column}`) REFERENCES `users`(`id`) ON DELETE RESTRICT"
            );
        }
    }
}
