<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class RefreshSessionDeadline extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('auth_refresh_tokens', [
            'session_expires_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        // No trustworthy immutable login deadline exists for legacy families.
        // Revoke them instead of guessing; never extend their original expiry.
        $this->db->table('auth_refresh_tokens')->where('revoked_at', null)
            ->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('auth_refresh_tokens', 'session_expires_at');
        // Deliberately do not resurrect revoked sessions.
    }
}
