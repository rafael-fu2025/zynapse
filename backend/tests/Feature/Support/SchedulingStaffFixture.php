<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

/** Explicit opt-in fixture; login itself must never imply scheduling enrollment. */
trait SchedulingStaffFixture
{
    protected function enrollSchedulingFixture(int $id, string $unit): int
    {
        $db = db_connect();
        $db->table('users')->where('id', $id)->update([
            'kind' => 'employee', 'employee_number' => 'SCH-' . $id,
            'department' => $unit === 'clinic' ? 'Health Services' : 'Guidance',
            'employment_status' => 'active',
        ]);
        $now = gmdate('Y-m-d H:i:s');
        $db->table('scheduling_staff_assignments')->insert([
            'tenant_id' => 1, 'unit' => $unit, 'user_id' => $id, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return (int) $db->insertID();
    }
}
