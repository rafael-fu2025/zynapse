<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/** Database-level identity integrity and list-order support for Admin Users. */
final class AdminUsersHardening extends Migration
{
    public function up(): void
    {
        $duplicate = $this->db->query(
            "SELECT type, LOWER(secret) AS normalized_secret, COUNT(*) AS aggregate
             FROM auth_identities
             GROUP BY type, LOWER(secret)
             HAVING COUNT(*) > 1
             LIMIT 1",
        )->getRowArray();

        if ($duplicate !== null) {
            throw new RuntimeException('Duplicate normalized authentication identities must be resolved before migration.');
        }

        $this->db->query("UPDATE auth_identities SET secret = LOWER(TRIM(secret)) WHERE type = 'email_password'");
        $this->db->query('CREATE UNIQUE INDEX ux_auth_identities_type_secret ON auth_identities (type, secret)');
        $this->db->query('CREATE INDEX ix_users_directory ON users (deleted_at, created_at, id)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX ix_users_directory ON users');
        $this->db->query('DROP INDEX ux_auth_identities_type_secret ON auth_identities');
    }
}
