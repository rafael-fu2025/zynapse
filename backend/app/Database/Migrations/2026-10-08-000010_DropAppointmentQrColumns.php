<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Retire only the appointment proof-of-booking hash and its unique index.
 * Patient QR identity (users.qr_code) and the appointment lifecycle stay intact.
 * Rollback restores the nullable schema, NOT the deleted token hashes.
 */
final class DropAppointmentQrColumns extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('clinic_appointments')) {
            return;
        }
        if ($this->indexExists()) {
            $this->forge->dropKey('clinic_appointments', 'qr_token_hash');
        }
        if ($this->db->fieldExists('qr_token_hash', 'clinic_appointments')) {
            $this->forge->dropColumn('clinic_appointments', 'qr_token_hash');
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('clinic_appointments')) {
            return;
        }
        if (! $this->db->fieldExists('qr_token_hash', 'clinic_appointments')) {
            $this->forge->addColumn('clinic_appointments', [
                'qr_token_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            ]);
        }
        if (! $this->indexExists()) {
            $this->db->query('ALTER TABLE `clinic_appointments` ADD UNIQUE INDEX `qr_token_hash` (`qr_token_hash`)');
        }
    }

    private function indexExists(): bool
    {
        return $this->db->query(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            ['clinic_appointments', 'qr_token_hash'],
        )->getRowArray() !== null;
    }
}
