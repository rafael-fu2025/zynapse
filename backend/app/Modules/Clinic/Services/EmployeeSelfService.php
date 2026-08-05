<?php
/**
 * EmployeeSelfService — identity-consolidated.
 *
 * The caller IS the employee: the profile is read straight from `users`
 * (kind=employee) by CurrentUser::id(). No patients_employees link, no
 * person/identifier join.
 */
declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\UserDto;

/**
 * EmployeeSelfService — Phase 11.
 */
final class EmployeeSelfService extends BaseService
{
    private const USER_COLS = 'id, kind, first_name, last_name, middle_name, qr_code, rfid_tag, date_of_birth, gender, address, archived_at, student_number, course, year_level, section, blood_type, consecutive_no_shows, employee_number, department, position, date_hired, employment_status, hr_synced_at, emergency_contact_name, emergency_contact_phone, is_teaching, created_at, updated_at';

    /**
     * Return the calling user's employee profile, or 404.
     */
    public function getMyProfile(): UserDto
    {
        $userId = CurrentUser::assert();
        $row = $this->findEmployeeUser($userId);
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'employee.not_registered', 'message' => 'No employee record is linked to your account.'],
            ]);
        }
        return UserDto::fromRow($row);
    }

    public function updateMyProfile(array $input): UserDto
    {
        $userId = CurrentUser::assert();
        $existing = $this->findEmployeeUser($userId);
        if ($existing === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'employee.not_registered', 'message' => 'No employee record is linked to your account.'],
            ]);
        }
        $id = (int) $existing['id'];

        $patch = [];
        if (array_key_exists('first_name', $input) && is_string($input['first_name']) && trim($input['first_name']) !== '') {
            $patch['first_name'] = trim($input['first_name']);
        }
        if (array_key_exists('middle_name', $input)) {
            $patch['middle_name'] = $input['middle_name'] === null ? null : (trim((string) $input['middle_name']) !== '' ? trim((string) $input['middle_name']) : null);
        }
        if (array_key_exists('last_name', $input) && is_string($input['last_name']) && trim($input['last_name']) !== '') {
            $patch['last_name'] = trim($input['last_name']);
        }
        if (array_key_exists('emergency_contact_name', $input)) {
            $patch['emergency_contact_name'] = $input['emergency_contact_name'] === null ? null : trim((string) $input['emergency_contact_name']);
        }
        if (array_key_exists('emergency_contact_phone', $input)) {
            $patch['emergency_contact_phone'] = $input['emergency_contact_phone'] === null ? null : trim((string) $input['emergency_contact_phone']);
        }
        if (array_key_exists('address', $input)) {
            $patch['address'] = $input['address'] === null ? null : trim((string) $input['address']);
        }
        if (array_key_exists('gender', $input) && in_array($input['gender'], ['male', 'female', 'other'], true)) {
            $patch['gender'] = $input['gender'];
        }
        if (array_key_exists('date_of_birth', $input) && $input['date_of_birth'] !== null) {
            $patch['date_of_birth'] = (string) $input['date_of_birth'];
        }

        if ($patch === []) {
            return UserDto::fromRow($existing);
        }

        $patch['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->db->table('users')
            ->where('id', $id)
            ->where('kind', 'employee')
            ->update($patch);

        $fresh = $this->findEmployeeUser($userId);
        return UserDto::fromRow($fresh ?? array_merge($existing, $patch));
    }

    /**
     * The calling employee's clinic encounters (portal "My clinic
     * visits"), newest first. Mirror of StudentSelfService.
     *
     * @return list<array<string, mixed>>
     */
    public function listMyClinicVisits(int $limit = 50): array
    {
        $userId = CurrentUser::assert();
        $emp = $this->findEmployeeUser($userId);
        if ($emp === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'employee.not_registered', 'message' => 'No employee record is linked to your account.'],
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
    private function findEmployeeUser(int $userId): ?array
    {
        $row = $this->db->table('users')
            ->select(self::USER_COLS)
            ->where('id', $userId)
            ->where('kind', 'employee')
            ->where('archived_at', null)
            ->get()->getRowArray();
        return $row;
    }
}
