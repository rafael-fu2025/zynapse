<?php
/**
 * StudentSelfService — identity-consolidated.
 *
 * The caller IS the student: the profile is read straight from `users`
 * (kind=student) by CurrentUser::id(). No patients_students link, no
 * person/identifier join.
 */
declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use Modules\Clinic\DTOs\UserDto;

/**
 * StudentSelfService — Phase 13.
 *
 * Resolves the caller's profile directly from `users` (kind=student).
 * Returns 404 when the user is not a student.
 */
final class StudentSelfService extends BaseService
{
    private const USER_COLS = 'id, kind, first_name, last_name, middle_name, qr_code, rfid_tag, date_of_birth, gender, address, archived_at, student_number, course, year_level, section, blood_type, consecutive_no_shows, employee_number, department, position, date_hired, employment_status, hr_synced_at, emergency_contact_name, emergency_contact_phone, is_teaching, created_at, updated_at';

    /**
     * Return the calling user's student profile, or throw 404.
     */
    public function getMyProfile(): UserDto
    {
        $userId = CurrentUser::assert();
        $row = $this->findStudentUser($userId);
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'student.not_registered', 'message' => 'No student record is linked to your account.'],
            ]);
        }
        return UserDto::fromRow($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMyClinicVisits(int $limit = 50): array
    {
        $userId = CurrentUser::assert();
        $stu = $this->findStudentUser($userId);
        if ($stu === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'student.not_registered', 'message' => 'No student record is linked to your account.'],
            ]);
        }

        $rows = $this->db->table('clinic_encounters')
            ->select('id, patient_user_id, chief_complaint, triage_priority, status, attending_user_id, started_at, closed_at, created_at')
            ->where('patient_user_id', $userId)
            ->where('archived_at', null)
            ->orderBy('started_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        $names = [];
        if ($rows !== []) {
            $userIds = array_values(array_unique(array_filter(array_map(
                static fn (array $r) => $r['attending_user_id'] !== null ? (int) $r['attending_user_id'] : null,
                $rows,
            ))));
            if ($userIds !== []) {
                $uRows = $this->db->table('users')
                    ->select('id, username')
                    ->whereIn('id', $userIds)
                    ->get()->getResultArray();
                foreach ($uRows as $u) {
                    $names[(int) $u['id']] = (string) $u['username'];
                }
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'                  => (int)    $r['id'],
                'chief_complaint'     => (string) $r['chief_complaint'],
                'triage_priority'     => $r['triage_priority'] !== null ? (string) $r['triage_priority'] : null,
                'status'              => (string) $r['status'],
                'attending_username'  => $names[(int) $r['attending_user_id']] ?? null,
                'started_at'          => (string) $r['started_at'],
                'closed_at'           => $r['closed_at'] !== null ? (string) $r['closed_at'] : null,
                'created_at'          => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findStudentUser(int $userId): ?array
    {
        $row = $this->db->table('users')
            ->select(self::USER_COLS)
            ->where('id', $userId)
            ->where('kind', 'student')
            ->where('archived_at', null)
            ->get()->getRowArray();
        return $row;
    }
}
