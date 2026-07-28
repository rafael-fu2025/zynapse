<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RefreshTokens — opaque-rotating refresh tokens for the JWT flow.
 *
 * Stores ONLY HMAC-SHA-256 hashes; plaintext tokens never touch storage.
 * Reuse = automatic revocation (Phase 2 worker detects twin rows).
 */
final class RefreshTokens extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'         => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'token_hash'      => ['type' => 'CHAR', 'constraint' => 64, 'null' => false], // HMAC-SHA256 hex
            'replaced_by_hash'=> ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'user_agent_hash' => ['type' => 'CHAR', 'constraint' => 16, 'null' => true],
            'ip_subnet'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => false],
            'expires_at'      => ['type' => 'DATETIME', 'null' => false],
            'revoked_at'      => ['type' => 'DATETIME', 'null' => true],
            'last_used_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey(['user_id', 'revoked_at']);
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'CASCADE');
        $this->forge->createTable('auth_refresh_tokens');
    }

    public function down(): void
    {
        $this->forge->dropTable('auth_refresh_tokens', true);
    }
}