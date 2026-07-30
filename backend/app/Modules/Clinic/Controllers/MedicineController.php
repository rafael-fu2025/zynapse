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
        $cursor   = (string) ($this->request->getGet('cursor') ?? '');
        $limit    = (int)    ($this->request->getGet('limit')  ?? 25);
        $q        = (string) ($this->request->getGet('q')      ?? '');
        $archived = (string) ($this->request->getGet('include_archived') ?? '');

        $page = $this->service->listMedicines(
            $cursor !== '' ? $cursor : null,
            $limit,
            $q !== '' ? $q : null,
            $archived === '1' || $archived === 'true',
        );

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

    /**
     * Receive a lot against a `received` reorder request. Quantity is
     * taken from that request server-side — the payload only carries
     * the batch identity (number, expiry, supplier).
     */
    public function addBatch(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.write');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'batch_number'    => 'required|max_length[100]',
            'expiration_date' => 'required|valid_date[Y-m-d]',
            'received_date'   => 'permit_empty|valid_date[Y-m-d]',
            'supplier'        => 'permit_empty|max_length[200]',
            'note'            => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->addBatch($id, $payload)->toArray(), null, 201);
    }

    /**
     * Update the medicine catalog row. Restricted to the reorder
     * threshold — every other catalog field is read-only after
     * creation (the batch ledger must keep describing the same
     * product).
     */
    public function update(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.write');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'reorder_threshold' => 'required|is_natural',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->updateMedicine($id, ['reorder_threshold' => (int) $payload['reorder_threshold']])->toArray());
    }

    /**
     * Soft-archive a medicine. Batches and movement history remain
     * in the database; the row simply drops off the default list.
     * Idempotent — re-archiving returns the same row.
     */
    public function archive(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.delete');
        return $this->ok($this->service->archiveMedicine($id)->toArray());
    }

    /**
     * Restore a soft-archived medicine back onto the default list.
     * Idempotent — restoring an active medicine returns the same row.
     */
    public function unarchive(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.delete');
        return $this->ok($this->service->unarchiveMedicine($id)->toArray());
    }

    public function dispense(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.write');
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'quantity'     => 'required|is_natural_no_zero',
            'encounter_id' => 'required|is_natural_no_zero',
            'note'         => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok(
            $this->service->dispense(
                $id,
                (int) $payload['quantity'],
                isset($payload['note']) ? (string) $payload['note'] : null,
                (int) $payload['encounter_id'],
            )->toArray(),
        );
    }

    /**
     * Ledger view — typed transactions with the stored running balance
     * (panel revision: in/out debit-credit tracking).
     */
    public function transactions(int $id): ResponseInterface
    {
        $this->authorize('clinic.inventory.read');
        return $this->ok($this->service->listTransactions($id));
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
