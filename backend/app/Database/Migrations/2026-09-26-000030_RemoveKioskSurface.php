<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RemoveKioskSurface — teardown of the retired check-in kiosk / lobby
 * queue-display feature (2026-09-26).
 *
 * Removes the schema half of the kiosk + public queue-display surfaces.
 * The application half (CheckinController/CheckinService, the kiosk
 * orchestrator, KioskSettings/KioskMedia services + controllers, the
 * public `GET clinic/queue/state` feed, and the web/mobile pages) was
 * deleted in the same change.
 *
 *   1. Drop `clinic_checkins`, `kiosk_media_assets`, `kiosk_settings`.
 *      The clinic QUEUE (clinic_queue_entries) STAYS — it is fed by
 *      appointment check-ins, referral handoff and bulk import.
 *   2. Delete the `kiosk.checkin.submit`, `kiosk.content.manage`,
 *      `clinic.checkin.record` and `clinic.checkin.read` permission
 *      codes with their `auth_groups_permissions` mappings.
 *   3. Delete the `kiosk` group with its memberships. The applied
 *      migrations are deliberately NOT rewritten — history stays
 *      append-only.
 *   4. Delete the dev kiosk machine account (`kiosk@synapse.dev`),
 *      if present — it never exists outside seeded dev/staging.
 *
 * Idempotent: re-runs are no-ops.
 */
final class RemoveKioskSurface extends Migration
{
    /** Tables holding only kiosk/check-in data, children first. */
    private const KIOSK_TABLES = ['clinic_checkins', 'kiosk_media_assets', 'kiosk_settings'];

    /** Permission codes minted for the kiosk surface. */
    private const KIOSK_PERMISSIONS = [
        'kiosk.checkin.submit',
        'kiosk.content.manage',
        'clinic.checkin.record',
        'clinic.checkin.read',
    ];

    private const KIOSK_GROUP = 'kiosk';

    /** Dev machine account minted by the (now trimmed) DevUserSeeder. */
    private const DEV_KIOSK_EMAIL = 'kiosk@synapse.dev';

    public function up(): void
    {
        // ---- 1. Kiosk schema -------------------------------------------
        foreach (self::KIOSK_TABLES as $table) {
            $this->forge->dropTable($table, true);
        }

        // ---- 2. Permission codes ---------------------------------------
        if ($this->db->tableExists('auth_groups_permissions')) {
            $this->db->table('auth_groups_permissions')
                ->whereIn('permission_code', self::KIOSK_PERMISSIONS)
                ->delete();
        }
        if ($this->db->tableExists('permissions')) {
            $this->db->table('permissions')
                ->whereIn('code', self::KIOSK_PERMISSIONS)
                ->delete();
        }

        // ---- 3. The kiosk group + its memberships -----------------------
        if (! $this->db->tableExists('auth_groups')) {
            return;
        }
        $group = $this->db->table('auth_groups')
            ->where('name', self::KIOSK_GROUP)
            ->get()->getRowArray();

        if ($group === null) {
            return;
        }
        $groupId = (int) $group['id'];

        if ($this->db->tableExists('auth_groups_users')) {
            $this->db->table('auth_groups_users')->where('group_id', $groupId)->delete();
        }
        if ($this->db->tableExists('auth_groups_permissions')) {
            $this->db->table('auth_groups_permissions')->where('group_id', $groupId)->delete();
        }
        $this->db->table('auth_groups')->where('id', $groupId)->delete();

        // ---- 4. The dev kiosk machine account ---------------------------
        if (! $this->db->tableExists('auth_identities') || ! $this->db->tableExists('users')) {
            return;
        }
        $identity = $this->db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', self::DEV_KIOSK_EMAIL)
            ->get()->getRowArray();

        if ($identity === null) {
            return;
        }
        $userId = (int) $identity['user_id'];
        $this->db->table('auth_identities')->where('user_id', $userId)->delete();
        if ($this->db->tableExists('auth_groups_users')) {
            $this->db->table('auth_groups_users')->where('user_id', $userId)->delete();
        }
        $this->db->table('users')->where('id', $userId)->delete();
    }

    /**
     * Not reversible: the feature's application code and its seeders no
     * longer exist, so recreating the tables would produce an empty,
     * unreachable schema. A rollback would need the deleted module back.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
}
