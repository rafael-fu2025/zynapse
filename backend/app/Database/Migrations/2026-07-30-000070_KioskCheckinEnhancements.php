<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * KioskCheckinEnhancements — kiosk gap analysis (July 2026):
 *
 * Adds the `clinic_appointment_confirmed` outcome to the kiosk
 * check-in trail. Previously a student with a CLINIC appointment today
 * was invisible to the kiosk dispatch (only counselling bookings were
 * resolved) and got a brand-new walk-in encounter — bypassing the
 * appointment → encounter auto-open flow and duplicating visits.
 *
 * The kiosk now runs the existing appointment `checked_in` transition,
 * which opens the linked encounter and queues it; this outcome records
 * that path. `encounter_id` (existing FK) carries the opened visit —
 * its row already links back to the appointment.
 */
final class KioskCheckinEnhancements extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `clinic_checkins` MODIFY `outcome`"
            . " ENUM('counselling_confirmed','counselling_already','clinic_appointment_confirmed','clinic_queued','duplicate') NOT NULL"
        );
    }

    public function down(): void
    {
        // Refuse to narrow the ENUM while rows use the new outcome —
        // silently truncating audit-trail values is worse than a
        // failed rollback.
        $rows = (int) $this->db->table('clinic_checkins')
            ->where('outcome', 'clinic_appointment_confirmed')
            ->countAllResults();
        if ($rows > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$rows} check-in(s) carry outcome 'clinic_appointment_confirmed'."
            );
        }

        $this->db->query(
            "ALTER TABLE `clinic_checkins` MODIFY `outcome`"
            . " ENUM('counselling_confirmed','counselling_already','clinic_queued','duplicate') NOT NULL"
        );
    }
}
