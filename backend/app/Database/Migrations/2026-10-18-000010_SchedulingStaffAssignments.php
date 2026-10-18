<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class SchedulingStaffAssignments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'unsigned' => true],
            'unit' => ['type' => 'VARCHAR', 'constraint' => 16],
            'user_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'is_active' => ['type' => 'TINYINT', 'default' => 1],
            'enrolled_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'updated_by_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'unit', 'user_id']);
        // No cascading user FK: a missing legacy directory identity must remain reviewable.
        $this->forge->createTable('scheduling_staff_assignments', true);

        foreach ([
            ['clinic', 'clinic_staff_schedules', 'user_id'],
            ['guidance', 'counselling_availability', 'counsellor_user_id'],
            ['guidance', 'counselling_appointments', 'counsellor_user_id'],
        ] as [$unit, $table, $column]) {
            $this->db->query(
                "INSERT IGNORE INTO scheduling_staff_assignments
                    (tenant_id, unit, user_id, is_active, created_at, updated_at)
                 SELECT DISTINCT tenant_id, ?, `{$column}`, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
                 FROM `{$table}` WHERE `{$column}` IS NOT NULL",
                [$unit],
            );
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('scheduling_staff_assignments', true);
    }
}
