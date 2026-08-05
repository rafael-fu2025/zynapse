<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\UserDto;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * PatientService — patient registry (identity-consolidated).
 *
 * Every patient IS a `users` row with a `kind` discriminator
 * ('student' | 'employee'). There is no separate person/patient table and
 * no user↔patient linking: creating a student/employee creates the user
 * AND its portal account (auth_identities + role) in one transaction.
 *
 * Records are soft-archived via `users.archived_at`, never deleted. Every
 * mutation writes an audit outbox row inside the same transaction.
 */
final class PatientService extends BaseService
{
    private const USER_COLS = 'id, kind, first_name, last_name, middle_name, qr_code, rfid_tag, date_of_birth, gender, address, archived_at, student_number, course, year_level, section, blood_type, consecutive_no_shows, employee_number, department, position, date_hired, employment_status, hr_synced_at, emergency_contact_name, emergency_contact_phone, is_teaching, created_at, updated_at';

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    // ----------------------------------------------------------- students

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listStudents(?string $cursor, int $limit, bool $includeArchived = false): array
    {
        $this->policy->check('patientsRead');

        $builder = $this->db->table('users')
            ->select(self::USER_COLS)
            ->where('kind', 'student')
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        if (! $includeArchived) {
            $builder->where('archived_at', null);
        }

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(static fn (array $r) => UserDto::fromRow($r)->toArray(), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * Bounded LIKE search on number + names (escaped so `%`/`_` in user
     * input match literally).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchStudents(string $q, int $limit = 20): array
    {
        $this->policy->check('patientsRead');

        $limit = max(1, min($limit, 50));

        $builder = $this->db->table('users')
            ->select(self::USER_COLS)
            ->where('kind', 'student')
            ->where('archived_at', null)
            ->groupStart()
                ->like('student_number', $q)
                ->orLike('first_name', $q)
                ->orLike('last_name', $q)
            ->groupEnd()
            ->orderBy('last_name', 'ASC')
            ->orderBy('first_name', 'ASC')
            ->limit($limit);

        return array_map(
            static fn (array $r) => UserDto::fromRow($r)->toArray(),
            $builder->get()->getResultArray(),
        );
    }

    /** Detail view — includes allergies + emergency contacts. */
    public function getStudent(int $id): UserDto
    {
        $this->policy->check('patientsRead');

        $row = $this->db->table('users')
            ->select(self::USER_COLS)
            ->where('id', $id)
            ->where('kind', 'student')
            ->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Student #{$id} not found."],
            ]);
        }

        $allergies = $this->db->table('patient_allergies')
            ->select('id, allergen, severity, reaction')
            ->where('user_id', $id)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $contacts = $this->db->table('patient_contacts')
            ->select('id, contact_name, relationship, phone, is_primary')
            ->where('user_id', $id)
            ->orderBy('is_primary', 'DESC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return UserDto::fromRow($row, $allergies, $contacts);
    }

