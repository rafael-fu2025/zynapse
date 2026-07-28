<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\EmployeeDto;

/**
 * EmployeeSelfService — Phase 11 employee portal.
 *
 * Every method resolves the caller's `users.id` to the matching
 * `patients_employees` row by looking up the employee's
 * `auth_identities` email and matching it against the
 * employees_employees.email column. The employee record's
 * `employee_number` is the cross-table key we use to scope
 * `clinic_encounters` (the encounters table is keyed by
 * `patient_school_id`, which we treat as either student_number
 * or employee_number depending on the patient kind).
 *
 * If a user has NO matching employee row (e.g. an external auditor
 * or an admin whose identity is in `users` but not in the patient
 * registry), the surface returns 404 — the employee portal is
 * strictly an EMPLOYEE surface, not a generic profile page.
 */
final class EmployeeSelfService extends BaseService
{
    /**
     * Return the calling user's employee record, or throw 404 if
     * the user is not on the patient registry.
     */
    public function getMyProfile(): EmployeeDto
    {
        $userId = CurrentUser::assert();
        $row = $this->findEmployeeRowForUserId($userId);
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'employee.not_registered', 'message' => 'No employee record is linked to your account.'],
            ]);
        }
        return EmployeeDto::fromRow($row);
    }

    /**
     * Self-update of the calling employee's PROFILE fields (Phase 14
     * use case: "Manage Profile (personal info, emergency contacts)").
     *
     * Strict allow-list — only the columns the diagram cares about
     * (name, emergency contact, address, gender, date_of_birth).
     * The caller CANNOT escalate: department, position,
     * employment_status, is_teaching, qr_code, rfid_tag, and
     * employee_number are immutable through this endpoint — those
     * are HR-managed.
     *
     * The audit outbox row is written in the same transaction
     * with the user id of the editor (== caller) so the change
     * is attributable to the staff member.
     *
     * @param array{first_name?:string, middle_name?:?string, last_name?:string, emergency_contact_name?:?string, emergency_contact_phone?:?string, address?:?string, gender?:?string, date_of_birth?:?string} $input
     */
    public function updateMyProfile(array $input): EmployeeDto
    {
        $userId = CurrentUser::assert();
        $existing = $this->findEmployeeRowForUserId($userId);
        if ($existing === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'employee.not_registered', 'message' => 'No employee record is linked to your account.'],
            ]);
        }
        $id = (int) $existing['id'];

        // Allow-list with the simplest coercion rules. We never
        // re-write employee_number / qr_code / rfid_tag / position
        // / department / employment_status / is_teaching here.
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
            // Nothing to do — return the existing row.
            return EmployeeDto::fromRow($existing);
        }

        $patch['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $this->db->table('patients_employees')
            ->where('id', $id)
            ->update($patch);

        $fresh = $this->findEmployeeRowForUserId($userId);
        // The find above filters by `archived_at IS NULL`; after
        // a self-update the row is still not archived, so this
        // returns the post-update shape. If the row somehow got
        // archived between the two reads, fall back to the patch.
        return EmployeeDto::fromRow($fresh ?? array_merge($existing, $patch));
    }

    /**
     * Return the calling employee's own clinic encounters, newest
     * first, capped at 50 rows. Strictly self-scoped: we look up
     * the caller's employee_number, then filter the encounters
     * table on that key. The user CANNOT see anyone else's visits.
     *
     * @return list<array<string, mixed>>
     */
    public function listMyClinicVisits(int $limit = 50): array
    {
        $userId = CurrentUser::assert();
        $emp = $this->findEmployeeRowForUserId($userId);
        if ($emp === null) {
            // Same 404 as the profile so the UX is consistent.
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'employee.not_registered', 'message' => 'No employee record is linked to your account.'],
            ]);
        }
        $employeeNumber = (string) $emp['employee_number'];

        $rows = $this->db->table('clinic_encounters')
            ->select('id, patient_school_id, chief_complaint, triage_priority, status, attending_user_id, started_at, closed_at, created_at')
            ->where('patient_school_id', $employeeNumber)
            ->where('archived_at', null)
            ->orderBy('started_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        // Decorate with the attending clinician's username so the
        // employee can see WHO saw them. Same name-join pattern as
        // the appointments service.
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
     * Resolve a `users.id` to a `patients_employees` row by
     * following the `patients_employees.user_id` UNIQUE link
     * (added by `EmployeeUserLink` migration, Phase 11).
     *
     * Strict lookup: if the link column is NULL, the user is NOT
     * on the registry, the method returns null, and the caller
     * raises 404. We never fall through to an arbitrary employee.
     *
     * @return array<string, mixed>|null
     */
    private function findEmployeeRowForUserId(int $userId): ?array
    {
        $row = $this->db->table('patients_employees')
            ->select('id, user_id, employee_number, first_name, last_name, middle_name, qr_code, rfid_tag, department, position, date_hired, employment_status, hr_synced_at, emergency_contact_name, emergency_contact_phone, date_of_birth, gender, address, is_teaching, archived_at, created_at, updated_at')
            ->where('user_id', $userId)
            ->where('archived_at', null)
            ->get()->getRowArray();
        return $row;
    }
}
