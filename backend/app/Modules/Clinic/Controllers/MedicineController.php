<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\MedicineService;

/**
 * MedicineController — thin endpoints for the batch-tracked medicine
 * inventory (Phase 12, recycled from legacy synapse_ag).
 */
final class MedicineController extends ApiController
{
    private readonly MedicineService $service;

    public function __construct(?MedicineService $service = null)
    {
        $this->service = $service ?? new MedicineService(new ClinicPolicy(), Services::auditOutbox());
    }

    public function list(): ResponseInterface
    {
        $this->authorize('clinic.inventory.read');
        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);

        $page = $this->service->listMedicines($cursor !== '' ? $cursor : null, $limit);

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function show(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.read');
        return $this->ok($this->service->getMedicine($id)->toArray());
    }

    public function computeForecast(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.forecast');
        return $this->ok($this->service->computeForecast($id), null, 201);
    }

    public function getForecast(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.read');
        return $this->ok(['forecast' => $this->service->getLatestForecast($id)]);
    }

    public function create(): ResponseInterface
    {
        $this->authorize('clinic.inventory.write');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'generic_name'      => 'required|max_length[200]',
            'brand_name'        => 'permit_empty|max_length[200]',
            'category'          => 'permit_empty|max_length[100]',
            'dosage_form'       => 'permit_empty|max_length[100]',
            'dosage_strength'   => 'permit_empty|max_length[100]',
            'unit'              => 'permit_empty|max_length[50]',
            'reorder_threshold' => 'permit_empty|is_natural',
            'description'       => 'permit_empty|max_length[2000]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->createMedicine($payload)->toArray(), null, 201);
    }

    public function addBatch(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.write');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'batch_number'      => 'required|max_length[100]',
            'quantity_received' => 'required|is_natural_no_zero',
            'expiration_date'   => 'required|valid_date[Y-m-d]',
            'received_date'     => 'permit_empty|valid_date[Y-m-d]',
            'supplier'          => 'permit_empty|max_length[200]',
            'note'              => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->addBatch($id, $payload)->toArray(), null, 201);
    }

    public function dispense(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.write');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'quantity' => 'required|is_natural_no_zero',
            'note'     => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok(
            $this->service->dispense(
                $id,
                (int) $payload['quantity'],
                isset($payload['note']) ? (string) $payload['note'] : null,
            )->toArray(),
        );
    }

    public function lowStock(): ResponseInterface
    {
        return $this->ok($this->service->listLowStock());
    }

    public function expiring(): ResponseInterface
    {
        $days = (int) ($this->request->getGet('days') ?? 30);
        return $this->ok($this->service->listExpiring($days));
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
