<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\AppointmentService;

/**
 * AppointmentController — thin endpoints for clinic scheduling.
 */
final class AppointmentController extends ApiController
{
    private readonly AppointmentService $service;

    public function __construct(?AppointmentService $service = null)
    {
        $this->service = $service ?? new AppointmentService(
            new ClinicPolicy(),
            Services::auditOutbox(),
            Services::notificationOutbox(),
        );
    }

    public function list(): ResponseInterface
    {
        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);
        $status = $this->request->getGet('status');
        $q      = $this->request->getGet('q');

        $page = $this->service->list(
            $cursor !== '' ? $cursor : null,
            $limit,
            is_string($status) ? $status : null,
            is_string($q) ? $q : null,
        );

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function schedule(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'patient_school_id' => 'required|max_length[32]',
            'provider_user_id'  => 'required|is_natural_no_zero',
            'scheduled_at'      => 'required|valid_date[Y-m-d H:i:s]',
            'reason'            => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->schedule(
            (string) $payload['patient_school_id'],
            (int)    $payload['provider_user_id'],
            (string) $payload['scheduled_at'],
            isset($payload['reason']) ? (string) $payload['reason'] : null,
        );
        return $this->ok($dto->toArray(), null, 201);
    }

    public function transition(int $appointmentId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'status' => 'required|in_list[checked_in,completed,cancelled,no_show]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->transition($appointmentId, (string) $payload['status']);
        return $this->ok($dto->toArray());
    }

    public function show(int $appointmentId): ResponseInterface
    {
        return $this->ok($this->service->show($appointmentId)->toArray());
    }

    public function update(int $appointmentId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'patient_school_id' => 'permit_empty|max_length[32]',
            'provider_user_id'  => 'permit_empty|is_natural_no_zero',
            'scheduled_at'      => 'permit_empty|valid_date[Y-m-d H:i:s]',
            'reason'            => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        // Reject empty bodies — the backend treats "no fields" as a
        // no-op success, but the SPA never sends an empty PATCH.
        $hasField = false;
        foreach (['patient_school_id', 'provider_user_id', 'scheduled_at', 'reason'] as $k) {
            if (array_key_exists($k, $payload)) {
                $hasField = true;
                break;
            }
        }
        if (! $hasField) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'At least one field is required.', 'field' => 'body'],
            ]);
        }

        $dto = $this->service->update($appointmentId, $payload);
        return $this->ok($dto->toArray());
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
