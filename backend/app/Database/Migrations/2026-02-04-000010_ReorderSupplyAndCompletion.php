<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ReorderSupplyAndCompletion — extends the procurement workflow so it
 * covers clinic SUPPLY items as well as medicines, and closes the loop
 * between the Reorders tab and the stock-entry screens:
 *
 *   - `item_type`      : 'medicine' (default, legacy rows) or 'supply'.
 *   - `supply_item_id` : FK to clinic_inventory_items for supply rows;
 *                        `medicine_id` becomes nullable for the same
 *                        reason (exactly one of the two is set).
 *   - status 'completed' : terminal state set when the delivered stock
 *                        is actually entered (medicine batch received /
 *                        supply movement recorded). 'received' now only
 *                        means "delivery arrived" and counts as an OPEN
 *                        status, so auto-check can no longer duplicate
 *                        a request while the batch entry is pending.
 *   - `fulfilled_at`   : timestamp of that completion.
 */
final class ReorderSupplyAndCompletion extends Migration
{
    public function up(): void
    {
        // ENUM widening + nullability via raw ALTERs (forge can't modify enums).
        $this->db->query(
            "ALTER TABLE `clinic_reorder_requests`"
            . " MODIFY `status` ENUM('pending','approved','ordered','received','completed','cancelled') NOT NULL DEFAULT 'pending',"
            . " MODIFY `medicine_id` BIGINT UNSIGNED NULL,"
            . " ADD `item_type` ENUM('medicine','supply') NOT NULL DEFAULT 'medicine' AFTER `id`,"
            . " ADD `supply_item_id` BIGINT UNSIGNED NULL AFTER `medicine_id`,"
            . " ADD `fulfilled_at` DATETIME NULL AFTER `actual_delivery_date`"
        );

        $this->db->query(
            'ALTER TABLE `clinic_reorder_requests`'
            . ' ADD CONSTRAINT `fk_crr_supply_item` FOREIGN KEY (`supply_item_id`)'
            . ' REFERENCES `clinic_inventory_items`(`id`) ON DELETE RESTRICT'
        );
        $this->db->query('CREATE INDEX `idx_crr_supply_status` ON `clinic_reorder_requests` (`supply_item_id`, `status`)');

        // Exactly one of medicine_id / supply_item_id per row (MySQL 8.4 enforces CHECKs).
        $this->db->query(
            'ALTER TABLE `clinic_reorder_requests` ADD CONSTRAINT `chk_crr_one_item` CHECK ('
            . " (`item_type` = 'medicine' AND `medicine_id` IS NOT NULL AND `supply_item_id` IS NULL)"
            . " OR (`item_type` = 'supply' AND `supply_item_id` IS NOT NULL AND `medicine_id` IS NULL)"
            . ')'
        );
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `clinic_reorder_requests` DROP CONSTRAINT `chk_crr_one_item`');
        $this->db->query('ALTER TABLE `clinic_reorder_requests` DROP FOREIGN KEY `fk_crr_supply_item`');
        $this->db->query('DROP INDEX `idx_crr_supply_status` ON `clinic_reorder_requests`');
        $this->db->query("UPDATE `clinic_reorder_requests` SET `status` = 'received' WHERE `status` = 'completed'");
        $this->db->query(
            'ALTER TABLE `clinic_reorder_requests`'
            . " MODIFY `status` ENUM('pending','approved','ordered','received','cancelled') NOT NULL DEFAULT 'pending',"
            . ' MODIFY `medicine_id` BIGINT UNSIGNED NOT NULL,'
            . ' DROP COLUMN `item_type`,'
            . ' DROP COLUMN `supply_item_id`,'
            . ' DROP COLUMN `fulfilled_at`'
        );
    }
}
