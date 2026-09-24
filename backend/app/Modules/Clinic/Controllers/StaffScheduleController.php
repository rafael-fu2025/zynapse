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
        $user     = (string) ($this->request->getGet('user_id') ?? '');
        $archived = (string) ($this->request->getGet('include_archived') ?? '');
        return $this->ok($this->service->list(
            $user !== '' ? (int) $user : null,
            $archived === '1' || $archived === 'true',
        ));
    }

    public function create(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        // `day_of_week` is no longer required: the roster dialog now ticks a
        // set of weekdays and posts `days_of_week` (2026-09-23). The
        // single-day shape is still accepted so older callers keep working.
        $rules = [
            'user_id'        => 'required|is_natural_no_zero',
            'day_of_week'    => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[6]',
            'shift_start'    => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'shift_end'      => 'required|regex_match[/^\d{2}:\d{2}(:\d{2})?$/]',
            'schedule_type'  => 'permit_empty|in_list[regular,on_call,leave]',
            'effective_from' => 'permit_empty|valid_date[Y-m-d]',
            'effective_to'   => 'permit_empty|valid_date[Y-m-d]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $this->assertWeekdaysPresent($payload);

        return $this->ok($this->service->create($payload), null, 201);
    }

    /**
     * Reject a weekday set that is not a non-empty list of 0-6.
     *
     * CI4's rule engine validates scalars, not arrays of ints, so the shape
     * check lives here; `StaffScheduleService::normaliseDaysOfWeek()` remains
     * the authoritative normaliser for the values themselves.
     *
     * @param array<string, mixed> $payload
     */
    private function assertWeekdaysPresent(array $payload): void
    {
        $raw = $payload['days_of_week'] ?? null;

        if ($raw === null) {
            if (array_key_exists('day_of_week', $payload) && $payload['day_of_week'] !== '' && $payload['day_of_week'] !== null) {
                return;
            }
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Pick at least one weekday.', 'field' => 'days_of_week'],
            ]);
        }

        if (! is_array($raw)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Weekdays must be a list of numbers 0-6.', 'field' => 'days_of_week'],
            ]);
        }

        foreach ($raw as $candidate) {
            if (! is_numeric($candidate) || (int) $candidate < 0 || (int) $candidate > 6) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Weekdays must be numbers 0-6.', 'field' => 'days_of_week'],
                ]);
            }
        }

        if ($raw === []) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Pick at least one weekday.', 'field' => 'days_of_week'],
            ]);
        }
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

    public function unarchive(int $id): ResponseInterface
    {
        return $this->ok($this->service->unarchive($id));
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
