<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * DevUserSeeder — DEV/STAGING ONLY. Creates the canonical dev account
 * roster — one account per role in Config\AuthGroups' 12-role catalog,
 * each holding exactly its own group. The shared password comes from
 * `SYNAPSE_DEV_PASSWORD`; when unset, a random per-run password is
 * generated and printed once (no credential literal lives in the repo).
 *
 * The live e2e suite (frontend/e2e, SYNAPSE_E2E=1) contractually signs
 * in as the superadmin, clinic_staff and report_viewer accounts; the
 * nurse/report_viewer accounts were added 2026-09 when the suite was
 * run against a fresh dev schema that only had the admin.
 *
 * RBAC notes (2026-09 rework; roster split 2026-09-26):
 *   - admin@synapse.dev is the Platform Owner: superadmin ONLY. Its
 *     every permission derives from the superadmin wildcard (`*`)
 *     resolved in PermissionService. It holds NO explicit per-user
 *     grants (RBAC_SECURITY_REVIEW R5; any pre-existing ones are
 *     removed on run) and NO clinic_admin membership — unit-admin
 *     governance paths are exercisable through the dedicated
 *     clinic_admin account instead.
 *   - Every other account is single-group, so privileged-role
 *     separation of duties (clinic_admin / guidance_admin / bmg_admin)
 *     is exercisable in dev without stacking roles.
 *
 * Idempotent AND self-converging: existing identities are adopted, not
 * duplicated, and group memberships are reconciled to the spec below —
 * extra groups the roster no longer maps (e.g. clinic_admin left on
 * the former dual-group admin) are pruned on run. Refuses to run in
 * production.
 */
final class DevUserSeeder extends Seeder
{
    private static ?string $generatedPassword = null;

    /**
     * Shared dev password: SYNAPSE_DEV_PASSWORD when provided, otherwise a
     * random per-run secret generated once and printed below so the value
     * never needs to live in the repo.
     */
    private function devPassword(): string
    {
        $pw = getenv('SYNAPSE_DEV_PASSWORD');
        if ($pw !== false && $pw !== '') {
            return $pw;
        }
        return self::$generatedPassword ??= 'dev-' . bin2hex(random_bytes(16));
    }

    /** @var array<string, array{username: string, groups: list<string>}> */
    private const ACCOUNTS = [
        'admin@synapse.dev'          => ['username' => 'synapse-admin', 'groups' => ['superadmin']],
        'clinic_admin@synapse.dev'   => ['username' => 'synapse-clinic-admin', 'groups' => ['clinic_admin']],
        'nurse@synapse.dev'          => ['username' => 'synapse-nurse', 'groups' => ['clinic_staff']],
        'guidance_admin@synapse.dev' => ['username' => 'synapse-guidance-admin', 'groups' => ['guidance_admin']],
        'supervisor@synapse.dev'     => ['username' => 'synapse-supervisor', 'groups' => ['guidance_supervisor']],
        'counsellor@synapse.dev'     => ['username' => 'synapse-counsellor', 'groups' => ['counsellor']],
        'bmg_admin@synapse.dev'      => ['username' => 'synapse-bmg-admin', 'groups' => ['bmg_admin']],
        'bmg_op@synapse.dev'         => ['username' => 'synapse-bmg-op', 'groups' => ['facilities_op']],
        'audit@synapse.dev'          => ['username' => 'synapse-audit', 'groups' => ['audit_reader']],
        'report_viewer@synapse.dev'  => ['username' => 'synapse-report-viewer', 'groups' => ['report_viewer']],
        'student@synapse.dev'        => ['username' => 'synapse-student', 'groups' => ['student']],
        'employee@synapse.dev'       => ['username' => 'synapse-employee', 'groups' => ['employee']],
    ];

    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('DevUserSeeder must never run in production.');
        }

        $now = date('Y-m-d H:i:s');
        $password = $this->devPassword();

        foreach (self::ACCOUNTS as $email => $spec) {
            $userId = $this->upsertUser($email, $spec['username'], $now, $password);
            $this->reconcileGroups((int) $userId, $spec['groups'], $now);
        }

        if (self::$generatedPassword !== null) {
            CLI::write('SYNAPSE_DEV_PASSWORD is not set — generated a per-run dev password:', 'yellow');
            CLI::write('  ' . self::$generatedPassword, 'green');
            CLI::write('Set SYNAPSE_DEV_PASSWORD in your .env to pin it across runs.', 'dark_gray');
        }

        // The admin identity for RBAC purposes is whichever account maps
        // to id 1 in long-lived dev schemas; reconcile only the seeded
        // superadmin here.
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

    private function upsertUser(string $email, string $username, string $now, string $password): int
    {
        $identity = $this->db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()->getRowArray();

        if ($identity !== null) {
            // Adopt the identity, but converge its password to the current
            // dev password so a rotated SYNAPSE_DEV_PASSWORD (or a fresh
            // generated one) actually takes effect on re-run.
            if (! password_verify($password, (string) $identity['secret2'])) {
                $this->db->table('auth_identities')
                    ->where('id', (int) $identity['id'])
                    ->update([
                        'secret2'    => password_hash($password, PASSWORD_DEFAULT),
                        'updated_at' => $now,
                    ]);
            }
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
            'secret2'    => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    private function usernameTaken(string $username): bool
    {
        return $this->db->table('users')->where('username', $username)->countAllResults() > 0;
    }

    /**
     * Insert missing group memberships, then prune memberships the
     * roster no longer maps — self-converging, mirroring the junction
     * sync in PermissionsAndGroupsSeeder. Rows pointing at group ids
     * that no longer exist in `auth_groups` are left alone: there is
     * no name to reason about intent with.
     */
    private function reconcileGroups(int $userId, array $groupNames, string $now): void
    {
        $specIds = [];
        foreach ($groupNames as $groupName) {
            $groupId = $this->assignGroup($userId, $groupName, $now);
            if ($groupId !== null) {
                $specIds[$groupId] = true;
            }
        }

        $knownNames = [];
        foreach ($this->db->table('auth_groups')->get()->getResultArray() as $g) {
            $knownNames[(int) $g['id']] = (string) $g['name'];
        }

        $memberships = $this->db->table('auth_groups_users')
            ->where('user_id', $userId)
            ->get()->getResultArray();
        foreach ($memberships as $membership) {
            $groupId = (int) $membership['group_id'];
            if (isset($knownNames[$groupId]) && ! isset($specIds[$groupId])) {
                $this->db->table('auth_groups_users')
                    ->where(['group_id' => $groupId, 'user_id' => $userId])
                    ->delete();
            }
        }
    }

    private function assignGroup(int $userId, string $groupName, string $now): ?int
    {
        $group = $this->db->table('auth_groups')->where('name', $groupName)->get()->getRowArray();
        if ($group === null) {
            return null; // PermissionsAndGroupsSeeder has not run; nothing to attach.
        }
        $groupId = (int) $group['id'];
        $member = $this->db->table('auth_groups_users')
            ->where(['group_id' => $groupId, 'user_id' => $userId])
            ->get()->getRowArray();
        if ($member === null) {
            $this->db->table('auth_groups_users')->insert([
                'group_id'   => $groupId,
                'user_id'    => $userId,
                'created_at' => $now,
            ]);
        }
        return $groupId;
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
