<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * DevUserSeeder — DEV/STAGING ONLY. Creates `admin@synapse.dev` with
 * password `DevPassw0rd!` and `admin` group membership. The admin
 * derives every permission from the group wildcard (`*`); it holds NO
 * explicit per-user grants (RBAC_SECURITY_REVIEW R5), and any pre-existing
 * ones are removed on run.
 *
 * Refuses to run in production.
 */
final class DevUserSeeder extends Seeder
{
    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('DevUserSeeder must never run in production.');
        }

        $now   = date('Y-m-d H:i:s');
        $email = 'admin@synapse.dev';

        $identity = $this->db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()->getRowArray();

        if ($identity !== null) {
            $userId = (int) $identity['user_id'];
        } else {
            $this->db->table('users')->insert([
                'username'   => 'synapse-admin',
                'status'     => 'active',
                'active'     => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $this->db->insertID();

            $this->db->table('auth_identities')->insert([
                'user_id'    => $userId,
                'type'       => 'email_password',
                'secret'     => $email,
                'secret2'    => password_hash('DevPassw0rd!', PASSWORD_DEFAULT),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Admin group membership.
        $group = $this->db->table('auth_groups')->where('name', 'admin')->get()->getRowArray();
        if ($group !== null) {
            $member = $this->db->table('auth_groups_users')
                ->where(['group_id' => (int) $group['id'], 'user_id' => $userId])
                ->get()->getRowArray();
            if ($member === null) {
                $this->db->table('auth_groups_users')->insert([
                    'group_id'   => (int) $group['id'],
                    'user_id'    => $userId,
                    'created_at' => $now,
                ]);
            }
        }

        // RBAC_SECURITY_REVIEW R5: the admin derives EVERY permission from
        // the `admin` group wildcard (`*`) resolved in PermissionService.
        // Explicit per-user grants are redundant and harmful — they are
        // "sticky" (they survive a group demotion) and, before the R1
        // wildcard-exclusion fix, an explicit `counselling.records.*` row
        // would have defeated that exclusion. Reconcile to the single
        // source of truth: the admin holds NO per-user grants. Deleting
        // rows from this RBAC junction is consistent with the codebase
        // convention (see UserAdminService::replaceGroupsInTxn).
        $this->db->table('user_permissions')->where('user_id', $userId)->delete();
    }
}
