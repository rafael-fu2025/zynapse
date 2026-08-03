<?php
/**
 * Phase 1.2: StudentSelfService — return persons_id and patient_identifier_id
 * alongside the legacy patients_students row.
 */
declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use Modules\Clinic\DTOs\StudentDto;

/**
 * StudentSelfService — Phase 13.
 *
 * Mirror of `EmployeeSelfService` for the student side. Resolves
 * the caller's `users.id` to a `patients_students` row by the
 * UNIQUE `user_id` link added in `StudentUserLink`. Returns 404
 * when the user has no linked student record.
 */
final class StudentSelfService extends BaseService
{
    /**
     * Return the calling user's student record, or throw 404.
     * Now also returns persons_id and patient_identifier_id (Phase 1.2).
     */
    public function getMyProfile(): StudentDto
    {
        $userId = CurrentUser::assert();
        $row = $this->findStudentRowForUserId($userId);
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'student.not_registered', 'message' => 'No student record is linked to your account.'],
            ]);
        }
        // Phase 1.2: enrich with unified-identity fields.
        $row = $this->enrichWithUnifiedFields($row);
        return StudentDto::fromRow($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMyClinicVisits(int $limit = 50): array
    {
        $userId = CurrentUser::assert();
        $stu = $this->findStudentRowForUserId($userId);
        if ($stu === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'student.not_registered', 'message' => 'No student record is linked to your account.'],
            ]);
        }
        $studentNumber = (string) $stu['student_number'];

        $rows = $this->db->table('clinic_encounters')
            ->select('id, patient_school_id, chief_complaint, triage_priority, status, attending_user_id, started_at, closed_at, created_at')
            ->where('patient_school_id', $studentNumber)
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
    private function findStudentRowForUserId(int $userId): ?array
    {
        $row = $this->db->table('patients_students')
            ->select('id, user_id, student_number, first_name, last_name, middle_name, qr_code, rfid_tag, course, year_level, section, date_of_birth, gender, address, blood_type, consecutive_no_shows, archived_at, created_at, updated_at, persons_id')
            ->where('user_id', $userId)
            ->where('archived_at', null)
            ->get()->getRowArray();
        return $row;
    }

    /**
     * Phase 1.2: enrich a patients_students row with persons_id and
     * patient_identifier_id (both from the new unified-identity
     * tables). persons_id is already on the legacy table; the patient
     * identifier id comes from patient_identifiers.
     */
    private function enrichWithUnifiedFields(array $row): array
    {
        $personsId = isset($row['persons_id']) ? (int) $row['persons_id'] : null;
        $patientIdentifierId = null;
        if ($personsId !== null) {
            $piRow = $this->db->table('patient_identifiers')
                ->select('id')
                ->where('persons_id', $personsId)
                ->where('kind', 'student')
                ->where('archived_at IS NULL', null, false)
                ->get()->getRowArray();
            if ($piRow !== null) {
                $patientIdentifierId = (int) $piRow['id'];
            }
        }
        $row['persons_id'] = $personsId;
        $row['patient_identifier_id'] = $patientIdentifierId;
        return $row;
    }
}
