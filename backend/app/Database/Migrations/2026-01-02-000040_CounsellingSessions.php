<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CounsellingSessions — strictly isolated from clinic_encounters.
 *
 * The `notes_cipher`, `notes_nonce`, `notes_key_version` columns hold
 * AES-256-GCM material for the free-text session notes. NEVER used in
 * WHERE / ORDER BY. The `patient_school_id` column is the only place
 * cross-module references can point into this table — and even then,
 * only through the `referral_referrals` bridge.
 */
final class CounsellingSessions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'patient_school_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => false],
            'counsellor_user_id'=> ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'started_at'        => ['type' => 'DATETIME', 'null' => false],
            'ended_at'          => ['type' => 'DATETIME', 'null' => true],
            'archived_at'       => ['type' => 'DATETIME', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('patient_school_id');
        $this->forge->addKey('counsellor_user_id');
        $this->forge->addForeignKey('counsellor_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('counselling_sessions');

        // Notes — encrypted. Column order is enforced by the service via
        // a single insert; nothing else writes here.
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'session_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'notes_cipher'    => ['type' => 'VARBINARY', 'constraint' => 8192, 'null' => false],
            'notes_nonce'     => ['type' => 'BINARY',    'constraint' => 12,    'null' => false],
            'notes_key_version'=> ['type' => 'TINYINT', 'unsigned' => true, 'null' => false],
            'created_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'created_at'      => ['type' => 'DATETIME', 'null' => false],
            'updated_at'      => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('session_id');
        $this->forge->addForeignKey('session_id', 'counselling_sessions', 'id', '', 'CASCADE');
        $this->forge->addForeignKey('created_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('counselling_notes');
    }

    public function down(): void
    {
        $this->forge->dropTable('counselling_notes', true);
        $this->forge->dropTable('counselling_sessions', true);
    }
}