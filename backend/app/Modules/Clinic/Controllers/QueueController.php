<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\QueueService;

/**
 * QueueController — walk-in queue endpoints (Phase 14, recycled from
 * legacy synapse_ag). `state` is PUBLIC (waiting-room TV / kiosk).
 */
final class QueueController extends ApiController
{
    private readonly QueueService $service;

    public function __construct(?QueueService $service = null)
    {
        $this->service = $service ?? new QueueService(new ClinicPolicy(), Services::auditOutbox());
    }

    public function today(): ResponseInterface
    {
        return $this->ok($this->service->today());
    }

    public function enqueue(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        if (! $this->makeValidation(['encounter_id' => 'required|is_natural_no_zero'])->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->enqueue((int) $payload['encounter_id']), null, 201);
    }

    public function callNext(): ResponseInterface
    {
        return $this->ok($this->service->callNext());
    }

    public function transition(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        if (! $this->makeValidation(['action' => 'required|in_list[start,skip,complete]'])->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->transition($id, (string) $payload['action']));
    }

    /** PUBLIC — minimum-disclosure waiting-room feed. */
    public function state(): ResponseInterface
    {
        return $this->ok($this->service->publicState());
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
