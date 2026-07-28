<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CounsellingSchedulingAnalytics — per-counsellor/slot no-show analytics
 * (Phase P5a, recycled from legacy synapse_ag scheduling_analytics +
 * SchedulingOptimizer).
 *
 * Populated by a deterministic recompute over `counselling_appointments`
 * (aggregate only — no cross-module JOIN). One row per
 * (counsellor, weekday, time slot); re-running upserts in place.
 */
final class CounsellingSchedulingAnalytics extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                      => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'counsellor_user_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'day_of_week'             => ['type' => 'TINYINT', 'unsigned' => true, 'null' => false, 'comment' => '0=Sun ... 6=Sat'],
            'time_slot'               => ['type' => 'TIME', 'null' => false],
            'total_appointments'      => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'total_no_shows'          => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'no_show_rate'            => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => false, 'default' => 0],
            'avg_utilization'         => ['type' => 'DECIMAL', 'constraint' => '5,4', 'null' => false, 'default' => 0],
            'recommended_overbooking' => ['type' => 'INT', 'unsigned' => true, 'null' => false, 'default' => 0],
            'last_calculated_at'      => ['type' => 'DATETIME', 'null' => true],
            'created_at'              => ['type' => 'DATETIME', 'null' => false],
            'updated_at'              => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['counsellor_user_id', 'day_of_week', 'time_slot']);
        $this->forge->addForeignKey('counsellor_user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('counselling_scheduling_analytics');

        $this->db->query('ALTER TABLE `counselling_scheduling_analytics` ADD CONSTRAINT `chk_csa_dow` CHECK (`day_of_week` BETWEEN 0 AND 6)');
    }

    public function down(): void
    {
        $this->forge->dropTable('counselling_scheduling_analytics', true);
    }
}
