<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RbacRoleRework — 2026-09 RBAC rework (Platform Owner / unit admins).
 *
 * Role definitions live in Config\AuthGroups (code-defined); this
 * migration converges EXISTING deployments to the same state. All
 * steps are idempotent so fresh installs (empty tables) and existing
 * deployments converge identically. Safe to re-run.
 *
 *   1. RENAMES (memberships reference auth_groups.id — they survive):
 *        admin                → clinic_admin   ("Clinic Administrator")
 *        clinical_supervisor  → guidance_supervisor ("Guidance Supervisor")
 *   2. RELABELS: facilities_op → "BMG Operator", counsellor →
 *      "Guidance Counsellor".
 *   3. NEW GROUPS: superadmin ("Platform Owner"), guidance_admin,
 *      bmg_admin.
 *   4. NEW PERMISSION CODES: rbac.privileged.manage, api_apps.manage,
 *      api_apps.read (module: platform).
 *   5. SYNC `auth_groups_permissions` to the config matrix — prunes
 *      tightened scopes (kiosk.content.manage off clinic_staff,
 *      units/categories.manage off facilities_op) that the
 *      check-then-skip seeder can never remove. The table is
 *      documentation (runtime resolution reads Config\AuthGroups), but
 *      it must stay honest for auditors.
 *
 * NOTE for operators: after this migration + seeder re-run, former
 * `admin` members are Clinic Administrators (explicit matrix — audit.*,
 * counselling.*, facilities.* are no longer implied). The platform
 * owner is minted with `synapse:promote-superadmin <email>`.
 */
