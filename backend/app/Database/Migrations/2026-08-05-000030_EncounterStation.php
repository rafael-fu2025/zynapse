<?php

declare(strict_types=1);

namespace App\Database\Migrations;

/**
 * EncounterStation — carries the kiosk station onto the visit record.
 *
 * `clinic_checkins` already records `station_id` (which kiosk the
 * patient used); this migration mirrors it onto `clinic_encounters` so
 * the clinic surface (Queue tab, Closed tab, view dialog) can show
 * which station opened the visit. Populated by `CheckinService` for
 * kiosk walk-ins + guest walk-ins; null for appointment auto-check-ins
 * (no station involved) and desk-created encounters.
 */
use CodeIgniter\Database\Migration;

final class EncounterStation extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('clinic_encounters')
            && ! $this->db->fieldExists('station_id', 'clinic_encounters')) {
            $this->db->query('ALTER TABLE `clinic_encounters` ADD COLUMN `station_id` VARCHAR(64) NULL DEFAULT NULL AFTER `attending_user_id`');
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('clinic_encounters')
            && $this->db->fieldExists('station_id', 'clinic_encounters')) {
            $this->db->query('ALTER TABLE `clinic_encounters` DROP COLUMN `station_id`');
        }
    }
}
