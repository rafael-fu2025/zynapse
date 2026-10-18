<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/** Exact directory aliases only; keys are scheduling units, not MIS department IDs. */
final class SchedulingStaff extends BaseConfig
{
    /** @var array<string, list<string>> Add verified labels/codes here or in a config registrar. */
    public array $departments = [
        'clinic' => ['Health Services', 'University Health Services'],
        'guidance' => ['Guidance'],
    ];

    public static function normalize(?string $label): string
    {
        return strtolower(trim($label ?? ''));
    }

    /** @return list<string> */
    public function departmentKeys(string $unit): array
    {
        return array_values(array_unique(array_filter(array_map(self::normalize(...), $this->departments[$unit] ?? []))));
    }

    /** Directory validity is independent of auth active flags, credentials and roles. */
    public function eligibility(string $unit, ?array $user): ?string
    {
        if ($user === null || ($user['kind'] ?? null) !== 'employee'
            || trim((string) ($user['employee_number'] ?? '')) === '') {
            return 'needs_review';
        }
        if (($user['archived_at'] ?? null) !== null || ($user['deleted_at'] ?? null) !== null
            || ($user['employment_status'] ?? null) !== 'active') {
            return 'inactive_employee';
        }
        if (! in_array(self::normalize($user['department'] ?? null), $this->departmentKeys($unit), true)) {
            return 'department_mismatch';
        }
        return null;
    }
}
