<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CounsellingKeyVersions — key-rotation lookup table (Phase 6).
 *
 * Maps `key_version` -> `key_ref`, where `key_ref` is the NAME of the
 * environment variable holding the 64-hex-char key. Key MATERIAL is
 * never stored in `synapse_zcode` (directive: no keys in source or DB).
 *
 * Also widens the note/referral cipher columns: AES-256-GCM output is
 * plaintext-length + 16-byte tag, and the service caps plaintext at
 * 16 KiB — VARBINARY(8192) silently truncated larger notes under
 * non-strict clients and hard-failed under strict mode.
 */
final class CounsellingKeyVersions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'version'      => ['type' => 'TINYINT', 'unsigned' => true, 'null' => false],
            'key_ref'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
            'status'       => ['type' => 'ENUM', 'constraint' => ['active', 'retired'], 'null' => false, 'default' => 'active'],
            'activated_at' => ['type' => 'DATETIME', 'null' => false],
            'retired_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('version');
        $this->forge->addUniqueKey('key_ref');
        $this->forge->createTable('counselling_key_versions');

        // Seed version 1 -> COUNSELLING_KEY (the current active env ref).
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('counselling_key_versions')->insert([
            'version'      => 1,
            'key_ref'      => 'COUNSELLING_KEY',
            'status'       => 'active',
            'activated_at' => $now,
            'created_at'   => $now,
        ]);

        // 16 KiB plaintext + 16-byte GCM tag = 16400 bytes max.
        $this->forge->modifyColumn('counselling_notes', [
            'notes_cipher' => ['type' => 'VARBINARY', 'constraint' => 16400, 'null' => false],
        ]);
        $this->forge->modifyColumn('referral_referrals', [
            'notes_cipher' => ['type' => 'VARBINARY', 'constraint' => 16400, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('referral_referrals', [
            'notes_cipher' => ['type' => 'VARBINARY', 'constraint' => 8192, 'null' => true],
        ]);
        $this->forge->modifyColumn('counselling_notes', [
            'notes_cipher' => ['type' => 'VARBINARY', 'constraint' => 8192, 'null' => false],
        ]);
        $this->forge->dropTable('counselling_key_versions', true);
    }
}
