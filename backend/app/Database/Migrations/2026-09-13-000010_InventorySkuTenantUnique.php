<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * InventorySkuTenantUnique — closes a multi-tenant gap in the supplies
 * catalog (2026-09-13 inventory audit).
 *
 * `2026-01-08-000010_ClinicInventory` created a GLOBAL unique key on
 * `clinic_inventory_items.sku`. SKUs are user-supplied, so a second
 * tenant reusing a first tenant's SKU would sail past the tenant-scoped
 * pre-check in InventoryService::createItem and then die on the raw
 * constraint with a 500. The key must be scoped per tenant.
 *
 * `clinic_medicine_batches` (medicine_id, batch_number) needs no such
 * fix — `medicine_id` is a global auto-increment PK, so that composite
 * key already cannot collide across tenants.
 *
 * Idempotent: re-runs are no-ops.
 */
final class InventorySkuTenantUnique extends Migration
{
    private const TABLE = 'clinic_inventory_items';

    private const NEW_KEY = 'uq_inventory_items_tenant_sku';

    public function up(): void
    {
        if (! $this->db->tableExists(self::TABLE) || $this->indexExists(self::NEW_KEY)) {
            return;
        }

        // Drop the global single-column UNIQUE on `sku` — located by shape
        // (a unique index covering exactly the sku column), not by name,
        // so the migration survives whatever the original Forge run named it.
        $global = $this->db->query("
            SELECT INDEX_NAME
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '" . self::TABLE . "'
              AND NON_UNIQUE = 0
              AND INDEX_NAME <> 'PRIMARY'
            GROUP BY INDEX_NAME
            HAVING COUNT(*) = 1 AND MIN(COLUMN_NAME) = 'sku' AND MAX(COLUMN_NAME) = 'sku'
            LIMIT 1
        ")->getRowArray();

        if ($global !== null) {
            $this->db->query(
                'ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . $global['INDEX_NAME'] . '`'
            );
        }

        $this->db->query(
            'ALTER TABLE `' . self::TABLE . '` ADD UNIQUE INDEX `' . self::NEW_KEY . '` (`tenant_id`, `sku`)'
        );
    }

    public function down(): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            return;
        }
        if ($this->indexExists(self::NEW_KEY)) {
            $this->db->query('ALTER TABLE `' . self::TABLE . '` DROP INDEX `' . self::NEW_KEY . '`');
        }
        if (! $this->indexExists('sku')) {
            $this->db->query('ALTER TABLE `' . self::TABLE . '` ADD UNIQUE INDEX `sku` (`sku`)');
        }
    }

    private function indexExists(string $indexName): bool
    {
        $row = $this->db->query("
            SELECT INDEX_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '" . self::TABLE . "'
              AND INDEX_NAME = '{$indexName}'
            LIMIT 1
        ")->getRow();
        return $row !== null;
    }
}
