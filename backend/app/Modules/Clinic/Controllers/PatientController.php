<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\PatientService;

/**
 * PatientController — thin endpoints for the patient registry
 * (Phase 11, recycled from legacy synapse_ag students/employees).
 */
final class PatientController extends ApiController
{
    private readonly PatientService $service;

    public function __construct(?PatientService $service = null)
    {
        $this->service = $service ?? new PatientService(new ClinicPolicy(), Services::auditOutbox());
    }

    // ----------------------------------------------------------- students

    public function listStudents(): ResponseInterface
    {
        $cursor   = (string) ($this->request->getGet('cursor') ?? '');
        $limit    = (int)    ($this->request->getGet('limit')  ?? 25);
        $archived = (string) ($this->request->getGet('include_archived') ?? '');

        $page = $this->service->listStudents(
            $cursor !== '' ? $cursor : null,
            $limit,
            $archived === '1' || $archived === 'true',
        );

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function searchStudents(): ResponseInterface
    {
        $q = trim((string) ($this->request->getGet('q') ?? ''));
        if (mb_strlen($q) < 2) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'q must be at least 2 characters.', 'field' => 'q'],
            ]);
        }

        return $this->ok($this->service->searchStudents($q, (int) ($this->request->getGet('limit') ?? 20)));
    }

    public function showStudent(int $id): ResponseInterface
    {
        return $this->ok($this->service->getStudent($id)->toArray());
    }

    public function createStudent(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        if (! $this->makeValidation($this->studentRules(true))->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        [$dto, $portalAccount] = $this->service->createStudent($payload);
        $out = $dto->toArray();
        if ($portalAccount !== null) {
            // Phase 3.5: surface the temporary password alongside the
            // patient row so the admin can share it once. The frontend
            // pops a modal showing these credentials.
            $out['portal_account'] = $portalAccount;
        }
        return $this->ok($out, null, 201);
    }

    public function updateStudent(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        if (! $this->makeValidation($this->studentRules(false))->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->updateStudent($id, $payload)->toArray());
    }

    public function setStudentArchived(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        if (! array_key_exists('archived', $payload) || ! is_bool($payload['archived'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'archived must be a boolean.', 'field' => 'archived'],
            ]);
        }

        return $this->ok($this->service->setStudentArchived($id, $payload['archived'])->toArray());
    }

    public function addAllergy(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'allergen' => 'required|max_length[200]',
            'severity' => 'permit_empty|in_list[mild,moderate,severe]',
            'reaction' => 'permit_empty|max_length[2000]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->addAllergy($id, $payload)->toArray(), null, 201);
    }

    public function addContact(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'contact_name' => 'required|max_length[150]',
            'relationship' => 'required|max_length[50]',
            'phone'        => 'required|max_length[20]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->addContact($id, $payload)->toArray(), null, 201);
    }

    // ---------------------------------------------------------- employees

    public function listEmployees(): ResponseInterface
    {
        $cursor   = (string) ($this->request->getGet('cursor') ?? '');
        $limit    = (int)    ($this->request->getGet('limit')  ?? 25);
        $archived = (string) ($this->request->getGet('include_archived') ?? '');

        $page = $this->service->listEmployees(
            $cursor !== '' ? $cursor : null,
            $limit,
            $archived === '1' || $archived === 'true',
        );

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function createEmployee(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'employee_number'         => 'required|max_length[50]',
            'first_name'              => 'required|max_length[100]',
            'last_name'               => 'required|max_length[100]',
            'middle_name'             => 'permit_empty|max_length[100]',
            'qr_code'                 => 'permit_empty|max_length[255]',
            'rfid_tag'                => 'permit_empty|max_length[255]',
            'department'              => 'permit_empty|max_length[100]',
            'position'                => 'permit_empty|max_length[100]',
            'date_hired'              => 'permit_empty|valid_date[Y-m-d]',
            'employment_status'       => 'permit_empty|in_list[active,inactive,on_leave]',
            'emergency_contact_name'  => 'permit_empty|max_length[150]',
            'emergency_contact_phone' => 'permit_empty|max_length[20]',
            'date_of_birth'           => 'permit_empty|valid_date[Y-m-d]',
            'gender'                  => 'permit_empty|in_list[male,female,other]',
            // Phase 3.5: optional portal-login creation.
            'create_account'          => 'permit_empty|is_bool',
            'account_email'           => 'permit_empty|valid_email|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        [$dto, $portalAccount] = $this->service->createEmployee($payload);
        $out = $dto->toArray();
        if ($portalAccount !== null) {
            // Phase 3.5: same as createStudent above.
            $out['portal_account'] = $portalAccount;
        }
        return $this->ok($out, null, 201);
    }

    public function showEmployee(int $id): ResponseInterface
    {
        return $this->ok($this->service->getEmployee($id)->toArray());
    }

    public function searchEmployees(): ResponseInterface
    {
        $q = (string) ($this->request->getGet('q') ?? '');
        if (strlen(trim($q)) < 2) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Query must be at least 2 characters.', 'field' => 'q'],
            ]);
        }
        return $this->ok($this->service->searchEmployees(trim($q)));
    }

    public function updateEmployee(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'first_name'              => 'permit_empty|max_length[100]',
            'last_name'               => 'permit_empty|max_length[100]',
            'middle_name'             => 'permit_empty|max_length[100]',
            'qr_code'                 => 'permit_empty|max_length[255]',
            'rfid_tag'                => 'permit_empty|max_length[255]',
            'department'              => 'permit_empty|max_length[100]',
            'position'                => 'permit_empty|max_length[100]',
            'date_hired'              => 'permit_empty|valid_date[Y-m-d]',
            'employment_status'       => 'permit_empty|in_list[active,inactive,on_leave]',
            'emergency_contact_name'  => 'permit_empty|max_length[150]',
            'emergency_contact_phone' => 'permit_empty|max_length[20]',
            'date_of_birth'           => 'permit_empty|valid_date[Y-m-d]',
            'gender'                  => 'permit_empty|in_list[male,female,other]',
            'is_teaching'             => 'permit_empty|is_bool',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->updateEmployee($id, $payload)->toArray());
    }

    public function setEmployeeArchived(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        if (! array_key_exists('archived', $payload) || ! is_bool($payload['archived'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'archived must be a boolean.', 'field' => 'archived'],
            ]);
        }
        return $this->ok($this->service->setEmployeeArchived($id, $payload['archived'])->toArray());
    }

    public function syncHrEmployees(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $records = $payload['employees'] ?? null;
        if (! is_array($records) || $records === []) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'employees must be a non-empty array.', 'field' => 'employees'],
            ]);
        }
        return $this->ok($this->service->syncHrEmployees(array_values($records)));
    }

    public function listDepartments(): ResponseInterface
    {
        $activeOnly = (string) ($this->request->getGet('active') ?? '') === '1';
        return $this->ok($this->service->listDepartments($activeOnly));
    }

    public function createDepartment(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'name'        => 'required|max_length[100]',
            'code'        => 'required|max_length[20]',
            'description' => 'permit_empty|max_length[1000]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->createDepartment($payload), null, 201);
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array<string, string>
     */
    private function studentRules(bool $create): array
    {
        $rules = [
            'first_name'    => ($create ? 'required' : 'permit_empty') . '|max_length[100]',
            'last_name'     => ($create ? 'required' : 'permit_empty') . '|max_length[100]',
            'middle_name'   => 'permit_empty|max_length[100]',
            'qr_code'       => 'permit_empty|max_length[255]',
            'rfid_tag'      => 'permit_empty|max_length[255]',
            'course'        => 'permit_empty|max_length[100]',
            'year_level'    => 'permit_empty|integer|greater_than[0]|less_than[7]',
            'section'       => 'permit_empty|max_length[20]',
            'date_of_birth' => 'permit_empty|valid_date[Y-m-d]',
            'gender'        => 'permit_empty|in_list[male,female,other]',
            'blood_type'    => 'permit_empty|max_length[5]',
            // Phase 3.5: optional portal-login creation.
            'create_account' => 'permit_empty|is_bool',
            'account_email'  => 'permit_empty|valid_email|max_length[255]',
        ];
        if ($create) {
            $rules['student_number'] = 'required|max_length[50]';
        }
        return $rules;
    }

    private function collectErrors(): array
    {
        $errs = [];
        foreach ($this->validation->getErrors() as $field => $msg) {
            $errs[] = ['code' => 'validation.field', 'message' => (string) $msg, 'field' => (string) $field];
        }
        return $errs;
    }
}
