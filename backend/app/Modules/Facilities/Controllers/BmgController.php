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
        $this->service = $service ?? new BmgService(
            new BmgPolicy(),
            Services::auditOutbox(),
            null,
            Services::notificationOutbox(),
        );
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
            // The unit's two drums — spec capacity is recomputed as their
            // sum; the ≥ 4 kg-per-drum floor is enforced in the service.
            'drum_one_capacity_kg' => 'permit_empty|decimal|greater_than[0]',
            'drum_two_capacity_kg' => 'permit_empty|decimal|greater_than[0]',
            'default_category_id'=> 'permit_empty|is_natural_no_zero',
            'category_ids'       => 'permit_empty',
            'notes'              => 'permit_empty|max_length[512]',
            // Every drum integrates exactly one registered ESP32.
            'device_id'          => 'required|is_natural_no_zero',
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
            'drum_one_capacity_kg' => 'permit_empty|decimal|greater_than[0]',
            'drum_two_capacity_kg' => 'permit_empty|decimal|greater_than[0]',
            'default_category_id'=> 'permit_empty|is_natural_no_zero',
            'category_ids'       => 'permit_empty',
            'notes'              => 'permit_empty|max_length[512]',
            // Reassign the drum's ESP32; an explicit null unbinds.
            'device_id'          => 'permit_empty|is_natural',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->updateUnit($unitId, $payload));
    }

    /**
     * Soft-archive a drum. The DELETE route carries no body (plain
     * archive — the ESP32 is released); the POST `units/{id}/archive`
     * route may name `relocate_device_to_unit_id` to move the drum's
     * ESP32 straight onto another device-less drum in the same
     * transaction.
     */
    public function archiveUnit(int $unitId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules   = ['relocate_device_to_unit_id' => 'permit_empty|is_natural_no_zero'];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        return $this->ok($this->service->archiveUnit($unitId, [
            'relocate_device_to_unit_id' => isset($payload['relocate_device_to_unit_id']) && $payload['relocate_device_to_unit_id'] !== ''
                ? (int) $payload['relocate_device_to_unit_id'] : null,
        ]));
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
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        // Starting the drum IS starting the batch: the waste mix is
        // designated on the drum, so the UI sends only the loaded
        // weight. The structured per-category `composition` (and the
        // legacy free-form `input_items`) remain accepted for API
        // clients that segregate by category.
        $composition = $payload['composition'] ?? [];
        if (! is_array($composition)) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'composition must be an array of {category_id, weight_kg}.', 'field' => 'composition'],
            ]);
        }

        $items = $payload['input_items'] ?? [];
        if (! is_array($items)) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'input_items must be an array.', 'field' => 'input_items'],
            ]);
        }

        $dto = $this->service->startBatch(
            $unitId,
            $items,
            (float) $payload['total_input_weight_kg'],
            $composition,
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
            // output_items is OPTIONAL free-form record-keeping detail
            // (SKU/qty breakdown). The UI now asks only for the output
            // weight — a sku=qty_kg ledger line was too technical for the
            // operator/dept. Stored when provided, omitted otherwise.
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $dto = $this->service->recordOutput(
            $batchId,
            (float) $payload['output_weight_kg'],
            $payload['output_items'] ?? [],
        );

        return $this->ok($dto->toArray());
    }

    public function finishBatch(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'quality_grade'    => 'required|in_list[excellent,good,fair]',
            'maturity_level'   => 'required|in_list[mature,maturing,immature]',
            'output_weight_kg' => 'permit_empty|decimal|greater_than[0]',
            'notes'            => 'permit_empty|max_length[512]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        $dto = $this->service->finishBatch($batchId, $payload);
        return $this->ok($dto->toArray());
    }

    /**
     * Unified "Add update" — one action, two internal entry types
     * (output / log). Appends an immutable ledger row.
     */
    public function addBatchUpdate(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'update_type'         => 'required|in_list[output,log]',
            'output_weight_kg'    => 'permit_empty|decimal|greater_than[0]',
            'event_type'          => 'permit_empty|in_list[observation,turning,aeration,moisture_adjustment,other]',
            'observation_note'    => 'permit_empty|max_length[1000]',
            'temperature_celsius' => 'permit_empty|decimal|greater_than_equal_to[-20]|less_than_equal_to[120]',
            'moisture_level'      => 'permit_empty|in_list[low,normal,high]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->addBatchUpdate($batchId, $payload), null, 201);
    }

    public function listBatchUpdates(int $batchId): ResponseInterface
    {
        return $this->ok($this->service->listBatchUpdates($batchId));
    }

    public function cancelBatch(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $reason = (string) ($payload['reason_code'] ?? 'unspecified');
        $note   = trim((string) ($payload['notes'] ?? ''));
        $dto = $this->service->cancelBatch($batchId, $reason, mb_substr($note, 0, 512));
        return $this->ok($dto->toArray());
    }

    public function listProcessLogs(int $batchId): ResponseInterface
    {
        return $this->ok($this->service->listProcessLogs($batchId));
    }

    public function listAlerts(int $batchId): ResponseInterface
    {
        return $this->ok($this->service->listAlerts($batchId));
    }

    public function acknowledgeAlert(int $alertId): ResponseInterface
    {
        return $this->ok($this->service->acknowledgeAlert($alertId));
    }

    // ---- BMG device administration (mechanized tumbler) ----------------

    public function listDevices(): ResponseInterface
    {
        $cursor   = (string) ($this->request->getGet('cursor') ?? '');
        $limit    = (int)    ($this->request->getGet('limit')  ?? 25);
        $archived = (string) ($this->request->getGet('include_archived') ?? '');

        $page = $this->service->listDevices(
            $cursor !== '' ? $cursor : null,
            $limit,
            $archived === '1' || $archived === 'true',
        );

        return $this->ok(
            $page['data'],
            \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null),
        );
    }

    public function createDevice(): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'code'         => 'permit_empty|max_length[32]',
            'mac'          => 'permit_empty|max_length[32]',
            'display_name' => 'permit_empty|max_length[128]',
            'unit_id'      => 'permit_empty|is_natural_no_zero',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        // 201 carries the plaintext token — shown once in the UI.
        return $this->ok($this->service->registerDevice([
            'code'         => (string) ($payload['code'] ?? ''),
            'mac'          => (string) ($payload['mac'] ?? ''),
            'display_name' => (string) ($payload['display_name'] ?? ''),
            'unit_id'      => isset($payload['unit_id']) && $payload['unit_id'] !== '' ? (int) $payload['unit_id'] : null,
        ]), null, 201);
    }

    public function setDeviceStatus(int $deviceId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $status  = (string) ($payload['status'] ?? '');

        return $this->ok($this->service->setDeviceStatus($deviceId, $status));
    }

    /**
     * Patch a device's display name and/or drum binding. Both fields are
     * optional; an omitted key is left untouched, while an explicit
     * `unit_id: null` unbinds the device.
     */
    public function updateDevice(int $deviceId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'display_name' => 'permit_empty|max_length[128]',
            'unit_id'      => 'permit_empty|is_natural',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }

        $input = [];
        if (array_key_exists('display_name', $payload)) {
            $input['display_name'] = (string) ($payload['display_name'] ?? '');
        }
        if (array_key_exists('unit_id', $payload)) {
            $input['unit_id'] = ($payload['unit_id'] === null || $payload['unit_id'] === '')
                ? null
                : (int) $payload['unit_id'];
        }

        return $this->ok($this->service->updateDevice($deviceId, $input));
    }

    /**
     * Soft-archive a device — the credential is refused by the ingest
     * filter from here on, and the drum binding is dropped.
     */
    public function archiveDevice(int $deviceId): ResponseInterface
    {
        return $this->ok($this->service->archiveDevice($deviceId));
    }

    public function regenerateDeviceToken(int $deviceId): ResponseInterface
    {
        // 201 carries the new plaintext token — shown once in the UI.
        return $this->ok($this->service->regenerateDeviceToken($deviceId), null, 201);
    }

    public function addProcessLog(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];

        $rules = [
            'log_date'            => 'permit_empty|valid_date[Y-m-d]',
            'event_type'          => 'permit_empty|in_list[observation,turning,aeration,moisture_adjustment,other]',
            'observation_note'    => 'permit_empty|max_length[1000]',
            'temperature_celsius' => 'permit_empty|decimal',
            'moisture_level'      => 'permit_empty|in_list[low,normal,high]',
            'oxygen_pct'          => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[25]',
            'device_id'           => 'permit_empty|max_length[64]',
            'calibration_status'  => 'permit_empty|in_list[ok,due,overdue]',
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
        $rules = [
            'weight_kg'                => 'required|decimal|greater_than[0]',
            'cn_ratio'                 => 'permit_empty|decimal|greater_than_equal_to[0.1]|less_than_equal_to[200]',
            'bulk_density_kg_per_m3'   => 'permit_empty|decimal|greater_than[0]',
            'ph'                       => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[14]',
            'note'                     => 'permit_empty|max_length[255]',
        ];
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

    /**
     * Industry-standard mass-balance tracking. Records a single loss
     * against an active batch (evaporation, off-gas, sampling, etc.)
     * and recomputes the denormalised `total_loss_kg` on the batch.
     */
    public function addBatchLoss(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'category_code' => 'required|in_list[evaporation,off_gas,sampling,spill,cleaning,mechanical_holdup,other]',
            'weight_kg'     => 'required|decimal|greater_than[0]',
            'note'          => 'permit_empty|max_length[255]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->addBatchLoss($batchId, $payload), null, 201);
    }

    /**
     * Read-only feed of losses for the drum detail panel.
     */
    public function listBatchLosses(int $batchId): ResponseInterface
    {
        return $this->ok($this->service->listBatchLosses($batchId));
    }

    // ---- Phase P4+: facilities audit fixes #2–#10 ---------------------

    public function listBatches(): ResponseInterface
    {
        $unitId  = (string) ($this->request->getGet('unit_id') ?? '');
        $status  = (string) ($this->request->getGet('status') ?? '');
        $cursor  = (string) ($this->request->getGet('cursor') ?? '');
        $limit   = (int) ($this->request->getGet('limit') ?? 25);

        $page = $this->service->listBatches(
            $unitId !== '' ? (int) $unitId : null,
            $status !== '' ? $status : null,
            $cursor !== '' ? $cursor : null,
            $limit,
        );
        return $this->ok($page['data'], \App\Http\ApiResponse::paginationMeta($page['count'], $page['next'], null));
    }

    public function releaseBatch(int $batchId): ResponseInterface
    {
        $payload = $this->request->getJSON(true) ?? [];
        $rules = [
            'quality_grade'  => 'required|in_list[excellent,good,fair]',
            'maturity_level' => 'required|in_list[mature,maturing,immature]',
            'notes'          => 'permit_empty|max_length[512]',
        ];
        if (! $this->makeValidation($rules)->run($payload)) {
            throw ApiException::validationFailure($this->collectErrors());
        }
        return $this->ok($this->service->releaseBatch($batchId, $payload));
    }

    public function batchCompliance(int $batchId): ResponseInterface
    {
        return $this->ok($this->service->batchCompliance($batchId));
    }

    public function blendCn(int $batchId): ResponseInterface
    {
        return $this->ok($this->service->blendCn($batchId));
    }

    public function listOpenAlerts(): ResponseInterface
    {
        return $this->ok($this->service->listOpenAlerts());
    }

    public function suggestUnit(): ResponseInterface
    {
        $categoryId = (int) ($this->request->getGet('category_id') ?? 0);
        $suggested  = $this->service->suggestUnit($categoryId);
        return $this->ok($suggested);
    }

    public function wasteCategoryDeviation(): ResponseInterface
    {
        return $this->ok($this->service->wasteCategoryDeviation());
    }
}