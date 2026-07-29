<?php

declare(strict_types=1);

namespace Modules\Facilities\Controllers;

use App\Controllers\Api\ApiController;
use App\Exceptions\ApiException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Modules\Facilities\Policies\BmgPolicy;
use Modules\Facilities\Services\BmgService;

/**
 * BmgController — thin endpoints for the BMG state machine.
 */
final class BmgController extends ApiController
{
    private readonly BmgService $service;

    public function __construct(?BmgService $service = null)
    {
        $this->service = $service ?? new BmgService(new BmgPolicy(), Services::auditOutbox());
    }

    public function listUnits(): ResponseInterface
    {
        $cursor   = (string) ($this->request->getGet('cursor') ?? '');
        $limit    = (int)    ($this->request->getGet('limit')  ?? 25);
        $archived = (string) ($this->request->getGet('include_archived') ?? '');

        $page = $this->service->listUnits(
            $cursor !== '' ? $cursor : null,
            $limit,
            $archived === '1' || $archived === 'true',
        );

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function createUnit(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'code'               => 'required|max_length[32]',
            'display_name'       => 'required|max_length[128]',
            'location_code'      => 'permit_empty|max_length[64]',
            'spec_capacity_kg'   => 'permit_empty|decimal|greater_than[0]',
            'default_category_id'=> 'permit_empty|is_natural_no_zero',
            'notes'              => 'permit_empty|max_length[512]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->createUnit($payload), null, 201);
    }

    public function updateUnit(int $unitId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'display_name'       => 'permit_empty|max_length[128]',
            'location_code'      => 'permit_empty|max_length[64]',
            'spec_capacity_kg'   => 'permit_empty|decimal|greater_than[0]',
            'default_category_id'=> 'permit_empty|is_natural_no_zero',
            'notes'              => 'permit_empty|max_length[512]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->updateUnit($unitId, $payload));
    }

    public function archiveUnit(int $unitId): ResponseInterface
    {
        return $this->ok($this->service->archiveUnit($unitId));
    }

    public function unarchiveUnit(int $unitId): ResponseInterface
    {
        return $this->ok($this->service->unarchiveUnit($unitId));
    }

    public function startBatch(int $unitId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'total_input_weight_kg' => 'required|decimal|greater_than[0]',
            'input_items'           => 'required',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $items = $payload['input_items'];
        if (! is_array($items) || $items === []) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'input_items must be a non-empty array.', 'field' => 'input_items'],
            ]);
        }

        $dto = $this->service->startBatch(
            $unitId,
            $items,
            (float) $payload['total_input_weight_kg'],
        );

        return $this->ok($dto->toArray(), null, 201);
    }

    public function recordOutput(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        // Resolve the batch's input weight to feed the `bmg_mass_invariant`
        // rule parameter. Throws 404 if the batch is missing.
        $maxKg = $this->service->peekInputKg($batchId);

        $rules = [
            'output_weight_kg' => 'required|decimal|greater_than[0]|bmg_mass_invariant[' . $maxKg . ']',
            'output_items'     => 'required',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->recordOutput(
            $batchId,
            (float) $payload['output_weight_kg'],
            $payload['output_items'],
        );

        return $this->ok($dto->toArray());
    }

    public function finishBatch(int $batchId): ResponseInterface
    {
        $dto = $this->service->finishBatch($batchId);
        return $this->ok($dto->toArray());
    }

    public function cancelBatch(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $reason = (string) ($payload['reason_code'] ?? 'unspecified');
        $dto = $this->service->cancelBatch($batchId, $reason);
        return $this->ok($dto->toArray());
    }

    public function listProcessLogs(int $batchId): ResponseInterface
    {
        return $this->ok($this->service->listProcessLogs($batchId));
    }

    public function addProcessLog(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'log_date'            => 'permit_empty|valid_date[Y-m-d]',
            'observation_note'    => 'permit_empty|max_length[1000]',
            'temperature_celsius' => 'permit_empty|decimal',
            'moisture_level'      => 'permit_empty|in_list[low,normal,high]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->addProcessLog($batchId, $payload), null, 201);
    }

    private function collectErrors(): array
    {
        $errs = [];
        foreach ($this->validation->getErrors() as $field => $msg) {
            $errs[] = ['code' => 'validation.field', 'message' => (string) $msg, 'field' => (string) $field];
        }
        return $errs;
    }

    // ---- Phase P4: waste categories, structured I/O, analytics --------

    public function listWasteCategories(): ResponseInterface
    {
        $activeOnly = (string) ($this->request->getGet('active') ?? '') === '1';
        return $this->ok($this->service->listWasteCategories($activeOnly));
    }

    public function createWasteCategory(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'code'                    => 'required|max_length[50]',
            'name'                    => 'required|max_length[100]',
            'description'             => 'permit_empty|max_length[1000]',
            'expected_yield_pct'      => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
            'reference_duration_days' => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->createWasteCategory($payload), null, 201);
    }

    public function updateWasteCategory(int $categoryId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'name'                    => 'permit_empty|max_length[100]',
            'description'             => 'permit_empty|max_length[1000]',
            'expected_yield_pct'      => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[100]',
            'reference_duration_days' => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        if (array_key_exists('is_active', $payload) && ! is_bool($payload['is_active'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'is_active must be a boolean.', 'field' => 'is_active'],
            ]);
        }
        return $this->ok($this->service->updateWasteCategory($categoryId, $payload));
    }

    public function archiveWasteCategory(int $categoryId): ResponseInterface
    {
        return $this->ok($this->service->archiveWasteCategory($categoryId));
    }

    public function unarchiveWasteCategory(int $categoryId): ResponseInterface
    {
        return $this->ok($this->service->unarchiveWasteCategory($categoryId));
    }

    public function deleteWasteCategory(int $categoryId): ResponseInterface
    {
        $this->service->deleteWasteCategory($categoryId);
        return $this->ok(['id' => $categoryId, 'deleted' => true]);
    }

    public function addBatchInput(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = ['weight_kg' => 'required|decimal|greater_than[0]', 'note' => 'permit_empty|max_length[255]'];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->addBatchInput($batchId, $payload), null, 201);
    }

    public function addBatchOutput(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'output_weight_kg' => 'required|decimal|greater_than[0]',
            'harvest_date'     => 'permit_empty|valid_date[Y-m-d]',
            'quality_grade'    => 'permit_empty|in_list[excellent,good,fair]',
            'note'             => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->addBatchOutput($batchId, $payload), null, 201);
    }

    public function batchAnalytics(int $batchId): ResponseInterface
    {
        return $this->ok($this->service->batchAnalytics($batchId));
    }

    /**
     * Active-batch dashboard feed for the "Processing Drums" widget.
     * Returns every batch in Processing or AwaitingOutput with the joined
     * unit, optional waste category, and computed days_active /
     * expected_completion_date / progress_pct. Read-only, no outbox.
     */
    public function listActiveBatches(): ResponseInterface
    {
        return $this->ok($this->service->listActiveBatches());
    }

    public function setUnitMaintenance(int $unitId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        if (! array_key_exists('maintenance', $payload) || ! is_bool($payload['maintenance'])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'maintenance must be a boolean.', 'field' => 'maintenance'],
            ]);
        }
        return $this->ok($this->service->setUnitMaintenance($unitId, $payload['maintenance']));
    }
}