<?php

declare(strict_types=1);

namespace Modules\Facilities\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Facilities\DTOs\BmgBatchDto;
use Modules\Facilities\DTOs\BmgUnitDto;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * BmgService — Facilities state machine.
 *
 * Lifecycle (per directive):
 *   Idle -> Processing -> AwaitingOutput -> Idle  (or -> Cancelled)
 *
  * Concurrency: `selectForUpdate` on the unit row before any state change.
 * The DB-level UNIQUE index on `active_unit_id` is the final guard.
 */
final class BmgService extends BaseService
{
    public function __construct(
        private readonly BmgPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUnits(?string $cursor, int $limit, bool $includeArchived = false): array
    {
        $this->policy->check('list');

        $builder = $this->db->table('facilities_bmg_units AS u')
            ->select('u.id, u.code, u.display_name, u.status, u.location_code, u.spec_capacity_kg, u.default_category_id, u.notes, u.created_at, u.updated_at, u.archived_at, c.name AS default_category_name, b.id AS active_batch_id')
            ->join(
                'facilities_bmg_batches AS b',
                "b.unit_id = u.id AND b.archived_at IS NULL AND b.status IN ('" . BMG_STATE_PROCESSING . "', '" . BMG_STATE_AWAITING_OUTPUT . "')",
                'left',
                false, // no identifier escaping — the ON clause carries quoted literals
            )
            ->join('facilities_waste_categories AS c', 'c.id = u.default_category_id', 'left')
            ->orderBy('u.created_at', 'DESC')
            ->orderBy('u.id', 'DESC');

        if (! $includeArchived) {
            $builder->where('u.archived_at', null);
        }

        KeysetPaginator::apply($builder, $cursor, $limit, 'u.created_at', 'u.id');

        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit, 'u.created_at');

        return [
            'data'  => array_map(static fn (array $r) => BmgUnitDto::fromRow($r)->toArray(), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * @param array<int, array{sku:string, qty_kg:float}> $inputItems
     */
    public function startBatch(int $unitId, array $inputItems, float $totalInputKg): BmgBatchDto
    {
        $this->policy->check('start');
        $userId = \App\Auth\CurrentUser::assert();

        if ($totalInputKg <= 0) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'total_input_weight_kg must be > 0.', 'field' => 'total_input_weight_kg'],
            ]);
        }

