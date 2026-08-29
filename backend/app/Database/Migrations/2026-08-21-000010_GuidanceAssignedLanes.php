<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Add parallel, counsellor-owned Guidance service lanes. */
final class GuidanceAssignedLanes extends Migration
{
    public function up(): void
    {
        $this->db->query('DROP INDEX `uq_cqe_active_service_slot` ON `counselling_queue_entries`');
        $this->forge->dropColumn('counselling_queue_entries', 'active_service_slot');

        $this->forge->addColumn('counselling_queue_entries', [
            'assigned_counsellor_user_id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => true,
                'after' => 'counselling_session_id',
            ],
        ]);
        $this->db->query(
            'UPDATE `counselling_queue_entries` q'
            . ' INNER JOIN `counselling_appointments` a ON a.`id` = q.`counselling_appointment_id`'
            . ' SET q.`assigned_counsellor_user_id` = a.`counsellor_user_id`'
            . ' WHERE q.`assigned_counsellor_user_id` IS NULL',
        );
        $this->db->query(
            'ALTER TABLE `counselling_queue_entries` ADD CONSTRAINT `fk_cqe_assigned_counsellor`'
            . ' FOREIGN KEY (`assigned_counsellor_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT',
        );
        $this->db->query(
            'CREATE INDEX `idx_cqe_lane_waiting` ON `counselling_queue_entries`'
            . ' (`tenant_id`, `queue_date`, `status`, `assigned_counsellor_user_id`, `position`)',
        );
        $this->db->query(
            'ALTER TABLE `counselling_queue_entries` ADD `active_service_counsellor_id` BIGINT UNSIGNED'
            . " GENERATED ALWAYS AS (CASE WHEN `status` IN ('called','in_session')"
            . ' THEN `assigned_counsellor_user_id` ELSE NULL END) STORED',
        );
        $this->db->query(
            'CREATE UNIQUE INDEX `uq_cqe_active_service_counsellor`'
            . ' ON `counselling_queue_entries` (`tenant_id`, `queue_date`, `active_service_counsellor_id`)',
        );
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX `uq_cqe_active_service_counsellor` ON `counselling_queue_entries`');
        $this->forge->dropColumn('counselling_queue_entries', 'active_service_counsellor_id');
        $this->db->query('DROP INDEX `idx_cqe_lane_waiting` ON `counselling_queue_entries`');
        $this->db->query('ALTER TABLE `counselling_queue_entries` DROP FOREIGN KEY `fk_cqe_assigned_counsellor`');
        $this->forge->dropColumn('counselling_queue_entries', 'assigned_counsellor_user_id');
        $this->db->query(
            'ALTER TABLE `counselling_queue_entries` ADD `active_service_slot` TINYINT'
            . " GENERATED ALWAYS AS (CASE WHEN `status` IN ('called','in_session') THEN 1 ELSE NULL END) STORED",
        );
        $this->db->query(
            'CREATE UNIQUE INDEX `uq_cqe_active_service_slot`'
            . ' ON `counselling_queue_entries` (`tenant_id`, `queue_date`, `active_service_slot`)',
        );
    }
}
