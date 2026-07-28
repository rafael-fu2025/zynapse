<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * ClinicStaffSchedules — recurring staff shift roster (Phase P5b,
 * recycled from legacy synapse_ag staff_schedules).
 *
 * Standalone admin-managed table. One row per (user, weekday) shift with
 * an optional effective date range; `schedule_type` distinguishes regular
 * shifts from on-call cover and leave. No cross-module FKs — `user_id`
 * references the auth `users` table only.
 */
final class ClinicStaffSchedules extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'        => ['type' => 'BIGINT', 'unsigned' => true, 'null' => false],
            'day_of_week'    => ['type' => 'TINYINT', 'unsigned' => true, 'null' => false, 'comment' => '0=Sun ... 6=Sat'],
            'shift_start'    => ['type' => 'TIME', 'null' => false],
            'shift_end'      => ['type' => 'TIME', 'null' => false],
            'schedule_type'  => ['type' => 'ENUM', 'constraint' => ['regular', 'on_call', 'leave'], 'null' => false, 'default' => 'regular'],
            'effective_from' => ['type' => 'DATE', 'null' => true],
            'effective_to'   => ['type' => 'DATE', 'null' => true],
            'is_active'      => ['type' => 'TINYINT', 'constraint' => 1, 'null' => false, 'default' => 1],
            'created_at'     => ['type' => 'DATETIME', 'null' => false],
            'updated_at'     => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['user_id', 'day_of_week']);
        $this->forge->addKey(['created_at', 'id']);
        $this->forge->addForeignKey('user_id', 'users', 'id', '', 'RESTRICT');
        $this->forge->createTable('clinic_staff_schedules');

        $this->db->query('ALTER TABLE `clinic_staff_schedules` ADD CONSTRAINT `chk_css_dow` CHECK (`day_of_week` BETWEEN 0 AND 6)');
        $this->db->query('ALTER TABLE `clinic_staff_schedules` ADD CONSTRAINT `chk_css_shift_order` CHECK (`shift_start` < `shift_end`)');
    }

    public function down(): void
    {
        $this->forge->dropTable('clinic_staff_schedules', true);
    }
}
