<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\EmployeeDto;
use Modules\Clinic\DTOs\StudentDto;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * PatientService — patient registry (Phase 11, recycled from synapse_ag).
 *
 * Ports the legacy StudentModel/EmployeeModel behavior:
 *   - uniqueness of student/employee number, QR and RFID handles;
 *   - LIKE-escaped search (legacy like_escape_helper);
 *   - "primary contact" demotion: marking a contact primary un-marks
 *     the student's other contacts in the same transaction.
 *
 * Records are soft-archived, never deleted. Every mutation writes an
 * audit outbox row inside the same transaction.
 */
final class PatientService extends BaseService
{
    private const STUDENT_COLS = 'id, student_number, first_name, last_name, middle_name, qr_code, rfid_tag, course, year_level, section, date_of_birth, gender, address, blood_type, consecutive_no_shows, archived_at, created_at, updated_at';
    private const EMPLOYEE_COLS = 'id, user_id, employee_number, first_name, last_name, middle_name, qr_code, rfid_tag, department, position, date_hired, employment_status, hr_synced_at, emergency_contact_name, emergency_contact_phone, date_of_birth, gender, address, is_teaching, archived_at, created_at, updated_at';

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
    public function listStudents(?string $cursor, int $limit): array
    {
        $this->policy->check('patientsRead');

        $builder = $this->db->table('patients_students')
            ->select(self::STUDENT_COLS)
            ->where('archived_at', null)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(static fn (array $r) => StudentDto::fromRow($r)->toArray(), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * Legacy `StudentController::search` — bounded LIKE search on
     * number + names. The pattern is escaped so `%`/`_` in user input
     * match literally (ported like_escape_helper behavior).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchStudents(string $q, int $limit = 20): array
    {
        $this->policy->check('patientsRead');

        $limit = max(1, min($limit, 50));

        $builder = $this->db->table('patients_students')
            ->select(self::STUDENT_COLS)
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
            static fn (array $r) => StudentDto::fromRow($r)->toArray(),
            $builder->get()->getResultArray(),
        );
    }

    /** Detail view — includes allergies + emergency contacts. */
    public function getStudent(int $id): StudentDto
    {
        $this->policy->check('patientsRead');

        $row = $this->db->table('patients_students')
            ->select(self::STUDENT_COLS)
            ->where('id', $id)
            ->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Student #{$id} not found."],
            ]);
        }

        $allergies = $this->db->table('patient_allergies')
            ->select('id, allergen, severity, reaction')
            ->where('student_id', $id)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $contacts = $this->db->table('patient_contacts')
            ->select('id, contact_name, relationship, phone, is_primary')
            ->where('student_id', $id)
            ->orderBy('is_primary', 'DESC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return StudentDto::fromRow($row, $allergies, $contacts);
    }

    /**
     * @param array<string, mixed> $input validated payload
     */
    public function createStudent(array $input): StudentDto
    {
        $this->policy->check('patientsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): StudentDto {
            $this->assertHandleUnique('patients_students', 'student_number', (string) $input['student_number'], null);
            $this->assertOptionalHandleUnique('patients_students', 'qr_code', $input['qr_code'] ?? null, null);
            $this->assertOptionalHandleUnique('patients_students', 'rfid_tag', $input['rfid_tag'] ?? null, null);

            $now = $this->utcNow();

            $this->db->table('patients_students')->insert([
                'student_number' => (string) $input['student_number'],
                'first_name'     => (string) $input['first_name'],
                'last_name'      => (string) $input['last_name'],
                'middle_name'    => $this->strOrNull($input, 'middle_name'),
                'qr_code'        => $this->strOrNull($input, 'qr_code'),
                'rfid_tag'       => $this->strOrNull($input, 'rfid_tag'),
                'course'         => $this->strOrNull($input, 'course'),
                'year_level'     => isset($input['year_level']) && $input['year_level'] !== '' ? (int) $input['year_level'] : null,
                'section'        => $this->strOrNull($input, 'section'),
                'date_of_birth'  => $this->strOrNull($input, 'date_of_birth'),
                'gender'         => $this->strOrNull($input, 'gender'),
                'address'        => $this->strOrNull($input, 'address'),
                'blood_type'     => $this->strOrNull($input, 'blood_type'),
                'consecutive_no_shows' => 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'clinic.patient_student_created',
                'patients_students',
                $id,
                $userId,
                ['resource_code' => 'student#' . (string) $input['student_number']],
            );

            return $this->getStudentRowDto($id);
        });
    }

    /**
     * Partial update. `student_number` is immutable (it is the logical
     * join key used by encounters/sessions/referrals).
     *
     * @param array<string, mixed> $input
     */
    public function updateStudent(int $id, array $input): StudentDto
    {
        $this->policy->check('patientsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $input, $userId): StudentDto {
            $row = $this->selectForUpdate('patients_students', ['id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Student #{$id} not found."],
                ]);
            }

            $this->assertOptionalHandleUnique('patients_students', 'qr_code', $input['qr_code'] ?? null, $id);
            $this->assertOptionalHandleUnique('patients_students', 'rfid_tag', $input['rfid_tag'] ?? null, $id);

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
                $this->db->table('patients_students')->where('id', $id)->update($update);

                $this->audit->enqueue(
                    'clinic.patient_student_updated',
                    'patients_students',
                    $id,
                    $userId,
                    ['fields' => implode(',', array_keys($update))],
                );
            }

            return $this->getStudentRowDto($id);
        });
    }

    public function setStudentArchived(int $id, bool $archived): StudentDto
    {
        $this->policy->check('patientsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $archived, $userId): StudentDto {
            $row = $this->selectForUpdate('patients_students', ['id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Student #{$id} not found."],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('patients_students')->where('id', $id)->update([
                'archived_at' => $archived ? $now : null,
                'updated_at'  => $now,
            ]);

            $this->audit->enqueue(
                $archived ? 'clinic.patient_student_archived' : 'clinic.patient_student_restored',
                'patients_students',
                $id,
                $userId,
                [],
            );

            return $this->getStudentRowDto($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function addAllergy(int $studentId, array $input): StudentDto
    {
        $this->policy->check('patientsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($studentId, $input, $userId): StudentDto {
            $row = $this->selectForUpdate('patients_students', ['id' => $studentId]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Student #{$studentId} not found."],
                ]);
            }

            $this->db->table('patient_allergies')->insert([
                'student_id'       => $studentId,
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
    public function addContact(int $studentId, array $input): StudentDto
    {
        $this->policy->check('patientsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($studentId, $input, $userId): StudentDto {
            $row = $this->selectForUpdate('patients_students', ['id' => $studentId]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Student #{$studentId} not found."],
                ]);
            }

            $isPrimary = (bool) ($input['is_primary'] ?? false);
            if ($isPrimary) {
                $this->db->table('patient_contacts')
                    ->where('student_id', $studentId)
                    ->update(['is_primary' => 0]);
            }

            $this->db->table('patient_contacts')->insert([
                'student_id'   => $studentId,
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
    public function listEmployees(?string $cursor, int $limit): array
    {
        $this->policy->check('patientsRead');

        $builder = $this->db->table('patients_employees')
            ->select(self::EMPLOYEE_COLS)
            ->where('archived_at', null)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(static fn (array $r) => EmployeeDto::fromRow($r)->toArray(), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createEmployee(array $input): EmployeeDto
    {
        $this->policy->check('patientsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): EmployeeDto {
            $this->assertHandleUnique('patients_employees', 'employee_number', (string) $input['employee_number'], null);
            $this->assertOptionalHandleUnique('patients_employees', 'qr_code', $input['qr_code'] ?? null, null);
            $this->assertOptionalHandleUnique('patients_employees', 'rfid_tag', $input['rfid_tag'] ?? null, null);

            $now = $this->utcNow();

            $this->db->table('patients_employees')->insert([
                'employee_number'         => (string) $input['employee_number'],
                'first_name'              => (string) $input['first_name'],
                'last_name'               => (string) $input['last_name'],
                'middle_name'             => $this->strOrNull($input, 'middle_name'),
                'qr_code'                 => $this->strOrNull($input, 'qr_code'),
                'rfid_tag'                => $this->strOrNull($input, 'rfid_tag'),
                'department'              => $this->strOrNull($input, 'department'),
                'position'                => $this->strOrNull($input, 'position'),
                'date_hired'              => $this->strOrNull($input, 'date_hired'),
                'employment_status'       => (string) ($input['employment_status'] ?? 'active'),
                'emergency_contact_name'  => $this->strOrNull($input, 'emergency_contact_name'),
                'emergency_contact_phone' => $this->strOrNull($input, 'emergency_contact_phone'),
                'date_of_birth'           => $this->strOrNull($input, 'date_of_birth'),
                'gender'                  => $this->strOrNull($input, 'gender'),
                'address'                 => $this->strOrNull($input, 'address'),
                'is_teaching'             => (int) (($input['is_teaching'] ?? false) === true),
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'clinic.patient_employee_created',
                'patients_employees',
                $id,
                $userId,
                ['resource_code' => 'employee#' . (string) $input['employee_number']],
            );

            $row = $this->db->table('patients_employees')->select(self::EMPLOYEE_COLS)->where('id', $id)->get()->getRowArray();
            return EmployeeDto::fromRow($row);
        });
    }

    public function getEmployee(int $id): EmployeeDto
    {
        $this->policy->check('patientsRead');
        $row = $this->db->table('patients_employees')->select(self::EMPLOYEE_COLS)->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Employee #{$id} not found."],
            ]);
        }
        return EmployeeDto::fromRow($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchEmployees(string $q, int $limit = 20): array
    {
        $this->policy->check('patientsRead');
        $limit = max(1, min($limit, 50));

        $rows = $this->db->table('patients_employees')
            ->select(self::EMPLOYEE_COLS)
            ->groupStart()
                ->like('employee_number', $q)
                ->orLike('first_name', $q)
                ->orLike('last_name', $q)
            ->groupEnd()
            ->where('archived_at', null)
            ->orderBy('last_name', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static fn (array $r) => EmployeeDto::fromRow($r)->toArray(), $rows);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateEmployee(int $id, array $input): EmployeeDto
    {
        $this->policy->check('patientsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $input, $userId): EmployeeDto {
            $row = $this->selectForUpdate('patients_employees', ['id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Employee #{$id} not found."],
                ]);
            }

            $this->assertOptionalHandleUnique('patients_employees', 'qr_code', $input['qr_code'] ?? null, $id);
            $this->assertOptionalHandleUnique('patients_employees', 'rfid_tag', $input['rfid_tag'] ?? null, $id);

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
                $this->db->table('patients_employees')->where('id', $id)->update($update);
                $this->audit->enqueue('clinic.patient_employee_updated', 'patients_employees', $id, $userId, [
                    'fields' => implode(',', array_keys($update)),
                ]);
            }

            $fresh = $this->db->table('patients_employees')->select(self::EMPLOYEE_COLS)->where('id', $id)->get()->getRowArray();
            return EmployeeDto::fromRow($fresh);
        });
    }

    public function setEmployeeArchived(int $id, bool $archived): EmployeeDto
    {
        $this->policy->check('patientsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $archived, $userId): EmployeeDto {
            $row = $this->selectForUpdate('patients_employees', ['id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Employee #{$id} not found."],
                ]);
            }
            $now = $this->utcNow();
            $this->db->table('patients_employees')->where('id', $id)->update([
                'archived_at' => $archived ? $now : null,
                'updated_at'  => $now,
            ]);
            $this->audit->enqueue(
                $archived ? 'clinic.patient_employee_archived' : 'clinic.patient_employee_restored',
                'patients_employees',
                $id,
                $userId,
                [],
            );
            $fresh = $this->db->table('patients_employees')->select(self::EMPLOYEE_COLS)->where('id', $id)->get()->getRowArray();
            return EmployeeDto::fromRow($fresh);
        });
    }

    /**
     * Idempotent HR sync: upsert employees by `employee_number` (ports
     * the legacy `EmployeeController::syncFromHr` shape without the
     * external HR system). Existing rows are updated + stamped with
     * `hr_synced_at`; new numbers are inserted.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array{created: int, updated: int}
     */
    public function syncHrEmployees(array $records): array
    {
        $this->policy->check('patientsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($records, $userId): array {
            $now = $this->utcNow();
            $created = 0;
            $updated = 0;

            foreach ($records as $rec) {
                $number = trim((string) ($rec['employee_number'] ?? ''));
                if ($number === '') {
                    continue;
                }
                $existing = $this->db->table('patients_employees')->where('employee_number', $number)->get()->getRowArray();

                $fields = [
                    'first_name'        => (string) ($rec['first_name'] ?? ($existing['first_name'] ?? '')),
                    'last_name'         => (string) ($rec['last_name'] ?? ($existing['last_name'] ?? '')),
                    'department'        => isset($rec['department']) && $rec['department'] !== '' ? (string) $rec['department'] : ($existing['department'] ?? null),
                    'position'          => isset($rec['position']) && $rec['position'] !== '' ? (string) $rec['position'] : ($existing['position'] ?? null),
                    'employment_status' => (string) ($rec['employment_status'] ?? ($existing['employment_status'] ?? 'active')),
                    'hr_synced_at'      => $now,
                    'updated_at'        => $now,
                ];

                if ($existing !== null) {
                    $this->db->table('patients_employees')->where('id', (int) $existing['id'])->update($fields);
                    $updated++;
                } else {
                    $this->db->table('patients_employees')->insert($fields + [
                        'employee_number' => $number,
                        'created_at'      => $now,
                    ]);
                    $created++;
                }
            }

            $this->audit->enqueue('clinic.employees_hr_synced', 'patients_employees', 0, $userId, [
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

    private function getStudentRowDto(int $id): StudentDto
    {
        $row = $this->db->table('patients_students')
            ->select(self::STUDENT_COLS)
            ->where('id', $id)
            ->get()->getRowArray();
        return StudentDto::fromRow($row);
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
