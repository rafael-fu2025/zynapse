<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * DevUserSeeder — DEV/STAGING ONLY. Creates `admin@synapse.dev` with
 * password `DevPassw0rd!`, admin group membership, and explicit
 * per-user grants for every seeded permission code.
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

        // Explicit per-user grants for every permission code.
        foreach ($this->db->table('permissions')->get()->getResultArray() as $perm) {
            $exists = $this->db->table('user_permissions')
                ->where(['user_id' => $userId, 'permission_code' => $perm['code']])
                ->get()->getRowArray();
            if ($exists === null) {
                $this->db->table('user_permissions')->insert([
                    'user_id'         => $userId,
                    'permission_code' => $perm['code'],
                    'created_at'      => $now,
                ]);
            }
        }
    }
}
