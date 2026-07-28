<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\ReorderService;

/**
 * ReorderController — thin endpoints for the procurement workflow
 * (Phase 13, recycled from legacy synapse_ag).
 */
final class ReorderController extends ApiController
{
    private readonly ReorderService $service;

    public function __construct(?ReorderService $service = null)
    {
        $this->service = $service ?? new ReorderService(new ClinicPolicy(), Services::auditOutbox());
    }

    public function list(): ResponseInterface
    {
        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);
        $status = (string) ($this->request->getGet('status') ?? '');

        if ($status !== '' && ! in_array($status, ['pending', 'approved', 'ordered', 'received', 'cancelled'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Unknown status filter.', 'field' => 'status'],
            ]);
        }

        $page = $this->service->list($cursor !== '' ? $cursor : null, $limit, $status !== '' ? $status : null);

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function create(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'medicine_id' => 'required|is_natural_no_zero',
            'quantity'    => 'required|is_natural_no_zero',
            'urgency'     => 'permit_empty|in_list[low,medium,high,critical]',
            'note'        => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->create(
            (int) $payload['medicine_id'],
            (int) $payload['quantity'],
            (string) ($payload['urgency'] ?? 'medium'),
            isset($payload['note']) ? (string) $payload['note'] : null,
        );
        return $this->ok($dto->toArray(), null, 201);
    }

    public function autoCheck(): ResponseInterface
    {
        return $this->ok($this->service->autoCheck());
    }

    public function transition(int $id): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'action'                 => 'required|in_list[approve,order,receive,cancel]',
            'expected_delivery_date' => 'permit_empty|valid_date[Y-m-d]',
            'note'                   => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->transition(
            $id,
            (string) $payload['action'],
            isset($payload['expected_delivery_date']) ? (string) $payload['expected_delivery_date'] : null,
            isset($payload['note']) ? (string) $payload['note'] : null,
        );
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
