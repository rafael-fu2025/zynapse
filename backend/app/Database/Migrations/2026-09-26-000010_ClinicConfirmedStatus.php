<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicConfirmedStatus — adds the missing `confirmed` member to the
 * `clinic_appointments.status` ENUM (2026-09-26).
 *
 * The transition endpoint, the service state machine (`AppointmentService::TRANSITIONS`)
 * and the Appointments page all speak of confirming an appointment — the
 * approval step that puts a staff member on a portal booking — but the
 * column ENUM from `LowercaseStatusEnums` never listed `confirmed`. MySQL
 * strict mode rejects the write with "Data truncated", so every clinic-side
 * confirm was a latent 500. Counselling's own schedule table already has
 * the matching member (`scheduled, confirmed, completed, cancelled, no_show`).
 *
 * No data rewrite is needed going up: no row can hold the value today
 * (the ENUM refused it). Coming down, any `confirmed` rows are folded back
 * to `scheduled` before the column is narrowed.
 */
final class ClinicConfirmedStatus extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `clinic_appointments` MODIFY `status`"
            . " ENUM('scheduled','confirmed','checked_in','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled'"
        );
    }

    public function down(): void
    {
        // MySQL rejects ENUMs differing only by case under ci collation, so
        // widen, rewrite, narrow — same discipline as LowercaseStatusEnums.
        $this->db->query('ALTER TABLE `clinic_appointments` MODIFY `status` VARCHAR(32) NOT NULL');
        $this->db->query("UPDATE clinic_appointments SET status = 'scheduled' WHERE status = 'confirmed'");
        $this->db->query(
            "ALTER TABLE `clinic_appointments` MODIFY `status`"
            . " ENUM('scheduled','checked_in','completed','cancelled','no_show') NOT NULL DEFAULT 'scheduled'"
        );
    }
}
