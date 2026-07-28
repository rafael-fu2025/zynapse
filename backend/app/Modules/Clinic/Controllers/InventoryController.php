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
        $cursor = (string) ($this->request->getGet('cursor') ?? '');
        $limit  = (int)    ($this->request->getGet('limit')  ?? 25);

        $page = $this->service->listItems($cursor !== '' ? $cursor : null, $limit);

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

    public function moveStock(int $itemId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'qty_delta'   => 'required|integer',
            'reason_code' => 'required|in_list[receive,dispense,adjustment]',
            'note'        => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $delta  = (int) $payload['qty_delta'];
        $reason = (string) $payload['reason_code'];

        // Sign coherence: receipts add stock, dispenses remove it.
        if ($delta === 0
            || ($reason === 'receive' && $delta < 0)
            || ($reason === 'dispense' && $delta > 0)
        ) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'qty_delta sign does not match reason_code.', 'field' => 'qty_delta'],
            ]);
        }

        $dto = $this->service->moveStock(
            $itemId,
            $delta,
            $reason,
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