final class RbacRoleRework extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // ---- 1. Renames (idempotent: only if the new name is free) ----
        $renames = [
            'admin'               => ['name' => 'clinic_admin',        'display_name' => 'Clinic Administrator'],
            'clinical_supervisor' => ['name' => 'guidance_supervisor', 'display_name' => 'Guidance Supervisor'],
        ];
        foreach ($renames as $from => $to) {
            $targetExists = $this->db->table('auth_groups')->where('name', $to['name'])->countAllResults() > 0;
            if ($targetExists) {
                continue; // Already converged (or a conflicting row exists — seeder owns it).
            }
            $this->db->table('auth_groups')->where('name', $from)->update([
                'name'         => $to['name'],
                'display_name' => $to['display_name'],
                'updated_at'   => $now,
            ]);
        }

        // ---- 2. Relabels (display only — safe to always run) ----------
        $this->db->table('auth_groups')->where('name', 'facilities_op')->update([
            'display_name' => 'BMG Operator',
            'updated_at'   => $now,
        ]);
        $this->db->table('auth_groups')->where('name', 'counsellor')->update([
            'display_name' => 'Guidance Counsellor',
            'updated_at'   => $now,
        ]);

        // ---- 3. New groups ---------------------------------------------
        $newGroups = [
            'superadmin'     => 'Platform Owner',
            'guidance_admin' => 'Guidance Administrator',
            'bmg_admin'      => 'BMG Administrator',
        ];
        foreach ($newGroups as $name => $display) {
            if ($this->db->table('auth_groups')->where('name', $name)->countAllResults() > 0) {
                continue;
            }
            $this->db->table('auth_groups')->insert([
                'name'         => $name,
                'display_name' => $display,
                'description'  => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }

        // ---- 4. New permission codes -----------------------------------
        $newPermissions = [
            'rbac.privileged.manage' => 'platform',
            'api_apps.manage'        => 'platform',
            'api_apps.read'          => 'platform',
        ];
        foreach ($newPermissions as $code => $module) {
            if ($this->db->table('permissions')->where('code', $code)->countAllResults() > 0) {
                continue;
            }
            $this->db->table('permissions')->insert([
                'code'       => $code,
                'module'     => $module,
                'summary'    => null,
                'created_at' => $now,
            ]);
        }

        // ---- 5. Sync auth_groups_permissions to the config matrix ------
        $this->syncGroupPermissionMatrix($now);
    }

    /**
     * Reconciles the (documentation) auth_groups_permissions junction
     * with Config\AuthGroups::$groupPermissions: inserts missing rows,
     * deletes rows the tightened matrix no longer maps, and ignores
     * codes not present in the `permissions` table (seeder convention).
     */
    private function syncGroupPermissionMatrix(string $now): void
    {
        /** @var \Config\AuthGroups $cfg */
        $cfg = config('Config\\AuthGroups');

        $groupIds = [];
        foreach ($this->db->table('auth_groups')->get()->getResultArray() as $g) {
            $groupIds[(string) $g['name']] = (int) $g['id'];
        }

        $knownCodes = [];
        foreach ($this->db->table('permissions')->get()->getResultArray() as $p) {
            $knownCodes[(string) $p['code']] = true;
        }

        foreach ($cfg->groupPermissions as $groupName => $permCodes) {
            $gid = $groupIds[$groupName] ?? null;
            if ($gid === null) {
                continue; // Fresh install — seeder owns population.
            }

            $desired = [];
            foreach ($permCodes as $code) {
                if (isset($knownCodes[$code])) {
                    $desired[$code] = true;
                }
            }

            $existing = [];
            foreach ($this->db->table('auth_groups_permissions')
                ->where('group_id', $gid)
                ->get()->getResultArray() as $row) {
                $existing[(string) $row['permission_code']] = true;
            }

            $toDelete = array_keys(array_diff_key($existing, $desired));
            if ($toDelete !== []) {
                $this->db->table('auth_groups_permissions')
                    ->where('group_id', $gid)
                    ->whereIn('permission_code', $toDelete)
                    ->delete();
            }

            foreach (array_keys(array_diff_key($desired, $existing)) as $code) {
                $this->db->table('auth_groups_permissions')->insert([
                    'group_id'        => $gid,
                    'permission_code' => $code,
                    'created_at'      => $now,
                ]);
            }
        }
    }

    /**
     * Best-effort reverse: renames and relabels are undone, new groups
     * are removed only when memberless (member data is never destroyed),
     * and the new platform permission codes are deleted. The junction
     * sync is NOT reversed — re-run the previous seeder if the old
     * documentation matrix is needed.
     */
    public function down(): void
    {
        $now = date('Y-m-d H:i:s');

        $renames = [
            'clinic_admin'        => ['name' => 'admin',               'display_name' => 'Administrator'],
            'guidance_supervisor' => ['name' => 'clinical_supervisor', 'display_name' => 'Clinical Supervisor'],
        ];
        foreach ($renames as $from => $to) {
            $targetExists = $this->db->table('auth_groups')->where('name', $to['name'])->countAllResults() > 0;
            if ($targetExists) {
                continue;
            }
            $this->db->table('auth_groups')->where('name', $from)->update([
                'name'         => $to['name'],
                'display_name' => $to['display_name'],
                'updated_at'   => $now,
            ]);
        }

        $this->db->table('auth_groups')->where('name', 'facilities_op')->update([
            'display_name' => 'Facilities Operator',
            'updated_at'   => $now,
        ]);
        $this->db->table('auth_groups')->where('name', 'counsellor')->update([
            'display_name' => 'Counsellor',
            'updated_at'   => $now,
        ]);

        foreach (['guidance_admin', 'bmg_admin', 'superadmin'] as $name) {
            $gid = $this->db->table('auth_groups')->where('name', $name)->get()->getRowArray();
            if ($gid === null) {
                continue;
            }
            $members = $this->db->table('auth_groups_users')->where('group_id', (int) $gid['id'])->countAllResults();
            if ($members === 0) {
                $this->db->table('auth_groups_permissions')->where('group_id', (int) $gid['id'])->delete();
                $this->db->table('auth_groups')->where('id', (int) $gid['id'])->delete();
            }
        }

        foreach (['rbac.privileged.manage', 'api_apps.manage', 'api_apps.read'] as $code) {
            $this->db->table('auth_groups_permissions')->where('permission_code', $code)->delete();
            $this->db->table('permissions')->where('code', $code)->delete();
        }
    }
}
