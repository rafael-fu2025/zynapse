<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * KioskCheckinPurpose — capture the walk-in's check-in purpose.
 *
 * Kiosk station redesign: after a patient resolves, the station offers
 * purpose cards (Consultation, Medical Certificate, …) plus an "Other"
 * free-text box. The chosen value is stored here (VARCHAR, length-capped
 * at 120) so triage and the staff trail know why the patient came in.
 * Free-text is never copied into the audit trail (PII-ish); only the
 * checkin record carries it.
 */
final class KioskCheckinPurpose extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('clinic_checkins', [
            'purpose' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
                'default'    => null,
                'after'      => 'outcome',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('clinic_checkins', 'purpose');
    }
}
