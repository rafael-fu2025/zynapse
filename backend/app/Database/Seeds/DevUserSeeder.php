<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * DevUserSeeder — DEV/STAGING ONLY. Creates the canonical dev accounts
 * with password `DevPassw0rd!` and their group memberships:
 *
 *   - admin@synapse.dev             → superadmin + clinic_admin
 *   - nurse@synapse.dev             → clinic_staff
 *   - report_viewer@synapse.dev     → report_viewer
 *
 * The live e2e suite (frontend/e2e, SYNAPSE_E2E=1) contractually signs
 * in as all three; the nurse/report_viewer accounts were added 2026-09
 * when the suite was run against a fresh dev schema that only had the
 * admin. The admin identity carries the superadmin wildcard (`*`) since
 * the 2026-09 RBAC rework (Platform Owner) PLUS clinic_admin so the
 * unit-admin governance paths are exercisable in dev; it holds NO
 * explicit per-user grants (RBAC_SECURITY_REVIEW R5), and any
 * pre-existing ones are removed on run.
 *
 * Idempotent: existing identities are adopted, not duplicated.
 * Refuses to run in production.
 */
final class DevUserSeeder extends Seeder
{
    private const PASSWORD = 'DevPassw0rd!';

    /** @var array<string, array{username: string, groups: list<string>}> */
    private const ACCOUNTS = [
        'admin@synapse.dev'         => ['username' => 'synapse-admin', 'groups' => ['superadmin', 'clinic_admin']],
        'nurse@synapse.dev'         => ['username' => 'synapse-nurse', 'groups' => ['clinic_staff']],
        'report_viewer@synapse.dev' => ['username' => 'synapse-report-viewer', 'groups' => ['report_viewer']],
    ];

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('DevUserSeeder must never run in production.');
        }

        $now = date('Y-m-d H:i:s');

        foreach (self::ACCOUNTS as $email => $spec) {
            $userId = $this->upsertUser($email, $spec['username'], $now);
            foreach ($spec['groups'] as $groupName) {
                $this->assignGroup((int) $userId, $groupName, $now);
            }
        }

        // The admin identity for RBAC purposes is whichever account maps
        // to id 1 in long-lived dev schemas; reconcile only the seeded
        // admin here.
        $adminId = $this->findUserId('admin@synapse.dev');
        if ($adminId !== null) {
            // RBAC_SECURITY_REVIEW R5: the admin derives EVERY permission
            // from the superadmin group wildcard (`*`) resolved in
            // PermissionService.
            // Explicit per-user grants are redundant and harmful — they are
            // "sticky" (they survive a group demotion) and, before the R1
            // wildcard-exclusion fix, an explicit `counselling.records.*` row
            // would have defeated that exclusion. Reconcile to the single
            // source of truth: the admin holds NO per-user grants. Deleting
            // rows from this RBAC junction is consistent with the codebase
            // convention (see UserAdminService::replaceGroupsInTxn).
            $this->db->table('user_permissions')->where('user_id', $adminId)->delete();
        }
    }

    private function upsertUser(string $email, string $username, string $now): int
    {
        $identity = $this->db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()->getRowArray();

        if ($identity !== null) {
            return (int) $identity['user_id'];
        }

        // `users.username` is UNIQUE and long-lived dev schemas may
        // already hold a demo user with the preferred handle (e.g.
        // `synapse-report-viewer` from SeedDemoUsersSeeder) — fall back
        // to the email local part, then a suffixed variant.
        $candidate = $username;
        if ($this->usernameTaken($candidate)) {
            $candidate = strstr($email, '@', true) ?: $email;
        }
        while ($this->usernameTaken($candidate)) {
            $candidate = $username . '-' . substr((string) random_int(1000, 9999), 0, 4);
        }

        $this->db->table('users')->insert([
            'username'   => $candidate,
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
            'secret2'    => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function usernameTaken(string $username): bool
    {
        return $this->db->table('users')->where('username', $username)->countAllResults() > 0;
    }

    private function assignGroup(int $userId, string $groupName, string $now): void
    {
        $group = $this->db->table('auth_groups')->where('name', $groupName)->get()->getRowArray();
        if ($group === null) {
            return; // PermissionsAndGroupsSeeder has not run; nothing to attach.
        }
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

    private function findUserId(string $email): ?int
    {
        $identity = $this->db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()->getRowArray();
        return $identity !== null ? (int) $identity['user_id'] : null;
    }
}
