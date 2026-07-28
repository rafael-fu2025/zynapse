<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * SeedDemoUsersSeeder — DEV/STAGING ONLY.
 *
 * Creates one pre-hashed login per non-admin role so demos, browser
 * walkthroughs, and integration tests have realistic RBAC fixtures
 * without round-tripping through /admin/users.
 *
 *   clinic_staff@synapse.dev   DevPassw0rd!  → group: clinic_staff
 *   counsellor@synapse.dev     DevPassw0rd!  → group: counsellor
 *   facilities_op@synapse.dev  DevPassw0rd!  → group: facilities_op
 *   audit_reader@synapse.dev   DevPassw0rd!  → group: audit_reader
 *
 * Refuses to run in production. Idempotent — re-running is safe (skips
 * users whose email-password identity already exists).
 *
 * Companion to DevUserSeeder (admin). Run AFTER
 * PermissionsAndGroupsSeeder so the groups exist.
 */
final class SeedDemoUsersSeeder extends Seeder
{
    /** @var array<int, array{email: string, username: string, group: string}> */
    private const USERS = [
        ['email' => 'clinic_staff@synapse.dev',  'username' => 'synapse-clinic-staff',  'group' => 'clinic_staff'],
        ['email' => 'counsellor@synapse.dev',    'username' => 'synapse-counsellor',    'group' => 'counsellor'],
        ['email' => 'facilities_op@synapse.dev', 'username' => 'synapse-facilities-op', 'group' => 'facilities_op'],
        ['email' => 'audit_reader@synapse.dev',  'username' => 'synapse-audit-reader',  'group' => 'audit_reader'],
    ];

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('SeedDemoUsersSeeder must never run in production.');
        }

        $now    = date('Y-m-d H:i:s');
        $hash   = password_hash('DevPassw0rd!', PASSWORD_DEFAULT);
        $groups = [];
        foreach ($this->db->table('auth_groups')->get()->getResultArray() as $g) {
            $groups[(string) $g['name']] = (int) $g['id'];
        }

        foreach (self::USERS as $u) {
            $email = $u['email'];
            $group = $u['group'];

            // Reuse an existing user row if the identity already exists.
            $identity = $this->db->table('auth_identities')
                ->where('type', 'email_password')
                ->where('secret', $email)
                ->get()->getRowArray();

            if ($identity !== null) {
                $userId = (int) $identity['user_id'];
                // Keep the password aligned with the documented dev fixture
                // in case it was rotated or reset out-of-band.
                $this->db->table('auth_identities')
                    ->where('user_id', $userId)
                    ->where('type', 'email_password')
                    ->update(['secret2' => $hash, 'updated_at' => $now]);
                // Reactivate the user (smoke tests deactivate probe users).
                $this->db->table('users')->where('id', $userId)->update([
                    'status'     => 'active',
                    'active'     => 1,
                    'updated_at' => $now,
                ]);
            } else {
                $this->db->table('users')->insert([
                    'username'   => $u['username'],
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
                    'secret2'    => $hash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Group membership.
            $gid = $groups[$group] ?? null;
            if ($gid !== null) {
                $member = $this->db->table('auth_groups_users')
                    ->where(['group_id' => $gid, 'user_id' => $userId])
                    ->get()->getRowArray();
                if ($member === null) {
                    $this->db->table('auth_groups_users')->insert([
                        'group_id'   => $gid,
                        'user_id'    => $userId,
                        'created_at' => $now,
                    ]);
                }
            }
        }
    }
}
