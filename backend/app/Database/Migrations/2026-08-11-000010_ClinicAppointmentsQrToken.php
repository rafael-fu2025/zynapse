<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicAppointmentsQrToken — per-appointment QR proof-of-booking.
 *
 * Stores only the HMAC hash of the QR token (never the plaintext), so a
 * DB leak can't mint usable appointment proofs. The plaintext token is
 * returned at booking time and via the `issueQr` endpoint so the patient
 * can render (or re-issue) their proof-of-appointment QR. Verification
 * uses the PUBLIC minimum-disclosure endpoint — it reveals only validity,
 * status and scheduled time, never PII.
 */
final class ClinicAppointmentsQrToken extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('clinic_appointments', [
            'qr_token_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'unique'     => true,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('clinic_appointments', 'qr_token_hash');
    }
}
