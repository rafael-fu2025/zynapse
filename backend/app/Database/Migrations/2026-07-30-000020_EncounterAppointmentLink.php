<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * EncounterAppointmentLink — panel revision (July 2026):
 *
 * Encounters are the anchor for actual clinic actions; appointments are
 * only the scheduling layer. Checking in a clinic appointment now
 * auto-creates the day's encounter, linked via `appointment_id`.
 *
 * Nullable — walk-ins (manual create + kiosk) have no appointment. The
 * UNIQUE index enforces at most ONE encounter per appointment, so a
 * double check-in can never spawn a second visit record.
 */
final class EncounterAppointmentLink extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('clinic_encounters', [
            'appointment_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'after' => 'patient_school_id'],
        ]);

        $this->db->query(
            'ALTER TABLE `clinic_encounters`'
            . ' ADD CONSTRAINT `fk_ce_appointment` FOREIGN KEY (`appointment_id`)'
            . ' REFERENCES `clinic_appointments`(`id`) ON DELETE RESTRICT'
        );
        $this->db->query('CREATE UNIQUE INDEX `uq_ce_appointment` ON `clinic_encounters` (`appointment_id`)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `clinic_encounters` DROP FOREIGN KEY `fk_ce_appointment`');
        $this->db->query('DROP INDEX `uq_ce_appointment` ON `clinic_encounters`');
        $this->forge->dropColumn('clinic_encounters', 'appointment_id');
    }
}