    /**
     * Auto-account: creating a student creates the user (kind=student)
     * AND its portal account (auth_identities with temporary password +
     * `student` role) in one transaction. There is no `create_account`
     * flag — it is always on. Returns the UserDto and the portal-account
     * envelope so the admin can share the temporary password once.
     *
     * @param array<string, mixed> $input validated payload
     * @return array{0: UserDto, 1: array{email: string, temporary_password: string, user_id: int}}
     */
    public function createStudent(array $input): array
    {
        $this->policy->check('patientsWrite');
        $userId = CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): array {
            $this->assertHandleUnique('users', 'student_number', (string) $input['student_number'], null);
            $this->assertOptionalHandleUnique('users', 'qr_code', $input['qr_code'] ?? null, null);
            $this->assertOptionalHandleUnique('users', 'rfid_tag', $input['rfid_tag'] ?? null, null);

            $now = $this->utcNow();

            $this->db->table('users')->insert([
                'kind'                => 'student',
                'student_number'      => (string) $input['student_number'],
                'first_name'          => (string) $input['first_name'],
                'last_name'           => (string) $input['last_name'],
                'middle_name'         => $this->strOrNull($input, 'middle_name'),
                'qr_code'             => $this->strOrNull($input, 'qr_code'),
                'rfid_tag'            => $this->strOrNull($input, 'rfid_tag'),
                'course'              => $this->strOrNull($input, 'course'),
                'year_level'          => isset($input['year_level']) && $input['year_level'] !== '' ? (int) $input['year_level'] : null,
                'section'             => $this->strOrNull($input, 'section'),
                'date_of_birth'       => $this->strOrNull($input, 'date_of_birth'),
                'gender'              => $this->strOrNull($input, 'gender'),
                'address'             => $this->strOrNull($input, 'address'),
                'blood_type'          => $this->strOrNull($input, 'blood_type'),
                'consecutive_no_shows' => 0,
                'status'              => 'active',
                'active'              => 1,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'clinic.patient_student_created',
                'users',
                $id,
                $userId,
                ['resource_code' => 'student#' . (string) $input['student_number']],
            );

            $accountEmail = isset($input['account_email']) && $input['account_email'] !== ''
                ? strtolower(trim((string) $input['account_email']))
                : strtolower((string) $input['student_number']) . '@synapse.dev';
            $portalAccount = $this->createAccount($id, 'student', $accountEmail, $userId);

            return [$this->getUserRowDto($id), $portalAccount];
        });
    }

    /**
     * Partial update. `student_number` is immutable (it is the logical
     * join key used by encounters/sessions/referrals).
     *
     * @param array<string, mixed> $input
     */
    public function updateStudent(int $id, array $input): UserDto
    {
        $this->policy->check('patientsWrite');
        $userId = CurrentUser::assert();

        return $this->txn(function () use ($id, $input, $userId): UserDto {
            $row = $this->selectForUpdate('users', ['id' => $id, 'kind' => 'student']);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Student #{$id} not found."],
                ]);
            }

            $this->assertOptionalHandleUnique('users', 'qr_code', $input['qr_code'] ?? null, $id);
            $this->assertOptionalHandleUnique('users', 'rfid_tag', $input['rfid_tag'] ?? null, $id);

            $update = [];
            foreach (['first_name', 'last_name', 'middle_name', 'qr_code', 'rfid_tag', 'course', 'section', 'date_of_birth', 'gender', 'address', 'blood_type'] as $col) {
                if (array_key_exists($col, $input)) {
                    $update[$col] = $input[$col] !== '' && $input[$col] !== null ? (string) $input[$col] : null;
                }
            }
            if (array_key_exists('year_level', $input)) {
                $update['year_level'] = $input['year_level'] !== null && $input['year_level'] !== '' ? (int) $input['year_level'] : null;
            }

            if ($update !== []) {
                $update['updated_at'] = $this->utcNow();
                $this->db->table('users')->where('id', $id)->where('kind', 'student')->update($update);

                $this->audit->enqueue(
                    'clinic.patient_student_updated',
                    'users',
                    $id,
                    $userId,
                    ['fields' => implode(',', array_keys($update))],
                );
            }

            return $this->getUserRowDto($id);
        });
    }

    public function setStudentArchived(int $id, bool $archived): UserDto
    {
        $this->policy->check('patientsWrite');
        $userId = CurrentUser::assert();

        return $this->txn(function () use ($id, $archived, $userId): UserDto {
            $row = $this->selectForUpdate('users', ['id' => $id, 'kind' => 'student']);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Student #{$id} not found."],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('users')->where('id', $id)->where('kind', 'student')->update([
                'archived_at' => $archived ? $now : null,
                'updated_at'  => $now,
            ]);

            $this->audit->enqueue(
                $archived ? 'clinic.patient_student_archived' : 'clinic.patient_student_restored',
                'users',
                $id,
                $userId,
                [],
            );

            return $this->getUserRowDto($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function addAllergy(int $studentId, array $input): UserDto
    {
        $this->policy->check('patientsWrite');
        $userId = CurrentUser::assert();

        return $this->txn(function () use ($studentId, $input, $userId): UserDto {
            $row = $this->selectForUpdate('users', ['id' => $studentId, 'kind' => 'student']);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Student #{$studentId} not found."],
                ]);
            }

            $this->db->table('patient_allergies')->insert([
                'user_id'          => $studentId,
                'allergen'         => (string) $input['allergen'],
                'severity'         => (string) ($input['severity'] ?? 'mild'),
                'reaction'         => $this->strOrNull($input, 'reaction'),
                'noted_by_user_id' => $userId,
                'created_at'       => $this->utcNow(),
            ]);

            $this->audit->enqueue(
                'clinic.patient_allergy_added',
                'patient_allergies',
                (int) $this->db->insertID(),
                $userId,
                ['severity' => (string) ($input['severity'] ?? 'mild')],
            );

            return $this->getStudent($studentId);
        });
    }

    /**
     * Legacy behavior: marking a contact primary demotes the student's
     * other contacts inside the same transaction.
     *
     * @param array<string, mixed> $input
     */
    public function addContact(int $studentId, array $input): UserDto
    {
        $this->policy->check('patientsWrite');
        $userId = CurrentUser::assert();

        return $this->txn(function () use ($studentId, $input, $userId): UserDto {
            $row = $this->selectForUpdate('users', ['id' => $studentId, 'kind' => 'student']);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Student #{$studentId} not found."],
                ]);
            }

            $isPrimary = (bool) ($input['is_primary'] ?? false);
            if ($isPrimary) {
                $this->db->table('patient_contacts')
                    ->where('user_id', $studentId)
                    ->update(['is_primary' => 0]);
            }

            $this->db->table('patient_contacts')->insert([
                'user_id'      => $studentId,
                'contact_name' => (string) $input['contact_name'],
                'relationship' => (string) $input['relationship'],
                'phone'        => (string) $input['phone'],
                'is_primary'   => $isPrimary ? 1 : 0,
                'created_at'   => $this->utcNow(),
            ]);

            $this->audit->enqueue(
                'clinic.patient_contact_added',
                'patient_contacts',
                (int) $this->db->insertID(),
                $userId,
                [],
            );

            return $this->getStudent($studentId);
        });
    }

    // ---------------------------------------------------------- employees

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listEmployees(?string $cursor, int $limit, bool $includeArchived = false, ?string $teaching = null): array
    {
        $this->policy->check('patientsRead');

        $builder = $this->db->table('users')
            ->select(self::USER_COLS)
            ->where('kind', 'employee')
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        if (! $includeArchived) {
            $builder->where('archived_at', null);
        }

        // Teaching / non-teaching triage. Non-teaching also captures
        // NULL (HR-synced rows without the flag), which renders as
        // non-teaching in the UI.
        if ($teaching === 'teaching') {
            $builder->where('is_teaching', 1);
        } elseif ($teaching === 'non_teaching') {
            $builder->groupStart()
                ->where('is_teaching', 0)
                ->orWhere('is_teaching', null)
                ->groupEnd();
        }

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(static fn (array $r) => UserDto::fromRow($r)->toArray(), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * Auto-account: creating an employee creates the user (kind=employee)
     * AND its portal account (auth_identities with temporary password +
     * `employee` role) in one transaction.
     *
     * @param array<string, mixed> $input
     * @return array{0: UserDto, 1: array{email: string, temporary_password: string, user_id: int}}
     */
    public function createEmployee(array $input): array
    {
        $this->policy->check('patientsWrite');
        $userId = CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): array {
            $this->assertHandleUnique('users', 'employee_number', (string) $input['employee_number'], null);
            $this->assertOptionalHandleUnique('users', 'qr_code', $input['qr_code'] ?? null, null);
            $this->assertOptionalHandleUnique('users', 'rfid_tag', $input['rfid_tag'] ?? null, null);

            $now = $this->utcNow();

            $this->db->table('users')->insert([
                'kind'                   => 'employee',
                'employee_number'        => (string) $input['employee_number'],
                'first_name'             => (string) $input['first_name'],
                'last_name'              => (string) $input['last_name'],
                'middle_name'            => $this->strOrNull($input, 'middle_name'),
                'qr_code'                => $this->strOrNull($input, 'qr_code'),
                'rfid_tag'               => $this->strOrNull($input, 'rfid_tag'),
                'department'             => $this->strOrNull($input, 'department'),
                'position'               => $this->strOrNull($input, 'position'),
                'date_hired'             => $this->strOrNull($input, 'date_hired'),
                'employment_status'      => (string) ($input['employment_status'] ?? 'active'),
                'emergency_contact_name' => $this->strOrNull($input, 'emergency_contact_name'),
                'emergency_contact_phone'=> $this->strOrNull($input, 'emergency_contact_phone'),
                'date_of_birth'          => $this->strOrNull($input, 'date_of_birth'),
                'gender'                 => $this->strOrNull($input, 'gender'),
                'address'                => $this->strOrNull($input, 'address'),
                'is_teaching'            => (int) (($input['is_teaching'] ?? false) === true),
                'status'                 => 'active',
                'active'                 => 1,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'clinic.patient_employee_created',
                'users',
                $id,
                $userId,
                ['resource_code' => 'employee#' . (string) $input['employee_number']],
            );

            $accountEmail = isset($input['account_email']) && $input['account_email'] !== ''
                ? strtolower(trim((string) $input['account_email']))
                : strtolower((string) $input['employee_number']) . '@synapse.dev';
            $portalAccount = $this->createAccount($id, 'employee', $accountEmail, $userId);

            return [$this->getUserRowDto($id), $portalAccount];
        });
    }

    /**
     * Create the portal account for a freshly-created patient user: an
     * `auth_identities` row (email_password, temporary password,
     * force_reset=1) plus role membership (`student`/`employee` group).
     * Returns the portal-account envelope for one-time sharing.
     *
     * @return array{email: string, temporary_password: string, user_id: int}
     */
    private function createAccount(int $userId, string $kind, string $email, int $actorUserId): array
    {
        $now = $this->utcNow();

        $temporaryPassword = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
        $this->db->table('auth_identities')->insert([
            'user_id'     => $userId,
            'type'        => 'email_password',
            'secret'      => $email,
            'secret2'     => password_hash($temporaryPassword, PASSWORD_DEFAULT),
            'force_reset' => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->assignGroup($userId, $kind === 'student' ? 'student' : 'employee');

        $this->audit->enqueue(
            'admin.portal_account_minted',
            'users',
            $userId,
            $actorUserId,
            [
                'resource_code' => "patient#{$kind}:{$email}",
                'next_status'   => 'active',
            ],
        );

        return [
            'email'              => $email,
            'temporary_password' => $temporaryPassword,
            'user_id'            => $userId,
        ];
    }

    /**
     * Assign a role group to a user (idempotent). The group must exist in
     * `auth_groups` (seeded from Config\AuthGroups); missing groups are
     * skipped so a stale config never breaks account creation.
     */
    private function assignGroup(int $userId, string $group): void
    {
        $gid = $this->db->table('auth_groups')->select('id')->where('name', $group)->get()->getRowArray();
        if ($gid === null) {
            return;
        }
        $exists = $this->db->table('auth_groups_users')
            ->where(['group_id' => (int) $gid['id'], 'user_id' => $userId])
            ->get()->getRowArray();
        if ($exists !== null) {
            return;
        }
        $this->db->table('auth_groups_users')->insert([
            'group_id'   => (int) $gid['id'],
            'user_id'    => $userId,
            'created_at' => $this->utcNow(),
        ]);
    }

    public function getEmployee(int $id): UserDto
    {
        $this->policy->check('patientsRead');
        $row = $this->db->table('users')
            ->select(self::USER_COLS)
            ->where('id', $id)
            ->where('kind', 'employee')
            ->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Employee #{$id} not found."],
            ]);
        }
        return UserDto::fromRow($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchEmployees(string $q, int $limit = 20): array
    {
        $this->policy->check('patientsRead');
        $limit = max(1, min($limit, 50));

        $rows = $this->db->table('users')
            ->select(self::USER_COLS)
            ->where('kind', 'employee')
            ->groupStart()
                ->like('employee_number', $q)
                ->orLike('first_name', $q)
                ->orLike('last_name', $q)
            ->groupEnd()
            ->where('archived_at', null)
            ->orderBy('last_name', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static fn (array $r) => UserDto::fromRow($r)->toArray(), $rows);
    }

    /**
     * Kiosk station autocomplete — lightweight combined lookup across
     * BOTH patient kinds (students + employees) by number, last name, or
     * first name. Returns a minimal shape (id, kind, name, school_id)
     * sized for a touch dropdown; the kiosk then checks the patient in
     * by school_id through the normal check-in path.
     *
     * @return array<int, array{id: int, kind: string, name: string, school_id: string}>
     */
    public function lookupForKiosk(string $q, int $limit = 8): array
    {
        $this->policy->check('patientsRead');
        $limit = max(1, min($limit, 12));

        $rows = $this->db->table('users')
            ->select('id, kind, first_name, last_name, middle_name, student_number, employee_number')
            ->whereIn('kind', ['student', 'employee'])
            ->where('archived_at', null)
            ->groupStart()
                ->like('student_number', $q)
                ->orLike('employee_number', $q)
                ->orLike('last_name', $q)
                ->orLike('first_name', $q)
            ->groupEnd()
            ->orderBy('last_name', 'ASC')
            ->orderBy('first_name', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $schoolId = (string) ($r['kind'] === 'student' ? $r['student_number'] : $r['employee_number']);
            $middle   = $r['middle_name'] !== null && $r['middle_name'] !== ''
                ? ' ' . mb_substr((string) $r['middle_name'], 0, 1) . '.'
                : '';
            $out[] = [
                'id'        => (int) $r['id'],
                'kind'      => (string) $r['kind'],
                'name'      => trim((string) $r['last_name'] . ', ' . (string) $r['first_name'] . $middle),
                'school_id' => $schoolId,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateEmployee(int $id, array $input): UserDto
    {
        $this->policy->check('patientsWrite');
        $userId = CurrentUser::assert();

        return $this->txn(function () use ($id, $input, $userId): UserDto {
            $row = $this->selectForUpdate('users', ['id' => $id, 'kind' => 'employee']);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Employee #{$id} not found."],
                ]);
            }

            $this->assertOptionalHandleUnique('users', 'qr_code', $input['qr_code'] ?? null, $id);
            $this->assertOptionalHandleUnique('users', 'rfid_tag', $input['rfid_tag'] ?? null, $id);

            $update = [];
            foreach (['first_name', 'last_name', 'middle_name', 'qr_code', 'rfid_tag', 'department', 'position', 'date_hired', 'employment_status', 'emergency_contact_name', 'emergency_contact_phone', 'date_of_birth', 'gender', 'address'] as $col) {
                if (array_key_exists($col, $input)) {
                    $update[$col] = $input[$col] !== '' && $input[$col] !== null ? (string) $input[$col] : null;
                }
            }
            // is_teaching is a boolean column; the boolean check below
            // is separate from the string list above so we don't run a
            // `!== ''` guard on a boolean.
            if (array_key_exists('is_teaching', $input)) {
                $update['is_teaching'] = (int) ($input['is_teaching'] === true);
            }
            if (isset($update['employment_status']) && $update['employment_status'] === null) {
                unset($update['employment_status']); // never null the enum
            }

            if ($update !== []) {
                $update['updated_at'] = $this->utcNow();
                $this->db->table('users')->where('id', $id)->where('kind', 'employee')->update($update);
                $this->audit->enqueue('clinic.patient_employee_updated', 'users', $id, $userId, [
                    'fields' => implode(',', array_keys($update)),
                ]);
            }

            return $this->getUserRowDto($id);
        });
    }

    public function setEmployeeArchived(int $id, bool $archived): UserDto
    {
        $this->policy->check('patientsWrite');
        $userId = CurrentUser::assert();

        return $this->txn(function () use ($id, $archived, $userId): UserDto {
            $row = $this->selectForUpdate('users', ['id' => $id, 'kind' => 'employee']);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Employee #{$id} not found."],
                ]);
            }
            $now = $this->utcNow();
            $this->db->table('users')->where('id', $id)->where('kind', 'employee')->update([
                'archived_at' => $archived ? $now : null,
                'updated_at'  => $now,
            ]);
            $this->audit->enqueue(
                $archived ? 'clinic.patient_employee_archived' : 'clinic.patient_employee_restored',
                'users',
                $id,
                $userId,
                [],
            );
            return $this->getUserRowDto($id);
        });
    }

    /**
     * Idempotent HR sync: upsert employee users by `employee_number`.
     * Existing rows are updated + stamped with `hr_synced_at`; new numbers
     * are inserted as identity-only `users` rows (kind=employee, no
     * credentials — HR sync never issues logins; admins mint accounts
     * via the admin user flow).
     *
     * @param array<int, array<string, mixed>> $records
     * @return array{created: int, updated: int}
     */
    public function syncHrEmployees(array $records): array
    {
        $this->policy->check('patientsWrite');
        $userId = CurrentUser::assert();

        return $this->txn(function () use ($records, $userId): array {
            $now = $this->utcNow();
            $created = 0;
            $updated = 0;

            foreach ($records as $rec) {
                $number = trim((string) ($rec['employee_number'] ?? ''));
                if ($number === '') {
                    continue;
                }
                $existing = $this->db->table('users')
                    ->where('kind', 'employee')
                    ->where('employee_number', $number)
                    ->get()->getRowArray();

                // is_teaching is populated from the HR record when present
                // (bool / 1 / '1'); otherwise the existing value is kept
                // (audit fix — previously the flag was never synced, so
                // HR-imported faculty silently lost the ability to refer).
                $teachingValue = isset($rec['is_teaching'])
                    ? (int) ($rec['is_teaching'] === true || $rec['is_teaching'] === 1 || $rec['is_teaching'] === '1' || $rec['is_teaching'] === 'true')
                    : ($existing['is_teaching'] ?? 0);

                $fields = [
                    'first_name'        => (string) ($rec['first_name'] ?? ($existing['first_name'] ?? '')),
                    'last_name'         => (string) ($rec['last_name'] ?? ($existing['last_name'] ?? '')),
                    'department'        => isset($rec['department']) && $rec['department'] !== '' ? (string) $rec['department'] : ($existing['department'] ?? null),
                    'position'          => isset($rec['position']) && $rec['position'] !== '' ? (string) $rec['position'] : ($existing['position'] ?? null),
                    'employment_status' => (string) ($rec['employment_status'] ?? ($existing['employment_status'] ?? 'active')),
                    'is_teaching'       => $teachingValue,
                    'hr_synced_at'      => $now,
                    'updated_at'        => $now,
                ];

                if ($existing !== null) {
                    $this->db->table('users')->where('id', (int) $existing['id'])->where('kind', 'employee')->update($fields);
                    $updated++;
                } else {
                    $this->db->table('users')->insert($fields + [
                        'kind'           => 'employee',
                        'employee_number' => $number,
                        'status'         => 'active',
                        'active'         => 1,
                        'created_at'     => $now,
                    ]);
                    $created++;
                }
            }

            $this->audit->enqueue('clinic.employees_hr_synced', 'users', 0, $userId, [
                'resource_code' => 'created#' . $created . '_updated#' . $updated,
            ]);

            return ['created' => $created, 'updated' => $updated];
        });
    }

    // ---------------------------------------------------------- departments

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDepartments(bool $activeOnly = false): array
    {
        $this->policy->check('patientsRead');

        $builder = $this->db->table('clinic_departments')
            ->select('id, name, code, description, is_active')
            ->orderBy('name', 'ASC');
        if ($activeOnly) {
            $builder->where('is_active', 1);
        }

        return array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'name'        => (string) $r['name'],
            'code'        => (string) $r['code'],
            'description' => $r['description'] !== null ? (string) $r['description'] : null,
            'is_active'   => (bool) $r['is_active'],
        ], $builder->get()->getResultArray());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createDepartment(array $input): array
    {
        $this->policy->check('departmentsManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): array {
            $this->assertHandleUnique('clinic_departments', 'code', (string) $input['code'], null);
            $this->assertHandleUnique('clinic_departments', 'name', (string) $input['name'], null);

            $now = $this->utcNow();
            $this->db->table('clinic_departments')->insert([
                'name'        => (string) $input['name'],
                'code'        => (string) $input['code'],
                'description' => $this->strOrNull($input, 'description'),
                'is_active'   => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('clinic.department_created', 'clinic_departments', $id, $userId, [
                'resource_code' => (string) $input['code'],
            ]);

            return ['id' => $id, 'name' => (string) $input['name'], 'code' => (string) $input['code'], 'is_active' => true];
        });
    }

    // ------------------------------------------------------------ helpers

    private function getUserRowDto(int $id): UserDto
    {
        $row = $this->db->table('users')
            ->select(self::USER_COLS)
            ->where('id', $id)
            ->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "User #{$id} not found."],
            ]);
        }
        return UserDto::fromRow($row);
    }

    private function assertHandleUnique(string $table, string $column, string $value, ?int $exceptId): void
    {
        $builder = $this->db->table($table)->where($column, $value);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }
        if ($builder->get()->getRowArray() !== null) {
            throw new ApiException('resource.conflict', 409, [
                ['code' => 'resource.conflict', 'message' => "{$column} '{$value}' already exists.", 'field' => $column],
            ]);
        }
    }

    private function assertOptionalHandleUnique(string $table, string $column, mixed $value, ?int $exceptId): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $this->assertHandleUnique($table, $column, (string) $value, $exceptId);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function strOrNull(array $input, string $key): ?string
    {
        return isset($input[$key]) && $input[$key] !== '' ? (string) $input[$key] : null;
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
