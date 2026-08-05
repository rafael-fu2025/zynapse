<?php
/**
 * PatientLookupService — identity-consolidated patient resolution.
 *
 * Resolves a patient by (qr_code, rfid_tag, student_number, or
 * employee_number) directly against the consolidated `users` table —
 * every patient IS a user row with a `kind` discriminator. There is no
 * legacy fallback and no patient_identifiers/persons join: `id` in the
 * returned row is the `users.id` (the canonical patient_user_id).
 *
 * Used by CheckinService, AppointmentService, ClinicService, QueueService,
 * ReferralService, CounsellingService, ScheduleService.
 *
 * @phpstan-type PatientRow array<string, mixed>
 */
declare(strict_types=1);

namespace Modules\Clinic\Services;

use Config\Services;

final class PatientLookupService
{
    private const USER_COLS = 'u.id, u.kind, u.first_name, u.last_name, u.middle_name, u.qr_code, u.rfid_tag, u.date_of_birth, u.gender, u.address, u.archived_at, u.created_at, u.updated_at, u.student_number, u.course, u.year_level, u.section, u.blood_type, u.consecutive_no_shows, u.employee_number, u.department, u.position, u.date_hired, u.employment_status, u.hr_synced_at, u.emergency_contact_name, u.emergency_contact_phone, u.is_teaching';

    /**
     * Resolve a patient by a raw identifier value (student_number or
     * employee_number). This is the common case for services that receive
     * a `patient_school_id`.
     *
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    public function findByIdentifier(string $identifier): array
    {
        $row = $this->resolveFromUsersByIdentifier($identifier);
        return $row !== null ? [$row['kind'], $row] : [null, null];
    }

    /**
     * @param 'qr'|'rfid'|'manual' $method  The scan method.
     * @param string               $identifier  The raw scanned value.
     * @return array{
     *     0: string|null,                              // 'student'|'employee'|null
     *     1: array<string, mixed>|null                 // user-shaped row, or null on miss
     * }
     */
    public function findForCheckin(string $method, string $identifier): array
    {
        $column = $this->columnForMethod($method);
        $row    = $this->resolveFromUsers($identifier, $column);
        return $row !== null ? [$row['kind'], $row] : [null, null];
    }

    /**
     * Resolve a user by identifier (student_number OR employee_number).
     * Prefers the first non-archived student/employee match.
     *
     * @return array<string, mixed>|null
     */
    private function resolveFromUsersByIdentifier(string $identifier): ?array
    {
        $db = Services::database();
        $row = $db->table('users u')
            ->select(self::USER_COLS)
            ->whereIn('u.kind', ['student', 'employee'])
            ->where('u.archived_at IS NULL', null, false)
            ->groupStart()
                ->where('u.student_number', $identifier)
                ->orWhere('u.employee_number', $identifier)
            ->groupEnd()
            ->orderBy('u.id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();
        return $row !== null && ! empty($row) ? $row : null;
    }

    /**
     * Resolve a user by a scan column (qr_code / rfid_tag) or, when the
     * method has no column (manual), by identifier.
     *
     * @return array<string, mixed>|null
     */
    private function resolveFromUsers(string $identifier, ?string $column): ?array
    {
        $db = Services::database();
        $builder = $db->table('users u')
            ->select(self::USER_COLS)
            ->whereIn('u.kind', ['student', 'employee'])
            ->where('u.archived_at IS NULL', null, false)
            ->orderBy('u.id', 'ASC')
            ->limit(1);

        if ($column === null) {
            $builder->groupStart()
                ->where('u.student_number', $identifier)
                ->orWhere('u.employee_number', $identifier)
            ->groupEnd();
        } else {
            $builder->where('u.' . $column, $identifier);
        }

        $row = $builder->get()->getRowArray();
        return $row !== null && ! empty($row) ? $row : null;
    }

    private function columnForMethod(string $method): ?string
    {
        return match ($method) {
            'qr'   => 'qr_code',
            'rfid' => 'rfid_tag',
            default => null,
        };
    }
}
