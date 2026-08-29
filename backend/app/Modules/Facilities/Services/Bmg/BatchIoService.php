<?php

declare(strict_types=1);

namespace Modules\Facilities\Services\Bmg;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * BatchIoService — structured I/O cluster for the BMG state machine.
 *
 * Extracted verbatim from BmgService: recording structured feedstock
 * inputs (`facilities_bmg_inputs`) and harvested outputs
 * (`facilities_bmg_outputs`) against an ACTIVE batch, with the
 * denormalised audit trail and the tier-3.2 record-level ownership
 * check. Transaction boundaries and policy action strings are
 * unchanged from the pre-extraction BmgService.
 */
final class BatchIoService extends BaseService
{
    public function __construct(
        ?BaseConnection $db,
        private readonly BmgPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly BmgSupport $support,
    ) {
        parent::__construct($db);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addBatchInput(int $batchId, array $input): array
    {
        return $this->recordIo($batchId, 'input', $input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addBatchOutput(int $batchId, array $input): array
    {
        return $this->recordIo($batchId, 'output', $input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function recordIo(int $batchId, string $kind, array $input): array
    {
        // Tier 3.2 — load the batch row outside the txn so the policy's
        // record-level ownership check can see `started_by_user_id`.
        // `io_record` is in OWNED_BATCH_ACTIONS, so without this load the
        // policy would fail closed (no record → no permission).
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('io_record', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $kind, $input, $userId): array {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }
            if (! in_array($batch['status'], [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT], true)) {
                throw new ApiException('statemachine.bmg.io_terminal_batch', 409, [
                    ['code' => 'statemachine.bmg.io_terminal_batch', 'message' => 'Inputs/outputs can only be recorded on an active batch.'],
                ]);
            }
            $now = $this->support->utcNow();

            if ($kind === 'input') {
                $this->db->table('facilities_bmg_inputs')->insert([
                    'batch_id'              => $batchId,
                    'tenant_id'             => CurrentTenant::id(),
                    'weight_kg'             => (float) $input['weight_kg'],
                    'cn_ratio'              => isset($input['cn_ratio']) && $input['cn_ratio'] !== '' ? (float) $input['cn_ratio'] : null,
                    'bulk_density_kg_per_m3'=> isset($input['bulk_density_kg_per_m3']) && $input['bulk_density_kg_per_m3'] !== '' ? (float) $input['bulk_density_kg_per_m3'] : null,
                    'ph'                    => isset($input['ph']) && $input['ph'] !== '' ? (float) $input['ph'] : null,
                    'note'                  => isset($input['note']) && $input['note'] !== '' ? (string) $input['note'] : null,
                    'recorded_by_user_id'   => $userId,
                    'recorded_at'           => $now,
                    'created_at'            => $now,
                ]);
                $id = (int) $this->db->insertID();
                $this->audit->enqueue('bmg.input_recorded', 'facilities_bmg_inputs', $id, $userId, ['resource_code' => (string) $batch['reference_code']]);
                return [
                    'id'                     => $id,
                    'batch_id'               => $batchId,
                    'weight_kg'              => (float) $input['weight_kg'],
                    'cn_ratio'               => isset($input['cn_ratio']) && $input['cn_ratio'] !== '' ? (float) $input['cn_ratio'] : null,
                    'bulk_density_kg_per_m3' => isset($input['bulk_density_kg_per_m3']) && $input['bulk_density_kg_per_m3'] !== '' ? (float) $input['bulk_density_kg_per_m3'] : null,
                    'ph'                     => isset($input['ph']) && $input['ph'] !== '' ? (float) $input['ph'] : null,
                ];
            }

            $this->db->table('facilities_bmg_outputs')->insert([
                'batch_id'            => $batchId,
                'tenant_id'           => CurrentTenant::id(),
                'output_weight_kg'    => (float) $input['output_weight_kg'],
                'harvest_date'        => isset($input['harvest_date']) && $input['harvest_date'] !== '' ? (string) $input['harvest_date'] : null,
                'quality_grade'       => isset($input['quality_grade']) && $input['quality_grade'] !== '' ? (string) $input['quality_grade'] : null,
                'note'                => isset($input['note']) && $input['note'] !== '' ? (string) $input['note'] : null,
                'recorded_by_user_id' => $userId,
                'created_at'          => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->audit->enqueue('bmg.output_recorded_detail', 'facilities_bmg_outputs', $id, $userId, ['resource_code' => (string) $batch['reference_code']]);
            return ['id' => $id, 'batch_id' => $batchId, 'output_weight_kg' => (float) $input['output_weight_kg']];
        });
    }
}
