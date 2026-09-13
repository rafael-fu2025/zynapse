<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use Config\AuthGroups;

/**
 * PermissionsAndGroupsSeeder — Phase 1 bootstrap.
 *
 * Idempotent AND self-converging: re-running is safe. Groups and
 * permissions are check-then-insert; the auth_groups_permissions
 * junction is SYNCED to Config\AuthGroups::$groupPermissions (missing
 * rows inserted, rows the tightened matrix no longer maps are pruned)
 * so fresh installs and existing deployments converge to the same state
 * (mirrors migration 2026-09-12-000010_RbacRoleRework).
 *
 * Populates:
 *   1. `auth_groups` from `Config\AuthGroups::$groups`.
 *   2. `permissions` from every code referenced across modules + a default set.
 *   3. `auth_groups_permissions` mapping from `Config\AuthGroups::$groupPermissions`.
 */
final class PermissionsAndGroupsSeeder extends Seeder
{
    /** @var array<string, string> */
    private array $basePermissions = [
        'rbac.read'                                  => 'core',
        'rbac.manage'                                => 'core',
        // 2026-09 RBAC rework: grant/revoke of privileged roles
        // (superadmin, clinic_admin, guidance_admin, bmg_admin).
        'rbac.privileged.manage'                     => 'platform',
        // 2026-09 external API apps (D4): superadmin-only management
        // and read of the developer-portal surface.
        'api_apps.manage'                            => 'platform',
        'api_apps.read'                              => 'platform',
        'portal.appointments.read'                   => 'core',
        'portal.appointments.manage'                 => 'core',
        'portal.queue.read'                          => 'core',
        'kiosk.content.manage'                       => 'core',

        // Clinic
        'clinic.encounters.create'                   => 'clinic',
        'clinic.encounters.read'                     => 'clinic',
        'clinic.encounters.write'                    => 'clinic',
        'clinic.encounters.soft_delete'              => 'clinic',
        'clinic.inventory.read'                      => 'clinic',
        'clinic.inventory.write'                     => 'clinic',
        'clinic.inventory.delete'                    => 'clinic',
        'clinic.appointments.read'                   => 'clinic',
        'clinic.appointments.write'                  => 'clinic',
        'clinic.patients.read'                       => 'clinic',
        'clinic.patients.write'                      => 'clinic',
        'clinic.reorders.read'                       => 'clinic',
        'clinic.reorders.manage'                     => 'clinic',
        'clinic.queue.read'                          => 'clinic',
        'clinic.queue.manage'                        => 'clinic',
        'clinic.checkin.record'                      => 'clinic',
        'clinic.checkin.read'                        => 'clinic',
        'kiosk.checkin.submit'                       => 'core',
        'clinic.treatments.read'                     => 'clinic',
        'clinic.triage.use'                          => 'clinic',
        'clinic.inventory.forecast'                  => 'clinic',
        'clinic.departments.manage'                  => 'clinic',
        'clinic.schedules.manage'                    => 'clinic',

        // Notifications (in-app)
        'notifications.read'                         => 'core',

        // Counselling
        'counselling.records.create'                 => 'counselling',
        'counselling.records.read'                   => 'counselling',
        'counselling.records.write'                  => 'counselling',
        // Oversight break-glass: satisfies the policy's record gate for
        // sessions the holder does not own. Granted ONLY to
        // guidance_supervisor (+ superadmin via the wildcard) — every
        // counsellor holding records.write had made the per-record check
        // a no-op (audit 2026-09-05, F2).
        'counselling.records.read_any'               => 'counselling',
        'counselling.records.soft_delete'            => 'counselling',
        'counselling.schedule.read'                  => 'counselling',
        'counselling.schedule.manage'                => 'counselling',
        'counselling.schedule.team_manage'           => 'counselling',
        'counselling.queue.read'                     => 'counselling',
        'counselling.queue.manage'                   => 'counselling',
        // 2026-09 guidance parity (Phase A): kiosk content ownership.
        'counselling.announcements.manage'           => 'counselling',
        'counselling.services.manage'                => 'counselling',
        // 2026-09 guidance parity (Phase B): surveys engine.
        'counselling.surveys.manage'                 => 'counselling',
        'counselling.responses.read'                 => 'counselling',
        'counselling.responses.read_any'             => 'counselling',

        // Facilities (BMG)
        'facilities.units.read'                      => 'facilities',
        'facilities.units.manage'                    => 'facilities',
        'facilities.bmg.transition'                  => 'facilities',
        'facilities.bmg.record_output'               => 'facilities',
        'facilities.bmg.logs.read'                   => 'facilities',
        'facilities.bmg.logs.record'                 => 'facilities',
        'facilities.categories.manage'               => 'facilities',
        'facilities.bmg.io.record'                   => 'facilities',

        // Referrals
        'referrals.create'                           => 'referrals',
        'referrals.read'                             => 'referrals',
        'referrals.acknowledge'                      => 'referrals',
        'referrals.review'                           => 'referrals',
        'referrals.close'                            => 'referrals',
        'referrals.issue_qr'                         => 'referrals',

        // Audit
        'audit.read'                                 => 'audit',
        'audit.export'                               => 'audit',

        // Reports (read-only cross-module analytics)
        'reports.read'                               => 'reports',
        'reports.export'                             => 'reports',
        'reports.configure'                          => 'reports',

        // Employee Portal (Phase 11). Self-scope: every authenticated
        // user can see their own employee row, their own clinic visit
        // history, and (via the existing `referrals.create` perm) refer
        // students to counselling. The permission only gates the
        // surface, NOT the record — record-scope is enforced by the
        // service resolving `CurrentUser::id()` to the caller's own
        // employee_number. Granted to every group so a freshly-sealed
        // user (e.g. `synapse-counsellor`) can still open "My portal".
        'employee.portal.read'                       => 'core',

        // Student Portal (Phase 13). Gates the `/me/student-*` self-
        // scope surface. Only the `student` group has this perm today;
        // record-scope is enforced via `patients_students.user_id`.
        // The full student self-service flow (book appointment, QR
        // check-in) is still deferred; the portal is read-only for now.
        'student.portal.read'                        => 'core',
    ];

