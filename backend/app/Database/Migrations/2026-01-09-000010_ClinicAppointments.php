<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicAppointments — scheduling for clinic encounters (Phase 9).
 *
 * Lifecycle: Scheduled → CheckedIn → Completed
 *            Scheduled → Cancelled | NoShow
 *
 * All timestamps are UTC; the SPA renders Asia/Manila. Keyset index on
 * (created_at, id); a covering index on (scheduled_at, id) serves the
 * day-view query.
 */
final class ClinicAppointments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'patient_school_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'provider_user_id'  => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'scheduled_at'      => ['type' => 'DATETIME', 'null' => false],
            'status'            => ['type' => 'ENUM', 'constraint' => ['Scheduled', 'CheckedIn', 'Completed', 'Cancelled', 'NoShow'], 'null' => false, 'default' => 'Scheduled'],
            'reason'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'archived_at'       => ['type' => 'DATETIME', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->addKey(['scheduled_at', 'id']);
        $this->forge->addKey('patient_school_id');
        $this->forge->addForeignKey('provider_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_appointments');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_appointments', true);
    }
}
