<?php

declare(strict_types=1);

namespace Modules\Clinic\Policies;

use App\Modules\Shared\BasePolicy;

/**
 * ClinicPolicy — gates clinic encounters + vitals.
 *
 * Module-level permissions:
 *   - clinic.encounters.read  → list / view
 *   - clinic.encounters.create → create
 *   - clinic.encounters.write → vitals + close
 *
 * Record-level:
 *   - A user with `clinic.encounters.write` may act on any encounter.
 *   - Otherwise, only the encounter's `attending_user_id` may act.
 */
final class ClinicPolicy extends BasePolicy
{
    public function check(string $action, mixed $record = null): void
    {
        $code = match ($action) {
            'list'           => 'clinic.encounters.read',
            'create'         => 'clinic.encounters.create',
            'view'           => 'clinic.encounters.read',
            'vitalsRead'     => 'clinic.encounters.read',
            'recordVitals'   => 'clinic.encounters.write',
            'close'          => 'clinic.encounters.write',
            'addTreatment'   => 'clinic.encounters.write',
            'setAssessment'  => 'clinic.encounters.write',
            'treatmentsRead' => 'clinic.treatments.read',
            'triageUse'      => 'clinic.triage.use',
            'inventoryForecast' => 'clinic.inventory.forecast',
            'inventoryRead'     => 'clinic.inventory.read',
            'inventoryWrite'    => 'clinic.inventory.write',
            'inventoryDelete'   => 'clinic.inventory.delete',
            'appointmentsRead'  => 'clinic.appointments.read',
            'appointmentsWrite' => 'clinic.appointments.write',
            'patientsRead'      => 'clinic.patients.read',
            'patientsWrite'     => 'clinic.patients.write',
            'departmentsManage' => 'clinic.departments.manage',
            'schedulesManage'   => 'clinic.schedules.manage',
            'reordersRead'      => 'clinic.reorders.read',
            'reordersManage'    => 'clinic.reorders.manage',
            'queueRead'         => 'clinic.queue.read',
            'queueManage'       => 'clinic.queue.manage',
            'checkinRecord'     => 'clinic.checkin.record',
            'checkinRead'       => 'clinic.checkin.read',
            'markNoShow'        => 'clinic.encounters.write',
            default          => null,
        };
        if ($code === null) {
            $this->deny('rbac.clinic.forbidden');
        }
        $this->enforce($code, $action, $record);
    }

    /**
     * @param array<string, mixed>|object|null $record
     */
    protected function canOnRecord(int $userId, mixed $record, string $action): bool
    {
        // Module-level write permission overrides record ownership.
        if ($this->can('clinic.encounters.write')) {
            return true;
        }
        $attending = is_array($record)
            ? ($record['attending_user_id'] ?? null)
            : ($record?->attending_user_id ?? null);
        return $attending !== null && (int) $attending === $userId;
    }
}