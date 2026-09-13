<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * SandboxTenant — the dedicated tenant that hosts ALL test-key traffic
 * (2026-09, D5). Synthetic data for it is seeded by SandboxTenantSeeder
 * (dev-gated like every demo seeder — production starts empty).
 *
 * Idempotent: inserts only when the slug is absent, so existing
 * deployments converge. Tenant 1 remains the production tenant.
 */
final class SandboxTenant extends Migration
{
    public function up(): void
    {
        $exists = $this->db->table('tenants')->where('slug', 'sandbox')->countAllResults();
        if ($exists > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert([
            'name'       => 'Sandbox (Synthetic)',
            'slug'       => 'sandbox',
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Sandbox tenant rows may own api_keys (RESTRICT FK) — only
        // remove the tenant when nothing references it.
        $tenant = $this->db->table('tenants')->where('slug', 'sandbox')->get()->getRowArray();
        if ($tenant === null) {
            return;
        }
        $keys = $this->db->table('api_keys')->where('tenant_id', (int) $tenant['id'])->countAllResults();
        $apps = $this->db->table('api_apps')->where('tenant_id', (int) $tenant['id'])->countAllResults();
        if ($keys === 0 && $apps === 0) {
            $this->db->table('tenants')->where('id', (int) $tenant['id'])->delete();
        }
    }
}
