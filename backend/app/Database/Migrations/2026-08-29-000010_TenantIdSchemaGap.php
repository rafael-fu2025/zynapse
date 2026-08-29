<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TenantIdSchemaGap — closes the schema half of the multi-tenancy gap.
 *
 * `2026-08-02-100700_TenantsAndTenantId` stamped `tenant_id` onto 32
 * domain tables but targeted the legacy inventory name (`clinic_inventory`),
 * so `clinic_inventory_items` — and seven other heavily-queried tables —
 * never received the column. Any attempt to scope queries by tenant on
 * those tables fails with `Unknown column`, which is exactly how the BMG
 * incident in 2026-08-04-000080_BmgMissingTenantIdColumns happened once.
 *
 * Verified against a freshly-migrated schema (every other table without
 * tenant_id is legitimately tenant-free: Shield auth tables, RBAC
 * junctions, `migrations`, `tenants`, `counselling_key_versions`).
 *
 * Pattern matches the bulk carrier: indexed column, no FK, DEFAULT 1.
 * Idempotent: re-runs are no-ops.
 */
final class TenantIdSchemaGap extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = [
        'clinic_inventory_items',
        'clinic_inventory_movements',
        'clinic_vitals',
        'counselling_availability',
        'counselling_notes',
        'generated_reports',
        'notifications',
        'report_configurations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->db->fieldExists('tenant_id', $table)) {
                continue;
            }

            $this->forge->addColumn($table, [
                'tenant_id' => [
                    'type'       => 'INT',
                    'unsigned'   => true,
                    'null'       => false,
                    'default'    => 1,
                    'after'      => 'id',
                ],
            ]);
            // Raw ALTER, not Forge: addKey() only takes effect inside
            // createTable(), and this matches the raw index DDL the
            // identity migrations already use.
            $this->db->query(
                "ALTER TABLE `{$table}` ADD INDEX `idx_{$table}_tenant` (`tenant_id`)"
            );
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->forge->dropColumn($table, 'tenant_id');
        }
    }
}
