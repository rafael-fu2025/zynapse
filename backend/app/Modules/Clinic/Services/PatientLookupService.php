<?php
/**
 * PatientLookupService — identity-consolidated patient resolution.
 *
 * Resolves a patient by student_number or employee_number directly
 * against the consolidated `users` table —
 * every patient IS a user row with a `kind` discriminator. There is no
 * legacy fallback and no patient_identifiers/persons join: `id` in the
 * returned row is the `users.id` (the canonical patient_user_id).
 *
 * Used by AppointmentService, ClinicService, QueueService,
 * ReferralService, CounsellingService, ScheduleService.
 *
 * @phpstan-type PatientRow array<string, mixed>
 */
declare(strict_types=1);

namespace Modules\Clinic\Services;

use Config\Services;
use App\Services\CurrentTenant;
use App\Exceptions\ApiException;

final class PatientLookupService
{
    private const USER_COLS = 'u.id, u.kind, u.first_name, u.last_name, u.middle_name, u.qr_code, u.date_of_birth, u.gender, u.address, u.archived_at, u.created_at, u.updated_at, u.student_number, u.course, u.year_level, u.section, u.blood_type, u.consecutive_no_shows, u.employee_number, u.department, u.position, u.date_hired, u.employment_status, u.hr_synced_at, u.emergency_contact_name, u.emergency_contact_phone, u.is_teaching';

    /**
     * Resolve a patient by a raw identifier value (student_number or
     * employee_number). This is the common case for services that receive
     * a `patient_school_id`.
     *
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    public function findByIdentifier(string $identifier): array
    {
        if (trim($identifier) === '') {
            return [null, null];
        }
        $row = $this->resolveFromUsersByIdentifier($identifier);
        // A known foreign identity is not an invitation to provision/associate
        // it in this tenant. Unknown identifiers retain the existing fallback.
        if ($row === null) {
            $foreign = Services::database()->table('users u')
                ->where('u.tenant_id !=', CurrentTenant::id())
                ->whereIn('u.kind', ['student', 'employee'])
                ->groupStart()->where('u.student_number', $identifier)->orWhere('u.employee_number', $identifier)->groupEnd()
                ->countAllResults();
            if ($foreign > 0) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'field' => 'patient_school_id', 'message' => 'Patient identity is unavailable in this tenant.'],
                ]);
            }
        }
        if ($row === null) {
            try {
                $fuMis = Services::fuMisAuthService();
                $userId = $fuMis->ensureStudentProvisioned($identifier);
                if ($userId === null) {
                    $userId = $fuMis->ensureEmployeeProvisioned($identifier);
                }
                if ($userId !== null) {
                    $row = $this->resolveFromUsersByIdentifier($identifier);
                }
            } catch (\Throwable $e) {
                log_message('warning', sprintf('PatientLookupService MIS resolution failed: %s', $e->getMessage()));
            }
        }
        return $row !== null ? [$row['kind'], $row] : [null, null];
    }

    /** Validate a canonical patient FK without changing identity or access policy. */
    public function assertPatientUserId(int $userId): void
    {
        $row = Services::database()->table('users')
            ->where('tenant_id', CurrentTenant::id())->where('id', $userId)
            ->whereIn('kind', ['student', 'employee'])->get()->getRowArray();
        if ($row === null) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'field' => 'patient_user_id', 'message' => 'Patient record is unavailable in this tenant.'],
            ]);
        }
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
            ->where('u.tenant_id', CurrentTenant::id())
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
}