        return $this->txn(function () use ($unitId, $inputItems, $totalInputKg, $userId): BmgBatchDto {
            // Lock the unit row.
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'archived_at' => null]);

            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found."],
                ]);
            }

            // A unit can only enter Processing from Idle. A unit parked in
            // Maintenance (see setUnitMaintenance()) is also rejected by
            // this check — we surface the existing state to the caller
            // rather than a generic "not idle" so the SPA can explain.
            if ($unit['status'] !== BMG_STATE_IDLE) {
                throw StateMachineException::invalidTransition($unit['status'], BMG_STATE_PROCESSING, 'bmg');
            }

            // Insert the batch. The generated `active_unit_id` column + UNIQUE
            // index will reject a duplicate if the unit slipped past us.
            // `category_id` falls back to the unit's default category if the
            // operator didn't override it.
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $ref = 'BMG-' . date('Ymd') . '-' . bin2hex(random_bytes(4));

            $this->db->table('facilities_bmg_batches')->insert([
                'unit_id'               => $unitId,
                'category_id'           => $unit['default_category_id'] !== null ? (int) $unit['default_category_id'] : null,
                'reference_code'        => $ref,
                'status'                => BMG_STATE_PROCESSING,
                'total_input_weight_kg' => $totalInputKg,
                'input_items'           => json_encode($inputItems, JSON_THROW_ON_ERROR),
                'started_by_user_id'    => $userId,
                'started_at'            => $now,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

            $batchId = (int) $this->db->insertID();

            // Update the unit.
            $this->db->table('facilities_bmg_units')
                ->where('id', $unitId)
                ->update([
                    'status'     => BMG_STATE_PROCESSING,
                    'updated_at' => $now,
                ]);

            $this->audit->enqueue(
                'bmg.batch_started',
                'facilities_bmg_batches',
                $batchId,
                $userId,
                ['resource_code' => $ref, 'next_status' => BMG_STATE_PROCESSING],
            );

            $batch = $this->db->table('facilities_bmg_batches')->where('id', $batchId)->get()->getRowArray();
            return BmgBatchDto::fromRow($batch);
        });
    }

    /**
     * Read-only access to a batch's total input weight, used to feed the
     * `bmg_mass_invariant` validation rule in `BmgController::recordOutput`.
     *
     * Throws 404 if the batch is missing or archived.
     */
    public function peekInputKg(int $batchId): float
    {
        $row = $this->db->table('facilities_bmg_batches')
            ->select('total_input_weight_kg')
            ->where('id', $batchId)
            ->where('archived_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
            ]);
        }
        return (float) $row['total_input_weight_kg'];
    }

    /**
     * @param array<int, array{sku:string, qty_kg:float}> $outputItems
     */
    public function recordOutput(int $batchId, float $outputKg, array $outputItems): BmgBatchDto
    {
        $this->policy->check('record_output');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $outputKg, $outputItems, $userId): BmgBatchDto {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'archived_at' => null]);

            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }

            if ($batch['status'] !== BMG_STATE_PROCESSING) {
                throw StateMachineException::invalidTransition($batch['status'], BMG_STATE_AWAITING_OUTPUT, 'bmg');
            }

            // Application-level mass invariant (the DB trigger is the second guard).
            if ($outputKg > (float) $batch['total_input_weight_kg']) {
                throw StateMachineException::massInvariant();
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            try {
                $this->db->table('facilities_bmg_batches')
                    ->where('id', $batchId)
                    ->update([
                        'status'             => BMG_STATE_AWAITING_OUTPUT,
                        'output_weight_kg'   => $outputKg,
                        'output_items'       => json_encode($outputItems, JSON_THROW_ON_ERROR),
                        'awaiting_output_at' => $now,
                        'updated_at'         => $now,
                    ]);
            } catch (\Throwable $t) {
                // Surface trigger violation as a clean 422.
                if (str_contains($t->getMessage(), 'BMG mass invariant')) {
                    throw StateMachineException::massInvariant();
                }
                throw $t;
            }

            $this->audit->enqueue(
                'bmg.output_recorded',
                'facilities_bmg_batches',
                $batchId,
                $userId,
                ['previous_status' => BMG_STATE_PROCESSING, 'next_status' => BMG_STATE_AWAITING_OUTPUT, 'reason_code' => 'record_output'],
            );

            $fresh = $this->db->table('facilities_bmg_batches')->where('id', $batchId)->get()->getRowArray();
            return BmgBatchDto::fromRow($fresh);
        });
    }

    public function finishBatch(int $batchId): BmgBatchDto
    {
        $this->policy->check('finish');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $userId): BmgBatchDto {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'archived_at' => null]);

            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }

            if ($batch['status'] !== BMG_STATE_AWAITING_OUTPUT) {
                throw StateMachineException::invalidTransition($batch['status'], BMG_STATE_IDLE, 'bmg');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('facilities_bmg_batches')
                ->where('id', $batchId)
                ->update([
                    'status'              => BMG_STATE_IDLE,
                    'finished_at'         => $now,
                    'finished_by_user_id' => $userId,
                    'updated_at'          => $now,
                ]);

            $this->db->table('facilities_bmg_units')
                ->where('id', (int) $batch['unit_id'])
                ->update(['status' => BMG_STATE_IDLE, 'updated_at' => $now]);

            $this->audit->enqueue(
                'bmg.batch_finished',
                'facilities_bmg_batches',
                $batchId,
                $userId,
                ['previous_status' => BMG_STATE_AWAITING_OUTPUT, 'next_status' => BMG_STATE_IDLE],
            );

            $fresh = $this->db->table('facilities_bmg_batches')->where('id', $batchId)->get()->getRowArray();
            return BmgBatchDto::fromRow($fresh);
        });
    }

    public function cancelBatch(int $batchId, string $reasonCode): BmgBatchDto
    {
        $this->policy->check('cancel');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $reasonCode, $userId): BmgBatchDto {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'archived_at' => null]);

            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }

            if (in_array($batch['status'], [BMG_STATE_IDLE, BMG_STATE_CANCELLED], true)) {
                throw StateMachineException::invalidTransition($batch['status'], BMG_STATE_CANCELLED, 'bmg');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('facilities_bmg_batches')
                ->where('id', $batchId)
                ->update([
                    'status'       => BMG_STATE_CANCELLED,
                    'cancelled_at' => $now,
                    'notes'        => ($batch['notes'] ?? '') !== ''
                        ? (string) $batch['notes'] . ' | cancel: ' . $reasonCode
                        : 'cancel: ' . $reasonCode,
                    'updated_at'   => $now,
                ]);

            $this->db->table('facilities_bmg_units')
                ->where('id', (int) $batch['unit_id'])
                ->update(['status' => BMG_STATE_IDLE, 'updated_at' => $now]);

            $this->audit->enqueue(
                'bmg.batch_cancelled',
                'facilities_bmg_batches',
                $batchId,
                $userId,
                ['previous_status' => (string) $batch['status'], 'next_status' => BMG_STATE_CANCELLED, 'reason_code' => $reasonCode],
            );

            $fresh = $this->db->table('facilities_bmg_batches')->where('id', $batchId)->get()->getRowArray();
            return BmgBatchDto::fromRow($fresh);
        });
    }

    // ------------------------------------------------- drum CRUD

    /**
     * Register a new BMG unit. The `code` is unique and uppercased
     * server-side so the legacy convention (`DRUM-01`, `DRUM-02`, …) is
     * preserved regardless of how the client types it. Same-transaction
     * audit row.
     *
     * @param array{code:string, display_name:string, location_code?:?string, spec_capacity_kg?:?float, default_category_id?:?int, notes?:?string} $input
     */
    public function createUnit(array $input): BmgUnitDto
    {
        $this->policy->check('manage_units');
        $userId = \App\Auth\CurrentUser::assert();

        $code = strtoupper(trim($input['code']));
        if ($code === '') {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'code is required.', 'field' => 'code'],
            ]);
        }

        $defaultCategoryId = $this->resolveCategoryId($input['default_category_id'] ?? null);

        return $this->txn(function () use ($input, $code, $defaultCategoryId, $userId): BmgUnitDto {
            $dup = $this->db->table('facilities_bmg_units')->where('code', $code)->get()->getRowArray();
            if ($dup !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "A unit with code '{$code}' already exists.", 'field' => 'code'],
                ]);
            }
            $now = $this->utcNow();
            $this->db->table('facilities_bmg_units')->insert([
                'code'                => $code,
                'display_name'        => trim((string) $input['display_name']),
                'location_code'       => isset($input['location_code']) && $input['location_code'] !== ''
                    ? trim((string) $input['location_code']) : null,
                'status'              => BMG_STATE_IDLE,
                'spec_capacity_kg'    => isset($input['spec_capacity_kg']) && $input['spec_capacity_kg'] !== ''
                    ? (float) $input['spec_capacity_kg'] : null,
                'default_category_id' => $defaultCategoryId,
                'notes'               => isset($input['notes']) && $input['notes'] !== ''
                    ? (string) $input['notes'] : null,
                'archived_at'         => null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'bmg.unit_created',
                'facilities_bmg_units',
                $id,
                $userId,
                ['resource_code' => $code, 'next_status' => BMG_STATE_IDLE],
            );

            $row = $this->db->table('facilities_bmg_units AS u')
                ->select('u.*, c.name AS default_category_name')
                ->join('facilities_waste_categories AS c', 'c.id = u.default_category_id', 'left')
                ->where('u.id', $id)
                ->get()->getRowArray();
            return BmgUnitDto::fromRow($row);
        });
    }

    /**
     * Update a unit's mutable fields. `code` is immutable (matches the
     * legacy "Drum code cannot be changed" rule). The state machine
     * is owned elsewhere — this method refuses to mutate `status`.
     *
     * @param array{display_name?:string, location_code?:?string, spec_capacity_kg?:?float, default_category_id?:?int, notes?:?string} $input
     */
    public function updateUnit(int $unitId, array $input): BmgUnitDto
    {
        $this->policy->check('manage_units');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($unitId, $input, $userId): BmgUnitDto {
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'archived_at' => null]);
            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found."],
                ]);
            }

            $update = ['updated_at' => $this->utcNow()];
            if (array_key_exists('display_name', $input) && $input['display_name'] !== null) {
                $dn = trim((string) $input['display_name']);
                if ($dn === '') {
                    throw new ApiException('validation.invalid', 422, [
                        ['code' => 'validation.invalid', 'message' => 'display_name cannot be empty.', 'field' => 'display_name'],
                    ]);
                }
                $update['display_name'] = $dn;
            }
            if (array_key_exists('location_code', $input)) {
                $update['location_code'] = $input['location_code'] !== null && $input['location_code'] !== ''
                    ? trim((string) $input['location_code']) : null;
            }
            if (array_key_exists('spec_capacity_kg', $input)) {
                $update['spec_capacity_kg'] = $input['spec_capacity_kg'] !== null && $input['spec_capacity_kg'] !== ''
                    ? (float) $input['spec_capacity_kg'] : null;
            }
            if (array_key_exists('default_category_id', $input)) {
                $update['default_category_id'] = $this->resolveCategoryId($input['default_category_id']);
            }
            if (array_key_exists('notes', $input)) {
                $update['notes'] = $input['notes'] !== null && $input['notes'] !== ''
                    ? (string) $input['notes'] : null;
            }

            $this->db->table('facilities_bmg_units')->where('id', $unitId)->update($update);

            $this->audit->enqueue(
                'bmg.unit_updated',
                'facilities_bmg_units',
                $unitId,
                $userId,
                ['resource_code' => (string) $unit['code']],
            );

            $fresh = $this->db->table('facilities_bmg_units AS u')
                ->select('u.*, c.name AS default_category_name')
                ->join('facilities_waste_categories AS c', 'c.id = u.default_category_id', 'left')
                ->where('u.id', $unitId)
                ->get()->getRowArray();
            return BmgUnitDto::fromRow($fresh);
        });
    }

    /**
     * Validate a default-category id. Returns null for blank / 0 /
     * missing; otherwise returns the row id and 404s if the category
     * doesn't exist.
     */
    private function resolveCategoryId(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }
        $id = (int) $raw;
        if ($id <= 0) {
            return null;
        }
        $row = $this->db->table('facilities_waste_categories')
            ->select('id, is_active')
            ->where('id', $id)
            ->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Waste category #{$id} not found.", 'field' => 'default_category_id'],
            ]);
        }
        return $id;
    }

    /**
     * Soft-archive a unit. Sets `archived_at`, refuses if the unit still
     * has an active batch (must be finished or cancelled first — same
     * invariant the legacy controller enforced by checking
     * `bmg_batches.status IN ('input','processing')`).
     */
    public function archiveUnit(int $unitId): BmgUnitDto
    {
        $this->policy->check('manage_units');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($unitId, $userId): BmgUnitDto {
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'archived_at' => null]);
            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found or already archived."],
                ]);
            }

            $active = $this->db->table('facilities_bmg_batches')
                ->where('unit_id', $unitId)
                ->where('archived_at', null)
                ->whereIn('status', [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT])
                ->countAllResults();
            if ($active > 0) {
                throw new ApiException('statemachine.bmg.unit_has_active_batch', 409, [
                    ['code' => 'statemachine.bmg.unit_has_active_batch', 'message' => 'Cannot archive a unit with an active batch. Finish or cancel the batch first.'],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('facilities_bmg_units')->where('id', $unitId)->update([
                'archived_at' => $now,
                'updated_at'  => $now,
            ]);

            $this->audit->enqueue(
                'bmg.unit_archived',
                'facilities_bmg_units',
                $unitId,
                $userId,
                ['resource_code' => (string) $unit['code']],
            );

            $fresh = $this->db->table('facilities_bmg_units')->where('id', $unitId)->get()->getRowArray();
            return BmgUnitDto::fromRow($fresh);
        });
    }

    /**
     * Restore a soft-archived unit: clears `archived_at` so the drum
     * rejoins the active list in Idle (its stored status is untouched
     * — an archived drum can only ever be Idle or Maintenance since
     * archiving refuses units with an active batch). Idempotent.
     */
    public function unarchiveUnit(int $unitId): BmgUnitDto
    {
        $this->policy->check('manage_units');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($unitId, $userId): BmgUnitDto {
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId]);
            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found."],
                ]);
            }
            if ($unit['archived_at'] === null) {
                // Idempotent: already active — just return the row.
                $fresh = $this->db->table('facilities_bmg_units AS u')
                    ->select('u.*, c.name AS default_category_name')
                    ->join('facilities_waste_categories AS c', 'c.id = u.default_category_id', 'left')
                    ->where('u.id', $unitId)
                    ->get()->getRowArray();
                return BmgUnitDto::fromRow($fresh);
            }

            $now = $this->utcNow();
            $this->db->table('facilities_bmg_units')->where('id', $unitId)->update([
                'archived_at' => null,
                'updated_at'  => $now,
            ]);

            $this->audit->enqueue(
                'bmg.unit_restored',
                'facilities_bmg_units',
                $unitId,
                $userId,
                ['resource_code' => (string) $unit['code']],
            );

            $fresh = $this->db->table('facilities_bmg_units AS u')
                ->select('u.*, c.name AS default_category_name')
                ->join('facilities_waste_categories AS c', 'c.id = u.default_category_id', 'left')
                ->where('u.id', $unitId)
                ->get()->getRowArray();
            return BmgUnitDto::fromRow($fresh);
        });
    }

    /**
     * Active-batch dashboard feed: every batch in Processing or AwaitingOutput
     * joined to its unit and (optionally) its waste category, enriched with
     * `days_active`, `expected_completion_date`, `days_until_expected`, and
     * `progress_pct` computed deterministically via {@see BmgAnalytics}.
     *
     * Used by the SPA's "Processing Drums" widget — a single round-trip
     * keeps the dashboard responsive behind the single-threaded dev server.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listActiveBatches(): array
    {
        $this->policy->check('list');

        $rows = $this->db->table('facilities_bmg_batches AS b')
            ->select(
                'b.id            AS batch_id,'
                . ' b.reference_code AS batch_code,'
                . ' b.status       AS batch_status,'
                . ' b.total_input_weight_kg,'
                . ' b.output_weight_kg,'
                . ' b.started_at,'
                . ' b.category_id,'
                . ' u.id            AS unit_id,'
                . ' u.code          AS unit_code,'
                . ' u.display_name  AS unit_name,'
                . ' u.location_code AS unit_location,'
                . ' c.name          AS category_name,'
                . ' c.reference_duration_days'
            )
            ->join('facilities_bmg_units AS u', 'u.id = b.unit_id', 'left')
            ->join('facilities_waste_categories AS c', 'c.id = b.category_id', 'left')
            ->where('b.archived_at', null)
            ->whereIn('b.status', [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT])
            ->orderBy('b.started_at', 'ASC')
            ->get()->getResultArray();

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $a     = new \App\Services\Analytics\BmgAnalytics();

        return array_map(static function (array $r) use ($a, $today): array {
            $startDate = substr((string) $r['started_at'], 0, 10);
            $refDays   = $r['reference_duration_days'] !== null ? (int) $r['reference_duration_days'] : 0;
            // expectedCompletionDate() falls back to 45 days when the batch has
            // no category reference, so the widget's progress bar always moves.
            $expected  = $a->expectedCompletionDate($startDate, $refDays);

            $daysActive = max(0, (int) ((new DateTimeImmutable($today))->diff(new DateTimeImmutable($startDate)))->days);

            return [
                'batch_id'                 => (int)    $r['batch_id'],
                'batch_code'               => (string) $r['batch_code'],
                'batch_status'             => (string) $r['batch_status'],
                'unit_id'                  => (int)    $r['unit_id'],
                'unit_code'                => (string) $r['unit_code'],
                'unit_name'                => (string) $r['unit_name'],
                'unit_location'            => $r['unit_location'] !== null ? (string) $r['unit_location'] : null,
                'category_name'            => $r['category_name'] !== null ? (string) $r['category_name'] : null,
                'input_kg'                 => round((float) $r['total_input_weight_kg'], 2),
                'output_kg'                => $r['output_weight_kg'] !== null ? round((float) $r['output_weight_kg'], 2) : null,
                'started_at'               => (string) $r['started_at'],
                'days_active'              => $daysActive,
                'reference_duration_days'  => $refDays > 0 ? $refDays : null,
                'expected_completion_date' => $expected,
                'days_until_expected'      => $a->daysUntilExpected($expected, $today),
                'progress_pct'             => $a->progressPercent($startDate, $expected, $today),
            ];
        }, $rows);
    }

    // ------------------------------------------------- process logs

    /**
     * Chronological process observations for a batch (recycled from
     * legacy bmg_process_logs — Phase 16).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listProcessLogs(int $batchId): array
    {
        $this->policy->check('logs_read');

        // 404 on missing/archived batch (reuses the read helper).
        $this->peekInputKg($batchId);

        $rows = $this->db->table('facilities_bmg_process_logs')
            ->where('batch_id', $batchId)
            ->orderBy('log_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'                  => (int) $r['id'],
            'batch_id'            => (int) $r['batch_id'],
            'log_date'            => (string) $r['log_date'],
            'observation_note'    => $r['observation_note'] !== null ? (string) $r['observation_note'] : null,
            'temperature_celsius' => $r['temperature_celsius'] !== null ? (float) $r['temperature_celsius'] : null,
            'moisture_level'      => $r['moisture_level'] !== null ? (string) $r['moisture_level'] : null,
            'recorded_by_user_id' => (int) $r['recorded_by_user_id'],
            'created_at'          => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * Record an observation against an ACTIVE batch. Legacy semantics:
     * logs exist to debug the decomposition period, so terminal batches
     * (idle/finished or cancelled) reject new entries.
     *
     * @param array<string, mixed> $input validated payload
     * @return array<string, mixed>
     */
    public function addProcessLog(int $batchId, array $input): array
    {
        $this->policy->check('logs_record');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $input, $userId): array {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'archived_at' => null]);
            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }
            if (! in_array($batch['status'], [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT], true)) {
                throw new ApiException('statemachine.bmg.log_terminal_batch', 409, [
                    ['code' => 'statemachine.bmg.log_terminal_batch', 'message' => 'Process logs can only be added while a batch is active.'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('facilities_bmg_process_logs')->insert([
                'batch_id'            => $batchId,
                'log_date'            => (string) ($input['log_date'] ?? substr($now, 0, 10)),
                'observation_note'    => isset($input['observation_note']) && $input['observation_note'] !== '' ? (string) $input['observation_note'] : null,
                'temperature_celsius' => isset($input['temperature_celsius']) && $input['temperature_celsius'] !== '' ? (float) $input['temperature_celsius'] : null,
                'moisture_level'      => isset($input['moisture_level']) && $input['moisture_level'] !== '' ? (string) $input['moisture_level'] : null,
                'recorded_by_user_id' => $userId,
                'created_at'          => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'bmg.process_log_recorded',
                'facilities_bmg_process_logs',
                $id,
                $userId,
                ['resource_code' => (string) $batch['reference_code']],
            );

            $row = $this->db->table('facilities_bmg_process_logs')->where('id', $id)->get()->getRowArray();
            return [
                'id'                  => (int) $row['id'],
                'batch_id'            => (int) $row['batch_id'],
                'log_date'            => (string) $row['log_date'],
                'observation_note'    => $row['observation_note'] !== null ? (string) $row['observation_note'] : null,
                'temperature_celsius' => $row['temperature_celsius'] !== null ? (float) $row['temperature_celsius'] : null,
                'moisture_level'      => $row['moisture_level'] !== null ? (string) $row['moisture_level'] : null,
                'recorded_by_user_id' => (int) $row['recorded_by_user_id'],
                'created_at'          => (string) $row['created_at'],
            ];
        });
    }

    // ------------------------------------------------- waste categories

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listWasteCategories(bool $activeOnly = false): array
    {
        $this->policy->check('list');
        $builder = $this->db->table('facilities_waste_categories')
            ->select('id, code, name, description, expected_yield_pct, reference_duration_days, is_active')
            ->orderBy('name', 'ASC');
        if ($activeOnly) {
            $builder->where('is_active', 1);
        }
        return array_map(static fn (array $r): array => [
            'id'                      => (int) $r['id'],
            'code'                    => (string) $r['code'],
            'name'                    => (string) $r['name'],
            'description'             => $r['description'] !== null ? (string) $r['description'] : null,
            'expected_yield_pct'      => $r['expected_yield_pct'] !== null ? (float) $r['expected_yield_pct'] : null,
            'reference_duration_days' => $r['reference_duration_days'] !== null ? (int) $r['reference_duration_days'] : null,
            'is_active'               => (bool) $r['is_active'],
        ], $builder->get()->getResultArray());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createWasteCategory(array $input): array
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): array {
            $dup = $this->db->table('facilities_waste_categories')->where('code', (string) $input['code'])->get()->getRowArray();
            if ($dup !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => 'A waste category with this code already exists.', 'field' => 'code'],
                ]);
            }
            $now = $this->utcNow();
            $this->db->table('facilities_waste_categories')->insert([
                'code'                    => (string) $input['code'],
                'name'                    => (string) $input['name'],
                'description'             => isset($input['description']) && $input['description'] !== '' ? (string) $input['description'] : null,
                'expected_yield_pct'      => isset($input['expected_yield_pct']) && $input['expected_yield_pct'] !== '' ? (float) $input['expected_yield_pct'] : null,
                'reference_duration_days' => isset($input['reference_duration_days']) && $input['reference_duration_days'] !== '' ? (int) $input['reference_duration_days'] : null,
                'is_active'               => 1,
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->audit->enqueue('bmg.waste_category_created', 'facilities_waste_categories', $id, $userId, [
                'resource_code' => (string) $input['code'],
            ]);
            return ['id' => $id, 'code' => (string) $input['code'], 'name' => (string) $input['name'], 'is_active' => true];
        });
    }

    /**
     * Update a waste category. `code` is immutable (matches the legacy
     * rule and the `UNIQUE` index that backs it). All other fields are
     * optional so the form can PATCH only the changed values. Soft
     * activation/deactivation happens here too.
     *
     * @param array{name?:string, description?:?string, expected_yield_pct?:?float, reference_duration_days?:?int, is_active?:bool} $input
     * @return array<string, mixed>
     */
    public function updateWasteCategory(int $categoryId, array $input): array
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($categoryId, $input, $userId): array {
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId]);
            if ($cat === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Waste category #{$categoryId} not found."],
                ]);
            }

            $update = ['updated_at' => $this->utcNow()];
            if (array_key_exists('name', $input) && $input['name'] !== null) {
                $n = trim((string) $input['name']);
                if ($n === '') {
                    throw new ApiException('validation.invalid', 422, [
                        ['code' => 'validation.invalid', 'message' => 'name cannot be empty.', 'field' => 'name'],
                    ]);
                }
                $update['name'] = $n;
            }
            if (array_key_exists('description', $input)) {
                $update['description'] = $input['description'] !== null && $input['description'] !== ''
                    ? (string) $input['description'] : null;
            }
            if (array_key_exists('expected_yield_pct', $input)) {
                $update['expected_yield_pct'] = $input['expected_yield_pct'] !== null && $input['expected_yield_pct'] !== ''
                    ? (float) $input['expected_yield_pct'] : null;
            }
            if (array_key_exists('reference_duration_days', $input)) {
                $update['reference_duration_days'] = $input['reference_duration_days'] !== null && $input['reference_duration_days'] !== ''
                    ? (int) $input['reference_duration_days'] : null;
            }
            if (array_key_exists('is_active', $input) && $input['is_active'] !== null) {
                $update['is_active'] = $input['is_active'] ? 1 : 0;
            }

            $this->db->table('facilities_waste_categories')->where('id', $categoryId)->update($update);

            $this->audit->enqueue('bmg.waste_category_updated', 'facilities_waste_categories', $categoryId, $userId, [
                'resource_code' => (string) $cat['code'],
            ]);

            $fresh = $this->db->table('facilities_waste_categories')->where('id', $categoryId)->get()->getRowArray();
            return [
                'id'                      => (int) $fresh['id'],
                'code'                    => (string) $fresh['code'],
                'name'                    => (string) $fresh['name'],
                'description'             => $fresh['description'] !== null ? (string) $fresh['description'] : null,
                'expected_yield_pct'      => $fresh['expected_yield_pct'] !== null ? (float) $fresh['expected_yield_pct'] : null,
                'reference_duration_days' => $fresh['reference_duration_days'] !== null ? (int) $fresh['reference_duration_days'] : null,
                'is_active'               => (bool) $fresh['is_active'],
            ];
        });
    }

    /**
     * Soft-archive a waste category by setting `is_active = 0`. The
     * FK `default_category_id` on `facilities_bmg_units` is `SET NULL`
     * on parent change — but the operator may have set a drum's default
     * to this category, so we refuse the archive if any active unit
     * still references it. The operator must clear the unit's
     * `default_category_id` first.
     */
    public function archiveWasteCategory(int $categoryId): array
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($categoryId, $userId): array {
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId]);
            if ($cat === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Waste category #{$categoryId} not found."],
                ]);
            }
            if ((int) $cat['is_active'] === 0) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => 'Category is already archived.', 'field' => 'is_active'],
                ]);
            }

            // Guard: refuse to archive while any unit still uses this as its default.
            $refs = $this->db->table('facilities_bmg_units')
                ->where('default_category_id', $categoryId)
                ->where('archived_at', null)
                ->countAllResults();
            if ($refs > 0) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "Cannot archive: {$refs} unit(s) still use this category as their default. Clear those first."],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('facilities_waste_categories')->where('id', $categoryId)->update([
                'is_active'  => 0,
                'updated_at' => $now,
            ]);

            $this->audit->enqueue('bmg.waste_category_archived', 'facilities_waste_categories', $categoryId, $userId, [
                'resource_code' => (string) $cat['code'],
            ]);

            return [
                'id'        => (int) $cat['id'],
                'code'      => (string) $cat['code'],
                'name'      => (string) $cat['name'],
                'is_active' => false,
            ];
        });
    }

    /**
     * Restore an archived waste category (`is_active = 1`) so it
     * reappears in pickers. Mirrors `archiveWasteCategory` — 409 when
     * the category is already active, same-transaction audit row.
     */
    public function unarchiveWasteCategory(int $categoryId): array
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($categoryId, $userId): array {
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId]);
            if ($cat === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Waste category #{$categoryId} not found."],
                ]);
            }
            if ((int) $cat['is_active'] === 1) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => 'Category is already active.', 'field' => 'is_active'],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('facilities_waste_categories')->where('id', $categoryId)->update([
                'is_active'  => 1,
                'updated_at' => $now,
            ]);

            $this->audit->enqueue('bmg.waste_category_restored', 'facilities_waste_categories', $categoryId, $userId, [
                'resource_code' => (string) $cat['code'],
            ]);

            return [
                'id'        => (int) $cat['id'],
                'code'      => (string) $cat['code'],
                'name'      => (string) $cat['name'],
                'is_active' => true,
            ];
        });
    }

    /**
     * Hard-delete a waste category. Refuses if any batch or unit still
     * references it — the operator should archive instead, which keeps
     * the category out of pickers but preserves history. Mirrors the
     * legacy `WasteCategoryController::delete()` guard.
     */
    public function deleteWasteCategory(int $categoryId): void
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        $this->txn(function () use ($categoryId, $userId): void {
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId]);
            if ($cat === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Waste category #{$categoryId} not found."],
                ]);
            }

            $batchRefs = $this->db->table('facilities_bmg_batches')
                ->where('category_id', $categoryId)
                ->countAllResults();
            if ($batchRefs > 0) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "Cannot delete: {$batchRefs} batch(es) reference this category. Archive it instead."],
                ]);
            }
            $unitRefs = $this->db->table('facilities_bmg_units')
                ->where('default_category_id', $categoryId)
                ->countAllResults();
            if ($unitRefs > 0) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "Cannot delete: {$unitRefs} unit(s) reference this category as their default. Clear those first."],
                ]);
            }

            $this->db->table('facilities_waste_categories')->where('id', $categoryId)->delete();

            $this->audit->enqueue('bmg.waste_category_deleted', 'facilities_waste_categories', $categoryId, $userId, [
                'resource_code' => (string) $cat['code'],
            ]);
        });
    }

    // --------------------------------------------------- structured I/O

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
        $this->policy->check('io_record');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $kind, $input, $userId): array {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'archived_at' => null]);
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
            $now = $this->utcNow();

            if ($kind === 'input') {
                $this->db->table('facilities_bmg_inputs')->insert([
                    'batch_id'            => $batchId,
                    'weight_kg'           => (float) $input['weight_kg'],
                    'note'                => isset($input['note']) && $input['note'] !== '' ? (string) $input['note'] : null,
                    'recorded_by_user_id' => $userId,
                    'recorded_at'         => $now,
                    'created_at'          => $now,
                ]);
                $id = (int) $this->db->insertID();
                $this->audit->enqueue('bmg.input_recorded', 'facilities_bmg_inputs', $id, $userId, ['resource_code' => (string) $batch['reference_code']]);
                return ['id' => $id, 'batch_id' => $batchId, 'weight_kg' => (float) $input['weight_kg']];
            }

            $this->db->table('facilities_bmg_outputs')->insert([
                'batch_id'            => $batchId,
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

    /**
     * Deterministic yield/ETA analytics for a batch (Phase P4).
     *
     * @return array<string, mixed>
     */
    public function batchAnalytics(int $batchId): array
    {
        $this->policy->check('analytics');

        $batch = $this->db->table('facilities_bmg_batches')
            ->select('id, reference_code, category_id, status, total_input_weight_kg, output_weight_kg, started_at, finished_at')
            ->where('id', $batchId)->where('archived_at', null)
            ->get()->getRowArray();
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
            ]);
        }

        $structuredIn  = (float) ($this->db->table('facilities_bmg_inputs')->selectSum('weight_kg', 't')->where('batch_id', $batchId)->get()->getRowArray()['t'] ?? 0);
        $structuredOut = (float) ($this->db->table('facilities_bmg_outputs')->selectSum('output_weight_kg', 't')->where('batch_id', $batchId)->get()->getRowArray()['t'] ?? 0);

        $inputKg  = $structuredIn > 0 ? $structuredIn : (float) $batch['total_input_weight_kg'];
        $outputKg = $structuredOut > 0 ? $structuredOut : (float) ($batch['output_weight_kg'] ?? 0);

        $category = null;
        if ($batch['category_id'] !== null) {
            $category = $this->db->table('facilities_waste_categories')
                ->select('name, expected_yield_pct, reference_duration_days')
                ->where('id', (int) $batch['category_id'])->get()->getRowArray();
        }

        $a         = new \App\Services\Analytics\BmgAnalytics();
        $yield     = $a->computeYield($inputKg, $outputKg);
        $startDate = substr((string) $batch['started_at'], 0, 10);
        $refDays   = $category !== null && $category['reference_duration_days'] !== null ? (int) $category['reference_duration_days'] : 0;
        $expected  = $a->expectedCompletionDate($startDate, $refDays);
        $today     = $this->utcNow();

        return [
            'batch_id'                => $batchId,
            'input_kg'                => round($inputKg, 2),
            'output_kg'               => round($outputKg, 2),
            'yield_pct'               => $yield,
            'yield_class'             => $a->classifyYield($yield),
            'mass_reduction_pct'      => $a->massReduction($yield),
            'expected_yield_pct'      => $category !== null && $category['expected_yield_pct'] !== null ? (float) $category['expected_yield_pct'] : null,
            'category_name'           => $category['name'] ?? null,
            'reference_duration_days' => $refDays > 0 ? $refDays : null,
            'expected_completion_date'=> $refDays > 0 ? $expected : null,
            'days_until_expected'     => $refDays > 0 ? $a->daysUntilExpected($expected, $today) : null,
            'progress_pct'            => $refDays > 0 ? $a->progressPercent($startDate, $expected, $today) : null,
        ];
    }

    /** Toggle a unit between Idle and Maintenance (only when not busy). */
    public function setUnitMaintenance(int $unitId, bool $maintenance): array
    {
        $this->policy->check('finish'); // facilities.bmg.transition
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($unitId, $maintenance, $userId): array {
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'archived_at' => null]);
            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found."],
                ]);
            }
            $current = (string) $unit['status'];
            $allowed = $maintenance ? [BMG_STATE_IDLE] : [BMG_STATE_MAINTENANCE];
            $next = $maintenance ? BMG_STATE_MAINTENANCE : BMG_STATE_IDLE;
            if (! in_array($current, $allowed, true)) {
                throw StateMachineException::invalidTransition($current, $next, 'bmg');
            }
            $now = $this->utcNow();
            $this->db->table('facilities_bmg_units')->where('id', $unitId)->update([
                'status'     => $next,
                'updated_at' => $now,
            ]);
            $this->audit->enqueue('bmg.unit_maintenance', 'facilities_bmg_units', $unitId, $userId, [
                'next_status' => $next,
            ]);
            return ['id' => $unitId, 'status' => $next];
        });
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
