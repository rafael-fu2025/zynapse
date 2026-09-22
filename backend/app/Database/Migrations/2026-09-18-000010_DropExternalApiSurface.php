<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * DropExternalApiSurface — teardown of the retired Developer portal
 * (2026-09-18).
 *
 * Removes the schema half of the external-API / developer-portal
 * feature. The application half (Modules\External, ApiKeyService,
 * ApiKeyAuthFilter, ApiKeyContext, ExternalApps config, the frontend
 * /developer pages, and SandboxTenantSeeder) was deleted in the same
 * change.
 *
 *   1. Drop `api_keys` (FK → api_apps, CASCADE) then `api_apps`.
 *   2. Delete the `api_apps.manage` / `api_apps.read` permission codes
 *      and their `auth_groups_permissions` mappings. The applied
 *      migration 2026-09-12-000010_RbacRoleRework is deliberately NOT
 *      rewritten — migration history stays append-only.
 *   3. Delete the `sandbox` tenant and the synthetic rows that host it
 *      (all `SBX-…` / `sandbox-staff` demo data written by the deleted
 *      SandboxTenantSeeder). FK-safe order: children before the staff
 *      user they RESTRICT-reference.
 *
 * NOT touched, by design:
 *   - `audit_events` is append-only and hash-chained
 *     (`commit_hash = SHA-256(prev_hash ‖ payload_json)`); deleting rows
 *     would break `synapse:audit-verify`. `actor_user_id` carries no FK
 *     (index only), so removing the synthetic staff user leaves at most
 *     a dangling id without corrupting the chain.
 *   - The MIS integration (`Services\FuMis`, `Config\FuMis`) is entirely
 *     independent of this feature and is not referenced here.
 *
 * Idempotent: re-runs are no-ops.
 */
final class DropExternalApiSurface extends Migration
{
    /** Tenant slug that hosted all `syn_test_…` key traffic. */
    private const SANDBOX_TENANT_SLUG = 'sandbox';

    /** Permission codes minted for the developer portal. */
    private const PORTAL_PERMISSIONS = ['api_apps.manage', 'api_apps.read'];

    /**
     * Tenant-scoped tables holding data for the sandbox tenant, in
     * deletion order (children first, then parents).
     *
     * @var list<string>
     */
    private const SANDBOX_DATA_TABLES = [
        'guidance_followups',
        'survey_responses',
        'survey_answer_options',
        'survey_questions',
        'survey_versions',
        'surveys',
        'guidance_announcements',
        'guidance_services',
        'kiosk_media_assets',
        'kiosk_settings',
        'counselling_queue_entries',
        'clinic_queue_entries',
        'clinic_checkins',
        'referral_referrals',
        'clinic_encounters',
    ];

    public function up(): void
    {
        // ---- 1. Developer-portal schema -------------------------------
        $this->forge->dropTable('api_keys', true);
        $this->forge->dropTable('api_apps', true);

        // ---- 2. Portal permission codes -------------------------------
        if ($this->db->tableExists('auth_groups_permissions')) {
            $this->db->table('auth_groups_permissions')
                ->whereIn('permission_code', self::PORTAL_PERMISSIONS)
                ->delete();
        }
        if ($this->db->tableExists('permissions')) {
            $this->db->table('permissions')
                ->whereIn('code', self::PORTAL_PERMISSIONS)
                ->delete();
        }

        // ---- 3. Sandbox tenant + synthetic rows -----------------------
        if (! $this->db->tableExists('tenants')) {
            return;
        }

        $tenant = $this->db->table('tenants')
            ->where('slug', self::SANDBOX_TENANT_SLUG)
            ->get()->getRowArray();

        if ($tenant === null) {
            return;
        }

        $tenantId = (int) $tenant['id'];

        foreach (self::SANDBOX_DATA_TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->where('tenant_id', $tenantId)->delete();
            }
        }

        if ($this->db->tableExists('users')) {
            $this->db->table('users')->where('tenant_id', $tenantId)->delete();
        }

        // Only drop the tenant row once nothing else references it.
        $remaining = $this->db->table('users')->where('tenant_id', $tenantId)->countAllResults();
        if ($remaining === 0) {
            $this->db->table('tenants')->where('id', $tenantId)->delete();
        }
    }

    /**
     * Not reversible: the feature's application code and its seeder no
     * longer exist, so recreating the tables would produce an empty,
     * unreachable schema. A rollback would need the deleted module back.
     */
    public function down(): void
    {
        // Intentionally empty.
    }
}
