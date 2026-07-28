<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\StaffScheduleService;

/**
 * StaffScheduleController — admin CRUD for the recurring staff shift
 * roster (Phase P5b, recycled from legacy synapse_ag staff_schedules).
 * Gated by `clinic.schedules.manage`.
 */
final class StaffScheduleController extends ApiController
{
    private readonly StaffScheduleService $service;

    public function __construct(?StaffScheduleService $service = null)
    {
        $this->service = $service ?? new StaffScheduleService(new ClinicPolicy(), Services::auditOutbox());
    }

    public function list(): ResponseInterface
    {
        $user = (string) ($this->request->getGet('user_id') ?? '');
        return $this->ok($this->service->list($user !== '' ? (int) $user : null));
    }

    public function create(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'user_id'        => 'required|is_natural_no_zero',
            'day_of_week'    => 'required|integer|greater_than_equal_to[0]|less_than_equal_to[6]',
            'shift_start'    => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'shift_end'      => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'schedule_type'  => 'permit_empty|in_list[regular,on_call,leave]',
            'effective_from' => 'permit_empty|valid_date[Y-m-d]',
            'effective_to'   => 'permit_empty|valid_date[Y-m-d]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->create($payload), null, 201);
    }

    public function update(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'day_of_week'    => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[6]',
            'shift_start'    => 'permit_empty|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'shift_end'      => 'permit_empty|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'schedule_type'  => 'permit_empty|in_list[regular,on_call,leave]',
            'effective_from' => 'permit_empty|valid_date[Y-m-d]',
            'effective_to'   => 'permit_empty|valid_date[Y-m-d]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->update($id, $payload));
    }

    public function archive(int $id): ResponseInterface
    {
        $this->service->archive($id);
        return $this->ok(['archived' => true]);
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
