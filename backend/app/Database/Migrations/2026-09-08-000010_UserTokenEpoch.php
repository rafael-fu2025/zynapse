<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * UserTokenEpoch — server-side revocation of outstanding access JWTs.
 *
 * `users.token_epoch` is a per-user version number embedded as an `epoch`
 * claim at token issuance and compared on every request by ApiAuthFilter.
 * Logout, password change, and refresh-replay revocation increment it, so
 * every already-issued access token dies immediately instead of living out
 * its TTL (previously up to 15 minutes after logout).
 *
 * Epoch starts at 0; legacy JWTs without an `epoch` claim decode to 0 and
 * keep working, so existing sessions survive the migration.
 */
final class UserTokenEpoch extends Migration
{
    public function up(): void
    {
        $this->db->query(
            'ALTER TABLE `users` '
            . 'ADD COLUMN `token_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 0 '
            . 'AFTER `last_active`'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `users` DROP COLUMN `token_epoch`');
    }
}
