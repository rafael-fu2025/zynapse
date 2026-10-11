<?php

declare(strict_types=1);

namespace Modules\Facilities\Services\Bmg;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * BatchIoService — structured I/O cluster for the BMG state machine.
 *
 * Recording structured feedstock inputs (`facilities_bmg_inputs`) and
 * harvested outputs (`facilities_bmg_outputs`) against an ACTIVE batch,
 * with the denormalised audit trail and the tier-3.2 record-level
 * ownership check (module-level only since the staff-wide I/O entry
 * scope — the batch's active-state checks remain).
 *
 * Rows are VOIDED, never edited or deleted: `voided_at`/`voided_by`/
 * `void_reason` mark the entry and every aggregation filters
 * `voided_at IS NULL`. Corrections are new rows; the ledger keeps both,
 * which is the audit trail. A void that would invert the mass
 * invariant (outputs exceeding remaining inputs) is refused.
 */
final class BatchIoService extends BaseService
{
    /** How far in the future a staff-entered timestamp may be (clock skew). */
    private const FUTURE_TOLERANCE_SECONDS = 600;

    public function __construct(
        ?BaseConnection $db,
        private readonly BmgPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly BmgSupport $support,
    ) {
        parent::__construct($db);
    }

    public function addBatchInput(int $batchId, array $input): array
    {
        return $this->recordIo($batchId, 'input', $input);
    }

    public function addBatchOutput(int $batchId, array $input): array
    {
        return $this->recordIo($batchId, 'output', $input);
    }

    /**
     * Void a manual input row. Refuses an already-voided row and a
     * void that would leave structured outputs exceeding remaining
     * structured inputs (same invariant the DB trigger guards on the
     * batch row, re-checked here across the ledger).
     */
    public function voidBatchInput(int $batchId, int $inputId, array $input): array
    {
        return $this->voidIo($batchId, 'input', $inputId, $input);
    }

    public function voidBatchOutput(int $batchId, int $outputId, array $input): array
    {
        return $this->voidIo($batchId, 'output', $outputId, $input);
    }

    /**
     * Read-only feed of manual inputs for a batch (oldest first).
     * Includes the void-aware running total and the void state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBatchInputs(int $batchId): array
    {
        $rows = $this->readableIoRows($batchId, 'input');

        $running = 0.0;
        return array_map(static function (array $r) use (&$running): array {
            if ($r['voided_at'] === null) {
                $running += (float) $r['weight_kg'];
            }
            return [
                'id'                     => (int) $r['id'],
                'batch_id'               => (int) $r['batch_id'],
                'weight_kg'              => (float) $r['weight_kg'],
                'category_id'            => $r['category_id'] !== null ? (int) $r['category_id'] : null,
                'category_name'          => $r['category_name'] ?? null,
                'cn_ratio'               => $r['cn_ratio'] !== null ? (float) $r['cn_ratio'] : null,
                'bulk_density_kg_per_m3' => $r['bulk_density_kg_per_m3'] !== null ? (float) $r['bulk_density_kg_per_m3'] : null,
                'ph'                     => $r['ph'] !== null ? (float) $r['ph'] : null,
                'note'                   => $r['note'] !== null ? (string) $r['note'] : null,
                'recorded_by_user_id'    => (int) $r['recorded_by_user_id'],
                'recorded_at'            => (string) $r['recorded_at'],
                'voided_at'              => $r['voided_at'] !== null ? (string) $r['voided_at'] : null,
                'void_reason'            => $r['void_reason'] !== null ? (string) $r['void_reason'] : null,
                'running_total_kg'       => round($running, 2),
            ];
        }, $rows);
    }

    /**
     * Read-only feed of manual outputs for a batch (oldest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBatchOutputs(int $batchId): array
    {
        $rows = $this->readableIoRows($batchId, 'output');

        $running = 0.0;
        return array_map(static function (array $r) use (&$running): array {
            if ($r['voided_at'] === null) {
                $running += (float) $r['output_weight_kg'];
            }
            return [
                'id'                  => (int) $r['id'],
                'batch_id'            => (int) $r['batch_id'],
                'output_weight_kg'    => (float) $r['output_weight_kg'],
                'harvest_date'        => $r['harvest_date'] !== null ? (string) $r['harvest_date'] : null,
                'quality_grade'       => $r['quality_grade'] !== null ? (string) $r['quality_grade'] : null,
                'note'                => $r['note'] !== null ? (string) $r['note'] : null,
                'recorded_by_user_id' => (int) $r['recorded_by_user_id'],
                'recorded_at'         => $r['recorded_at'] !== null ? (string) $r['recorded_at'] : (string) $r['created_at'],
                'voided_at'           => $r['voided_at'] !== null ? (string) $r['voided_at'] : null,
                'void_reason'         => $r['void_reason'] !== null ? (string) $r['void_reason'] : null,
                'running_total_kg'    => round($running, 2),
            ];
        }, $rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readableIoRows(int $batchId, string $kind): array
    {
        $this->assertBatchReadable($batchId);

        if ($kind === 'input') {
            return $this->db->table('facilities_bmg_inputs AS i')
                ->select('i.id, i.batch_id, i.weight_kg, i.category_id, c.name AS category_name, i.cn_ratio, i.bulk_density_kg_per_m3, i.ph, i.note, i.recorded_by_user_id, i.recorded_at, i.voided_at, i.void_reason')
                ->join('facilities_waste_categories AS c', 'c.id = i.category_id', 'left')
                ->where('i.batch_id', $batchId)
                ->where('i.tenant_id', CurrentTenant::id())
                ->orderBy('i.recorded_at', 'ASC')
                ->orderBy('i.id', 'ASC')
                ->get()->getResultArray();
        }

        return $this->db->table('facilities_bmg_outputs AS o')
            ->select('o.id, o.batch_id, o.output_weight_kg, o.harvest_date, o.quality_grade, o.note, o.recorded_by_user_id, o.recorded_at, o.created_at, o.voided_at, o.void_reason')
            ->where('o.batch_id', $batchId)
            ->where('o.tenant_id', CurrentTenant::id())
            // Third arg: the expression must NOT be backticked as one
            // identifier — legacy rows have no recorded_at.
            ->orderBy('COALESCE(o.recorded_at, o.created_at)', 'ASC', false)
            ->orderBy('o.id', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function recordIo(int $batchId, string $kind, array $input): array
    {
        // Tier 3.2 — load the batch row outside the txn so the policy
        // can see `started_by_user_id`. `io_record` is staff-wide now,
        // but the module-level permission check still passes through
        // the policy with the batch in hand.
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
                $recordedAt = $this->resolveStaffTimestamp($input['recorded_at'] ?? null, $now);
                $categoryId = $this->resolveCategoryId($input['category_id'] ?? null);
                $this->db->table('facilities_bmg_inputs')->insert([
                    'batch_id'              => $batchId,
                    'tenant_id'             => CurrentTenant::id(),
                    'weight_kg'             => (float) $input['weight_kg'],
                    'category_id'           => $categoryId,
                    'cn_ratio'              => isset($input['cn_ratio']) && $input['cn_ratio'] !== '' ? (float) $input['cn_ratio'] : null,
                    'bulk_density_kg_per_m3'=> isset($input['bulk_density_kg_per_m3']) && $input['bulk_density_kg_per_m3'] !== '' ? (float) $input['bulk_density_kg_per_m3'] : null,
                    'ph'                    => isset($input['ph']) && $input['ph'] !== '' ? (float) $input['ph'] : null,
                    'note'                  => isset($input['note']) && $input['note'] !== '' ? (string) $input['note'] : null,
                    'recorded_by_user_id'   => $userId,
                    'recorded_at'           => $recordedAt,
                    'created_at'            => $now,
                ]);
                $id = (int) $this->db->insertID();
                $this->audit->enqueue('bmg.input_recorded', 'facilities_bmg_inputs', $id, $userId, ['resource_code' => (string) $batch['reference_code']]);
                return [
                    'id'                     => $id,
                    'batch_id'               => $batchId,
                    'weight_kg'              => (float) $input['weight_kg'],
                    'category_id'            => $categoryId,
                    'recorded_at'            => $recordedAt,
                    'cn_ratio'               => isset($input['cn_ratio']) && $input['cn_ratio'] !== '' ? (float) $input['cn_ratio'] : null,
                    'bulk_density_kg_per_m3' => isset($input['bulk_density_kg_per_m3']) && $input['bulk_density_kg_per_m3'] !== '' ? (float) $input['bulk_density_kg_per_m3'] : null,
                    'ph'                     => isset($input['ph']) && $input['ph'] !== '' ? (float) $input['ph'] : null,
                ];
            }

            $recordedAt = $this->resolveStaffTimestamp($input['recorded_at'] ?? null, $now);
            $this->db->table('facilities_bmg_outputs')->insert([
                'batch_id'            => $batchId,
                'tenant_id'           => CurrentTenant::id(),
                'output_weight_kg'    => (float) $input['output_weight_kg'],
                'harvest_date'        => isset($input['harvest_date']) && $input['harvest_date'] !== '' ? (string) $input['harvest_date'] : null,
                'quality_grade'       => isset($input['quality_grade']) && $input['quality_grade'] !== '' ? (string) $input['quality_grade'] : null,
                'note'                => isset($input['note']) && $input['note'] !== '' ? (string) $input['note'] : null,
                'recorded_by_user_id' => $userId,
                'recorded_at'         => $recordedAt,
                'created_at'          => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->audit->enqueue('bmg.output_recorded_detail', 'facilities_bmg_outputs', $id, $userId, ['resource_code' => (string) $batch['reference_code']]);
            return ['id' => $id, 'batch_id' => $batchId, 'output_weight_kg' => (float) $input['output_weight_kg'], 'recorded_at' => $recordedAt];
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function voidIo(int $batchId, string $kind, int $rowId, array $input): array
    {
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('io_record', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'A void reason is required.', 'field' => 'reason'],
            ]);
        }

        return $this->txn(function () use ($batchId, $kind, $rowId, $reason, $userId): array {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }

            $table = $kind === 'input' ? 'facilities_bmg_inputs' : 'facilities_bmg_outputs';
            $row   = $this->selectForUpdate($table, ['id' => $rowId, 'batch_id' => $batchId, 'tenant_id' => CurrentTenant::id()]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => ucfirst($kind) . " #{$rowId} not found on batch #{$batchId}."],
                ]);
            }
            if ($row['voided_at'] !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => ucfirst($kind) . " #{$rowId} is already voided."],
                ]);
            }

            // Refuse a void that inverts the ledger invariant: remaining
            // (non-voided) structured inputs must still cover the
            // structured outputs. Same rule the batch trigger guards.
            // sumLedger already excludes the row being voided.
            if ($kind === 'input') {
                $inputKg  = $this->sumLedger($batchId, 'input', (int) $rowId);
                $outputKg = $this->sumLedger($batchId, 'output', null);
                if ($outputKg > $inputKg) {
                    throw new ApiException('statemachine.bmg.mass_invariant', 422, [
                        ['code' => 'statemachine.bmg.mass_invariant', 'message' => 'Voiding this input would leave outputs exceeding recorded inputs.'],
                    ]);
                }
            }

            $now = $this->support->utcNow();
            $this->db->table($table)
                ->where('tenant_id', CurrentTenant::id())
                ->where('id', $rowId)
                ->update([
                    'voided_at'         => $now,
                    'voided_by_user_id' => $userId,
                    'void_reason'       => $reason,
                ]);

            $this->audit->enqueue(
                $kind === 'input' ? 'bmg.input_voided' : 'bmg.output_voided',
                $table,
                $rowId,
                $userId,
                ['resource_code' => (string) $batch['reference_code'], 'reason_code' => $reason],
            );

            return [
                'id'         => $rowId,
                'batch_id'   => $batchId,
                'kind'       => $kind,
                'voided_at'  => $now,
                'void_reason' => $reason,
            ];
        });
    }

    /**
     * Sum of non-voided ledger weights for a batch, optionally
     * excluding one row (the candidate being voided).
     */
    private function sumLedger(int $batchId, string $kind, ?int $excludeId): float
    {
        $table  = $kind === 'input' ? 'facilities_bmg_inputs' : 'facilities_bmg_outputs';
        $column = $kind === 'input' ? 'weight_kg' : 'output_weight_kg';
        $builder = $this->db->table($table)
            ->selectSum($column, 't')
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->where('voided_at', null);
        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }
        $row = $builder->get()->getRowArray();
        return (float) ($row['t'] ?? 0);
    }

    private function assertBatchReadable(int $batchId): void
    {
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        // `io_read` mirrors `io_record`: whoever may WRITE the manual
        // ledger may read it back. Gating reads on the starter-only
        // `logs_read` made a staff member's own entry invisible to them
        // the moment they submitted it.
        $this->policy->check('io_read', $batch);
    }

    /**
     * Resolve the staff-entered date/time for a manual I/O row. The
     * operator types Manila wall time; storage is UTC (house rule).
     * Returns server-now when omitted. A timestamp more than the
     * tolerance in the future is a clock error, not a fact.
     */
    private function resolveStaffTimestamp(mixed $raw, string $fallbackUtc): string
    {
        if ($raw === null || $raw === '') {
            return $fallbackUtc;
        }
        $value = trim((string) $raw);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value, new DateTimeZone('Asia/Manila'))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('Asia/Manila'));
        if ($parsed === false) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'recorded_at must be a Manila local date/time (YYYY-MM-DD HH:MM:SS).', 'field' => 'recorded_at'],
            ]);
        }
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($parsed->getTimestamp() > $nowUtc->getTimestamp() + self::FUTURE_TOLERANCE_SECONDS) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'recorded_at cannot be in the future.', 'field' => 'recorded_at'],
            ]);
        }
        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * FK-safe category resolution — a bad id must 422, not break the
     * insert. A NULL or empty value is "no specific type".
     */
    private function resolveCategoryId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || (int) $raw <= 0) {
            return null;
        }
        $categoryId = (int) $raw;
        $exists = $this->db->table('facilities_waste_categories')
            ->select('id')
            ->where('id', $categoryId)
            ->where('tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        if ($exists === null) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => "Waste category #{$categoryId} not found.", 'field' => 'category_id'],
            ]);
        }
        return $categoryId;
    }
}
