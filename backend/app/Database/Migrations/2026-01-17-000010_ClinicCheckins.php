<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicCheckins — kiosk self-service check-in trail, recycled from
 * legacy `synapse_ag` Iot\CheckinController + offline_checkin_buffer
 * (Phase 17).
 *
 * Adaptations from the legacy design:
 *   - The offline buffer moves CLIENT-side (the kiosk SPA queues scans
 *     in localStorage and replays them with the original `scanned_at`);
 *     the server keeps the authoritative scan trail in this table and
 *     enforces the legacy ±5-minute duplicate window against it.
 *   - `counselling_appointment_id` is a LOGICAL reference (no FK):
 *     counselling is another module — same shared-reference convention
 *     as the three-strike counter.
 *   - `encounter_id` keeps a real FK: clinic_encounters is module-local.
 */
final class ClinicCheckins extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'patient_school_id'          => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => false],
            'method'                     => ['type' => 'ENUM', 'constraint' => ['qr', 'rfid', 'manual'], 'null' => false, 'default' => 'manual'],
            'station_id'                 => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'outcome'                    => ['type' => 'ENUM', 'constraint' => ['counselling_confirmed', 'counselling_already', 'clinic_queued', 'duplicate'], 'null' => false],
            'counselling_appointment_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'encounter_id'               => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'recorded_by_user_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'scanned_at'                 => ['type' => 'DATETIME', 'null' => false],
            'created_at'                 => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['patient_school_id', 'scanned_at']);
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->addForeignKey('encounter_id', 'clinic_encounters', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('recorded_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_checkins');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_checkins', true);
    }
}
