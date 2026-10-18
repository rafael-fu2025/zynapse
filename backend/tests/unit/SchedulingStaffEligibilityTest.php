<?php

declare(strict_types=1);

namespace Tests\Unit;

use Config\SchedulingStaff;
use PHPUnit\Framework\TestCase;

final class SchedulingStaffEligibilityTest extends TestCase
{
    private function employee(array $changes = []): array
    {
        return [...['kind' => 'employee', 'employee_number' => 'E-42', 'employment_status' => 'active',
            'department' => ' Health Services ', 'archived_at' => null, 'deleted_at' => null, 'active' => 0], ...$changes];
    }

    public function testExactAliasesAndNoLoginRequirement(): void
    {
        $config = new SchedulingStaff();
        $this->assertNull($config->eligibility('clinic', $this->employee()));
        $this->assertNull($config->eligibility('clinic', $this->employee(['department' => 'UNIVERSITY HEALTH SERVICES'])));
        $this->assertSame('department_mismatch', $config->eligibility('guidance', $this->employee()));
        foreach (['Health', 'Health Services Annex', 'Nursing', 'Guidance Office'] as $label) {
            $this->assertSame('department_mismatch', $config->eligibility('clinic', $this->employee(['department' => $label, 'position' => 'Clinic Nurse'])));
        }
        $config->departments['clinic'][] = 'HS-CODE';
        $this->assertNull($config->eligibility('clinic', $this->employee(['department' => ' hs-code '])));
        $this->assertSame(['health services', 'university health services', 'hs-code'], $config->departmentKeys('clinic'));
    }

    public function testMissingDirectoryIdentityIsReviewNotInferredEmployee(): void
    {
        $config = new SchedulingStaff();
        $this->assertSame('needs_review', $config->eligibility('clinic', null));
        $this->assertSame('needs_review', $config->eligibility('clinic', $this->employee(['kind' => null])));
        $this->assertSame('needs_review', $config->eligibility('clinic', $this->employee(['employee_number' => ''])));
    }

    public function testInactiveArchivedAndDeletedDirectoryRecordsAreBlocked(): void
    {
        $config = new SchedulingStaff();
        foreach ([['employment_status' => 'inactive'], ['employment_status' => 'on_leave'],
            ['employment_status' => null], ['archived_at' => '2026-10-18 00:00:00'], ['deleted_at' => '2026-10-18 00:00:00']] as $change) {
            $this->assertSame('inactive_employee', $config->eligibility('clinic', $this->employee($change)));
        }
    }
}
