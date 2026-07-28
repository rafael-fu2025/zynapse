<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ReorderRequests — procurement workflow recycled from legacy
 * `synapse_ag` reorder_requests (Phase 13).
 *
 * Lifecycle: pending → approved → ordered → received (or cancelled at
 * any pre-terminal step). `current_stock`/`reorder_level` are frozen
 * snapshots taken at request time, so the request stays meaningful
 * after stock moves. One OPEN request per medicine (service-enforced,
 * ported from ReorderRequestModel::hasOpenRequest).
 */
final class ReorderRequests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                     => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'medicine_id'            => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'requested_quantity'     => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'current_stock'          => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'reorder_level'          => ['type' => 'INT', 'unsigned' => true, 'null' => false],
            'urgency'                => ['type' => 'ENUM', 'constraint' => ['low', 'medium', 'high', 'critical'], 'null' => false, 'default' => 'medium'],
            'status'                 => ['type' => 'ENUM', 'constraint' => ['pending', 'approved', 'ordered', 'received', 'cancelled'], 'null' => false, 'default' => 'pending'],
            'auto_triggered'         => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 0],
            'requested_by_user_id'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'approved_by_user_id'    => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'procurement_note'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'order_date'             => ['type' => 'DATE', 'null' => true],
            'expected_delivery_date' => ['type' => 'DATE', 'null' => true],
            'actual_delivery_date'   => ['type' => 'DATE', 'null' => true],
            'created_at'             => ['type' => 'DATETIME', 'null' => false],
            'updated_at'             => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('status');
        $this->forge->addKey('urgency');
        $this->forge->addKey(['medicine_id', 'status']);
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->addForeignKey('medicine_id', 'clinic_medicines', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('requested_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->addForeignKey('approved_by_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_reorder_requests');

        // Legacy CHECK (MySQL 8.4 enforces).
        $this->db->query('ALTER TABLE `clinic_reorder_requests` ADD CONSTRAINT `chk_crr_qty_positive` CHECK (`requested_quantity` > 0)');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_reorder_requests', true);
    }
}
