<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * KioskGuestCheckin — allow walk-in guests (no account / patient record)
 * to check in by name.
 *
 * Guests have no `users` row, so the checkin/encounter `patient_user_id`
 * stays NULL and `patient_school_id` becomes nullable. The typed name is
 * carried in a dedicated `guest_name` column (never conflated with the
 * registry identifiers) so the staff trail and queue still show who is
 * waiting.
 */
final class KioskGuestCheckin extends Migration
{
    public function up(): void
    {
        // clinic_checkins: guests have no school id; store their name.
        $this->forge->modifyColumn('clinic_checkins', [
            'patient_school_id' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
        ]);
        $this->forge->addColumn('clinic_checkins', [
            'guest_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
                'default'    => null,
                'after'      => 'purpose',
            ],
        ]);

        // clinic_encounters: same treatment for the walk-in encounter.
        $this->forge->modifyColumn('clinic_encounters', [
            'patient_school_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
        ]);
        $this->forge->addColumn('clinic_encounters', [
            'guest_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
                'default'    => null,
                'after'      => 'patient_user_id',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('clinic_encounters', 'guest_name');
        $this->forge->dropColumn('clinic_checkins', 'guest_name');
    }
}
