<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Retire the consolidated users RFID handle and its global unique index.
 * DropLegacyIdentityTables already removed persons/patients_students/
 * patients_employees/patient_identifiers; RemoveKioskSurface removed the
 * clinic_checkins method enum. None needs to be recreated or altered here.
 * Patient qr_code and its uniq_users_qr_code index are intentionally retained.
 * Rollback restores empty nullable handles, NOT deleted RFID values.
 */
final class DropRfidIdentityColumns extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('users')) {
            return;
        }
        if ($this->indexExists()) {
            $this->forge->dropKey('users', 'uniq_users_rfid_tag');
        }
        if ($this->db->fieldExists('rfid_tag', 'users')) {
            $this->forge->dropColumn('users', 'rfid_tag');
        }
    }

    public function down(): void
    {
        if (! $this->db->tableExists('users')) {
            return;
        }
        if (! $this->db->fieldExists('rfid_tag', 'users')) {
            $this->forge->addColumn('users', [
                'rfid_tag' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'qr_code'],
            ]);
        }
        if (! $this->indexExists()) {
            // Mirror UsersIdentityColumns: historical databases with duplicate
            // handles used a plain index. Do not make a rollback fail on them.
            $duplicates = $this->db->query(
                'SELECT `rfid_tag` FROM `users` WHERE `rfid_tag` IS NOT NULL'
                . ' GROUP BY `rfid_tag` HAVING COUNT(*) > 1 LIMIT 1',
            )->getNumRows() > 0;
            $kind = $duplicates ? 'INDEX' : 'UNIQUE INDEX';
            $this->db->query("ALTER TABLE `users` ADD {$kind} `uniq_users_rfid_tag` (`rfid_tag`)");
        }
    }

    private function indexExists(): bool
    {
        return $this->db->query(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            ['users', 'uniq_users_rfid_tag'],
        )->getRowArray() !== null;
    }
}
