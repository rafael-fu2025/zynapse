<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Add an independent replenishment target and snapshot it on reorders. */
final class InventoryTargetStock extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('clinic_inventory_items', [
            'target_stock' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'after' => 'reorder_level',
            ],
        ]);
        $this->forge->addColumn('clinic_medicines', [
            'target_stock' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'after' => 'reorder_threshold',
            ],
        ]);
        $this->forge->addColumn('clinic_reorder_requests', [
            'target_stock' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'after' => 'reorder_level',
            ],
        ]);

        // Preserve the existing proposal behavior until clinic personnel review
        // each target. New and edited rows require an explicit valid target.
        $this->db->query(
            'UPDATE `clinic_inventory_items` SET `target_stock` = `reorder_level` * 2'
            . ' WHERE `reorder_level` > 0 AND `target_stock` IS NULL',
        );
        $this->db->query(
            'UPDATE `clinic_medicines` SET `target_stock` = `reorder_threshold` * 2'
            . ' WHERE `reorder_threshold` > 0 AND `target_stock` IS NULL',
        );
    }

    public function down(): void
    {
        $this->forge->dropColumn('clinic_reorder_requests', 'target_stock');
        $this->forge->dropColumn('clinic_medicines', 'target_stock');
        $this->forge->dropColumn('clinic_inventory_items', 'target_stock');
    }
}

