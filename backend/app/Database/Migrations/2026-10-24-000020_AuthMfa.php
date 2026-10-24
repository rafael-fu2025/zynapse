<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AuthMfa — TOTP second factor for LOCAL-identity accounts (2026-10 audit G-1).
 *
 * Scope: the local email/password login path only. MIS-delegated
 * (student/employee-number) logins are owned by the university API and are
 * deliberately never MFA-gated.
 *
 * The TOTP secret is stored AES-256-GCM encrypted under the MFA_KEY env
 * ref (EncryptionService::encryptWith) — key material never lives in the
 * database. `last_used_timestep` enforces single-use codes (replay
 * rejection). One row per user; `enabled_at NULL` = staged enrollment
 * (setup started, not yet confirmed by a valid code).
 */
final class AuthMfa extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'user_id'           => ['type' => 'BIGINT', 'unsigned' => true],
            'secret_cipher'     => ['type' => 'VARBINARY', 'constraint' => 255, 'null' => false],
            'secret_nonce'      => ['type' => 'VARBINARY', 'constraint' => 12, 'null' => false],
            'enabled_at'        => ['type' => 'DATETIME', 'null' => true],
            'last_used_timestep' => ['type' => 'BIGINT', 'null' => true],
            'created_at'        => ['type' => 'DATETIME', 'null' => false],
            'updated_at'        => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('user_id');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('auth_mfa');
    }

    public function down(): void
    {
        $this->forge->dropTable('auth_mfa', true);
    }
}
