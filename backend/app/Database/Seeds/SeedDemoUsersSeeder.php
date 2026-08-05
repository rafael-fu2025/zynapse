<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * SeedDemoUsersSeeder — DEV/STAGING ONLY.
 *
 * Creates one named demo login per OPERATIONAL STAFF role so demos,
 * browser walkthroughs, and integration tests have realistic RBAC
 * fixtures without round-tripping through /admin/users.
 *
 * Every non-admin account uses the canonical personal email format
 * `firstname.lastname@foundationu.edu.ph` and the shared demo password
 * `DevPassw0rd!` — the same convention PatientRegistrySeeder applies to
 * patient accounts. Only the admin keeps `admin@synapse.dev`
 * (DevUserSeeder).
 *
 * Patients (students/employees) get their accounts from
 * PatientRegistrySeeder; this seeder covers the staff roles that are
 * not patient profiles.
 *
 * Refuses to run in production. Idempotent — re-running is safe (upserts
 * by email). Run AFTER PermissionsAndGroupsSeeder so the groups exist.
 */
final class SeedDemoUsersSeeder extends Seeder
{
    private const DEMO_PASSWORD = 'DevPassw0rd!';

    /**
     * @var array<int, array{first: string, last: string, username: string, group: string}>
     */
    private const USERS = [
        ['first' => 'Nina',  'last' => 'Reyes',      'username' => 'synapse-clinic-staff',        'group' => 'clinic_staff'],
        ['first' => 'Liza',  'last' => 'Santos',     'username' => 'synapse-counsellor',          'group' => 'counsellor'],
        ['first' => 'Mark',  'last' => 'Villanueva', 'username' => 'synapse-facilities-op',       'group' => 'facilities_op'],
        ['first' => 'Tina',  'last' => 'Aquino',     'username' => 'synapse-audit-reader',        'group' => 'audit_reader'],
        // Phase 19 (ACTOR_ACCESS_ANALYSIS): read-only analytics role.
        ['first' => 'Paul',  'last' => 'Mendoza',    'username' => 'synapse-report-viewer',       'group' => 'report_viewer'],
        // RBAC_SECURITY_REVIEW R4: clinical oversight / break-glass role.
        ['first' => 'Ana',   'last' => 'Garcia',     'username' => 'synapse-clinical-supervisor', 'group' => 'clinical_supervisor'],
    ];

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('SeedDemoUsersSeeder must never run in production.');
        }

        $now    = date('Y-m-d H:i:s');
        $hash   = password_hash(self::DEMO_PASSWORD, PASSWORD_DEFAULT);
        $groups = [];
        foreach ($this->db->table('auth_groups')->get()->getResultArray() as $g) {
            $groups[(string) $g['name']] = (int) $g['id'];
        }

        foreach (self::USERS as $u) {
            $email = strtolower($u['first']) . '.' . strtolower($u['last']) . '@foundationu.edu.ph';
            $group = $u['group'];

            // Reuse an existing user row if the identity already exists.
            $identity = $this->db->table('auth_identities')
                ->where('type', 'email_password')
                ->where('secret', $email)
                ->get()->getRowArray();

            if ($identity !== null) {
                $userId = (int) $identity['user_id'];
                // Keep the password aligned with the documented dev fixture.
                $this->db->table('auth_identities')
                    ->where('user_id', $userId)
                    ->where('type', 'email_password')
                    ->update(['secret2' => $hash, 'force_reset' => 0, 'updated_at' => $now]);
                // Reactivate the user (smoke tests deactivate probe users).
                $this->db->table('users')->where('id', $userId)->update([
                    'status'     => 'active',
                    'active'     => 1,
                    'updated_at' => $now,
                ]);
            } else {
                $this->db->table('users')->insert([
                    'username'   => $u['username'],
                    'first_name' => $u['first'],
                    'last_name'  => $u['last'],
                    'status'     => 'active',
                    'active'     => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $userId = (int) $this->db->insertID();

                $this->db->table('auth_identities')->insert([
                    'user_id'     => $userId,
                    'type'        => 'email_password',
                    'secret'      => $email,
                    'secret2'     => $hash,
                    'force_reset' => 0,
                    'created_at'  => $now,
                    'updated_at'  => $now,
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
