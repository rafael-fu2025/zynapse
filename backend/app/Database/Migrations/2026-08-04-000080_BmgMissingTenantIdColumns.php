<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * BmgMissingTenantIdColumns — panel-revision patch (August 2026):
 *
 * The original `TenantsAndTenantId` migration added `tenant_id` to the
 * core Facilities tables (`facilities_bmg_units`, `facilities_bmg_batches`,
 * `facilities_bmg_process_logs`, `facilities_waste_categories`) but missed
 * three child tables that the application already treats as tenant-scoped:
 *
 *   - facilities_bmg_composition  (per-batch per-category mix rows)
 *   - facilities_bmg_inputs       (per-batch feedstock inputs)
 *   - facilities_bmg_outputs      (per-batch harvest outputs)
 *
 * `BmgService::startBatch` and `BmgService::addBatchIo` both write a
 * `tenant_id` column into these tables, so the missing columns surface
 * as `Unknown column 'tenant_id' in 'field list'` → MySQL error →
 * transaction rollback → 409 `transaction.rolled_back` to the SPA.
 *
 * This migration adds `tenant_id INT UNSIGNED NOT NULL DEFAULT 1` to
 * each of the three tables, mirroring the column added by
 * `TenantsAndTenantId`. The default keeps the migration idempotent on
 * empty tables and lets legacy rows that pre-date multi-tenant scope
 * remain valid (CurrentTenant::id() is 1 in the single-tenant
 * deployment anyway).
 *
 * Idempotent: re-running is a no-op via `forge->addColumn()`-equivalent
 * existence checks.
 */
final class BmgMissingTenantIdColumns extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'facilities_bmg_composition',
        'facilities_bmg_inputs',
        'facilities_bmg_outputs',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if ($this->db->fieldExists('tenant_id', $table)) {
                continue;
            }
            $this->forge->addColumn($table, [
                'tenant_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => false,
                    'default'  => 1,
                    'after'    => 'id',
                ],
            ]);
            // Index for the same tenant-scoping queries every other
            // domain table uses (idx_<table>_tenant). Mirrors
            // `TenantsAndTenantId::up()` so the planner can choose it
            // for batch-scoped reads.
            $this->forge->addKey('tenant_id', false, false, 'idx_' . $table . '_tenant');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table)) {
                continue;
            }
            if ($this->db->fieldExists('tenant_id', $table)) {
                $this->forge->dropColumn($table, 'tenant_id');
            }
        }
    }
}