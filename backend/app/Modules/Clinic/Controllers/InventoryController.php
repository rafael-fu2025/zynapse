<?php

declare(strict_types=1);

namespace Modules\Clinic\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\InventoryService;

/**
 * InventoryController — thin endpoints for clinic supply stock.
 */
final class InventoryController extends ApiController
{
    private readonly InventoryService $service;

    public function __construct(?InventoryService $service = null)
    {
        $this->service = $service ?? new InventoryService(new ClinicPolicy(), Services::auditOutbox());
    }

    public function listItems(): ResponseInterface
    {
        $cursor    = (string) ($this->request->getGet('cursor') ?? '');
        $limit     = (int)    ($this->request->getGet('limit')  ?? 25);
        $q         = (string) ($this->request->getGet('q')      ?? '');
        $archived  = (string) ($this->request->getGet('include_archived') ?? '');
        $lowStock  = (string) ($this->request->getGet('low_stock') ?? '');

        $page = $this->service->listItems(
            $cursor !== '' ? $cursor : null,
            $limit,
            $q !== '' ? $q : null,
            $archived === '1' || $archived === 'true',
            $lowStock === '1' || $lowStock === 'true',
        );

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function createItem(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'sku'           => 'required|alpha_dash|max_length[64]',
            'name'          => 'required|max_length[128]',
            'unit'          => 'permit_empty|max_length[32]',
            'reorder_level' => 'permit_empty|is_natural',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->createItem(
            (string) $payload['sku'],
            (string) $payload['name'],
            (string) ($payload['unit'] ?? 'pc'),
            (int)    ($payload['reorder_level'] ?? 0),
        );
        return $this->ok($dto->toArray(), null, 201);
    }

    /**
     * Update a supply item. SKU is immutable (it's the FK for the
     * movement ledger); name, unit, reorder_level are mutable.
     * Stock is untouched — use /move for that.
     */
    public function updateItem(int $itemId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'name'          => 'required|max_length[128]',
            'unit'          => 'permit_empty|max_length[32]',
            'reorder_level' => 'permit_empty|is_natural',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->updateItem(
            $itemId,
            (string) $payload['name'],
            (string) ($payload['unit'] ?? 'pc'),
            (int)    ($payload['reorder_level'] ?? 0),
        );
        return $this->ok($dto->toArray());
    }

    /**
     * Soft-archive a supply item. The movement ledger is preserved
     * — only the catalog row drops off the default list.
     */
    public function archiveItem(int $itemId): ResponseInterface
    {
        return $this->ok($this->service->archiveItem($itemId)->toArray());
    }

    /**
     * Restore a soft-archived supply item back onto the default list.
     */
    public function unarchiveItem(int $itemId): ResponseInterface
    {
        return $this->ok($this->service->unarchiveItem($itemId)->toArray());
    }

    public function moveStock(int $itemId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'qty_delta'    => 'required|integer',
            'reason_code'  => 'required|in_list[dispense,adjustment]',
            'encounter_id' => 'permit_empty|is_natural_no_zero',
            'note'         => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $delta  = (int) $payload['qty_delta'];
        $reason = (string) $payload['reason_code'];

        // Sign coherence: dispenses remove stock. Receipts are NOT a
        // free-form movement any more — see receiveOrdered().
        if ($delta === 0 || ($reason === 'dispense' && $delta > 0)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'qty_delta sign does not match reason_code.', 'field' => 'qty_delta'],
            ]);
        }

        $dto = $this->service->moveStock(
            $itemId,
            $delta,
            $reason,
            isset($payload['note']) ? (string) $payload['note'] : null,
            isset($payload['encounter_id']) && $payload['encounter_id'] !== '' ? (int) $payload['encounter_id'] : null,
        );

        return $this->ok($dto->toArray());
    }

    /**
     * Receive the delivered quantity of a `received` reorder request.
     * Quantity defaults to the request server-side; the payload may
     * lower it for a partial delivery (with an optional shortage note).
     */
    public function receiveOrdered(int $itemId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'note'          => 'permit_empty|max_length[255]',
            'quantity'      => 'permit_empty|is_natural_no_zero',
            'shortage_note' => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->receiveOrdered(
            $itemId,
            isset($payload['note']) ? (string) $payload['note'] : null,
            isset($payload['quantity']) ? (int) $payload['quantity'] : null,
            isset($payload['shortage_note']) ? (string) $payload['shortage_note'] : null,
        )->toArray());
    }

    /**
     * Ledger view — signed movements with the stored running balance
     * (panel revision: in/out debit-credit tracking).
     */
    public function listMovements(int $itemId): ResponseInterface
    {
        return $this->ok($this->service->listMovements($itemId));
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