    public function run(): void
    {
        /** @var \Config\AuthGroups $cfg */
        $cfg = config('Config\\AuthGroups');
        $now = date('Y-m-d H:i:s');

        // 1. Groups.
        foreach ($cfg->groups as $code => $name) {
            $existing = $this->db->table('auth_groups')->where('name', $code)->get()->getRowArray();
            if ($existing !== null) {
                continue;
            }
            $this->db->table('auth_groups')->insert([
                'name'         => $code,
                'display_name' => $name,
                'description'  => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        // 2. Permissions.
        foreach ($this->basePermissions as $code => $module) {
            $exists = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            if ($exists !== null) {
                continue;
            }
            $this->db->table('permissions')->insert([
                'code'       => $code,
                'module'     => $module,
                'summary'    => null,
                'created_at' => $now,
            ]);
        }

        // 3. Group <-> Permission.
        $groupsByName = [];
        foreach ($this->db->table('auth_groups')->get()->getResultArray() as $g) {
            $groupsByName[$g['name']] = (int) $g['id'];
        }

        foreach ($cfg->groupPermissions as $groupCode => $permCodes) {
            $gid = $groupsByName[$groupCode] ?? null;
            if ($gid === null) {
                continue;
            }

            $desired = [];
            foreach ($permCodes as $perm) {
                if (! isset($this->basePermissions[$perm])) {
                    continue;
                }
                $desired[$perm] = true;
            }

            $existing = [];
            foreach ($this->db->table('auth_groups_permissions')
                ->where('group_id', $gid)
                ->get()->getResultArray() as $row) {
                $existing[(string) $row['permission_code']] = true;
            }

            // Prune rows the tightened matrix no longer maps (e.g.
            // kiosk.content.manage off clinic_staff, units/categories
            // manage off facilities_op — 2026-09 RBAC rework).
            $toDelete = array_keys(array_diff_key($existing, $desired));
            if ($toDelete !== []) {
                $this->db->table('auth_groups_permissions')
                    ->where('group_id', $gid)
                    ->whereIn('permission_code', $toDelete)
                    ->delete();
            }

            foreach (array_keys(array_diff_key($desired, $existing)) as $perm) {
                $this->db->table('auth_groups_permissions')->insert([
                    'group_id'        => $gid,
                    'permission_code' => $perm,
                    'created_at'      => $now,
                ]);
            }
        }
    }
}
