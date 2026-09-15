<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\EquipmentService;

/**
 * EquipmentController — thin endpoints for the durable-asset catalog
 * (the third inventory catalog beside medicines and supplies).
 */
final class EquipmentController extends ApiController
{
    private readonly EquipmentService $service;

    public function __construct(?EquipmentService $service = null)
    {
        $this->service = $service ?? new EquipmentService(new ClinicPolicy(), Services::auditOutbox());
    }

    public function listItems(): ResponseInterface
    {
        $cursor   = (string) ($this->request->getGet('cursor') ?? '');
        $limit    = (int)    ($this->request->getGet('limit')  ?? 25);
        $q        = (string) ($this->request->getGet('q')      ?? '');
        $archived = (string) ($this->request->getGet('include_archived') ?? '');

        $page = $this->service->listItems(
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

    public function getEquipment(int $equipmentId): ResponseInterface
    {
        return $this->ok($this->service->getEquipment($equipmentId));
    }

    public function createItem(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'name'     => 'required|max_length[128]',
            'category' => 'permit_empty|max_length[100]',
            'location' => 'permit_empty|max_length[128]',
            'notes'    => 'permit_empty|max_length[500]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->createItem($payload), null, 201);
    }

    /**
     * Update the catalog row (name, category, location, notes). Unit
     * statuses are untouched here — they go through changeUnitStatus so
     * every change lands in the status log.
     */
    public function updateItem(int $equipmentId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'name'     => 'required|max_length[128]',
            'category' => 'permit_empty|max_length[100]',
            'location' => 'permit_empty|max_length[128]',
            'notes'    => 'permit_empty|max_length[500]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->updateItem($equipmentId, $payload));
    }

    /**
     * Soft-archive an equipment catalog row. Units and status history are
     * preserved — only the row leaves the default list.
     */
    public function archiveItem(int $equipmentId): ResponseInterface
    {
        return $this->ok($this->service->archiveItem($equipmentId));
    }

    /**
     * Restore a soft-archived equipment catalog row.
     */
    public function unarchiveItem(int $equipmentId): ResponseInterface
    {
        return $this->ok($this->service->unarchiveItem($equipmentId));
    }

    /**
     * Add physical units to an equipment item. New units start `working`
     * and the addition is recorded in the status log.
     */
    public function addUnits(int $equipmentId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'quantity'      => 'required|is_natural_no_zero',
            'acquired_date' => 'permit_empty|valid_date[Y-m-d]',
            'note'          => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->addUnits(
            $equipmentId,
            (int) $payload['quantity'],
            isset($payload['acquired_date']) && $payload['acquired_date'] !== '' ? (string) $payload['acquired_date'] : null,
            isset($payload['note']) && $payload['note'] !== '' ? (string) $payload['note'] : null,
        ), null, 201);
    }

    /**
     * Edit a unit's descriptive fields (condition note, acquired date).
     */
    public function updateUnit(int $unitId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'condition_note' => 'permit_empty|max_length[255]',
            'acquired_date'  => 'permit_empty|valid_date[Y-m-d]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->updateUnit(
            $unitId,
            isset($payload['condition_note']) && $payload['condition_note'] !== '' ? (string) $payload['condition_note'] : null,
            isset($payload['acquired_date']) && $payload['acquired_date'] !== '' ? (string) $payload['acquired_date'] : null,
        ));
    }

    /**
     * One-click status change for a unit — appends the from→to row to the
     * status log and mirrors the time onto the unit.
     */
    public function changeUnitStatus(int $unitId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'status' => 'required|in_list[working,for_repair,for_replacement,retired]',
            'note'   => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->changeUnitStatus(
            $unitId,
            (string) $payload['status'],
            isset($payload['note']) && $payload['note'] !== '' ? (string) $payload['note'] : null,
        ));
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
