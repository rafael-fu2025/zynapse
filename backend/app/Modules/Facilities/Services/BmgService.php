<?php

declare(strict_types=1);

namespace Modules\Facilities\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use App\Services\Notify\NotificationOutboxService;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Facilities\DTOs\BmgAlertDto;
use Modules\Facilities\DTOs\BmgBatchDto;
use Modules\Facilities\DTOs\BmgUnitDto;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * BmgService — Facilities state machine.
 *
 * Lifecycle (per directive):
 *   Idle -> Processing -> AwaitingOutput -> Idle  (or -> Cancelled -> Released)
 *
  * Concurrency: `selectForUpdate` on the unit row before any state change.
 * The DB-level UNIQUE index on `active_unit_id` is the final guard.
 */
final class BmgService extends BaseService
{
    public function __construct(
        private readonly BmgPolicy $policy,
        private readonly AuditOutboxService $audit,
        private ?BmgAlertEngine $alertEngine = null,
        private ?NotificationOutboxService $notify = null,
    ) {
        parent::__construct();
        $this->alertEngine ??= new BmgAlertEngine();
        $this->notify ??= new NotificationOutboxService();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUnits(?string $cursor, int $limit, bool $includeArchived = false): array
    {
        $this->policy->check('list');

        $builder = $this->db->table('facilities_bmg_units AS u')
            ->select('u.id, u.code, u.display_name, u.status, u.location_code, u.spec_capacity_kg, u.default_category_id, u.notes, u.created_at, u.updated_at, u.archived_at, c.name AS default_category_name, b.id AS active_batch_id, b.total_input_weight_kg AS active_batch_weight_kg')
            ->where('u.tenant_id', CurrentTenant::id())
            ->join(
                'facilities_bmg_batches AS b',
                "b.unit_id = u.id AND b.archived_at IS NULL AND b.status IN ('" . BMG_STATE_PROCESSING . "', '" . BMG_STATE_AWAITING_OUTPUT . "', '" . BMG_STATE_CURING . "')",
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
     * @param array<int, array{category_id:int, weight_kg:float}> $composition
     */
    public function startBatch(int $unitId, array $inputItems, float $totalInputKg, array $composition = []): BmgBatchDto
    {
        $this->policy->check('start');
        $userId = \App\Auth\CurrentUser::assert();

        if ($totalInputKg <= 0) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'total_input_weight_kg must be > 0.', 'field' => 'total_input_weight_kg'],
            ]);
        }

        // Panel revision: segregated waste tracking. When a composition
        // is supplied (one row per waste category with its weight), the
        // component weights must add up to the declared total — the
        // ratios drive the mix-weighted expected duration.
        $composition = $this->normalizeComposition($composition, $totalInputKg);

        return $this->txn(function () use ($unitId, $inputItems, $totalInputKg, $composition, $userId): BmgBatchDto {
            // Lock the unit row.
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);

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

            // Validate composition categories and resolve their codes so
            // the legacy `input_items` JSON stays populated even when the
            // caller only sends the structured composition.
            $catCodes = [];
            if ($composition !== []) {
                $catIds = array_map(static fn (array $c): int => $c['category_id'], $composition);
                $catRows = $this->db->table('facilities_waste_categories')
                    ->select('id, code')
                    ->where('tenant_id', CurrentTenant::id())
                    ->whereIn('id', $catIds)
                    ->get()->getResultArray();
                foreach ($catRows as $cr) {
                    $catCodes[(int) $cr['id']] = (string) $cr['code'];
                }
                foreach ($catIds as $cid) {
                    if (! isset($catCodes[$cid])) {
                        throw new ApiException('resource.not_found', 404, [
                            ['code' => 'resource.not_found', 'message' => "Waste category #{$cid} not found.", 'field' => 'composition'],
                        ]);
                    }
                }
                if ($inputItems === []) {
                    $inputItems = array_map(static fn (array $c): array => [
                        'sku'    => $catCodes[$c['category_id']],
                        'qty_kg' => $c['weight_kg'],
                    ], $composition);
                }
            }

            // Insert the batch. The generated `active_unit_id` column + UNIQUE
            // index will reject a duplicate if the unit slipped past us.
            // `category_id` prefers the single-component mix, then falls
            // back to the unit's default category.
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $ref = 'BMG-' . date('Ymd') . '-' . bin2hex(random_bytes(4));

            $categoryId = null;
            if (count($composition) === 1) {
                $categoryId = $composition[0]['category_id'];
            } elseif ($unit['default_category_id'] !== null) {
                $categoryId = (int) $unit['default_category_id'];
            }

            $this->db->table('facilities_bmg_batches')->insert([
                'unit_id'               => $unitId,
                'tenant_id'             => CurrentTenant::id(),
                'category_id'           => $categoryId,
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

            // Structured per-category mix rows (weights + ratios).
            foreach ($composition as $c) {
                $this->db->table('facilities_bmg_composition')->insert([
                    'batch_id'    => $batchId,
                    'tenant_id'   => CurrentTenant::id(),
                    'category_id' => $c['category_id'],
                    'weight_kg'   => $c['weight_kg'],
                    'created_at'  => $now,
                ]);
            }

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
            ->where('tenant_id', CurrentTenant::id())
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
        // Ownership check first — load the row outside the txn (no lock yet).
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('record_output', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $outputKg, $outputItems, $userId): BmgBatchDto {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);

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

    /**
     * Industry lifecycle: `AwaitingOutput → Curing`. Curing is a
     * long-tail phase (1–3 months) with reduced monitoring cadence.
     * The batch and unit both transition; the `active_unit_id`
     * generated column remains populated (curing is "active" for
     * the one-active-batch-per-unit invariant) so the unit cannot
     * start a fresh batch until the cure finishes.
     *
     * Operator may supply an `accumulated_in_process_kg` snapshot of
     * the residue mass left on the unit at the transition point, for
     * trace-back across long cures. Defaulted to 0.00.
     */
    public function moveToCuring(int $batchId, ?float $accumulatedKg = null): BmgBatchDto
    {
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('move_to_curing', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $accumulatedKg, $userId): BmgBatchDto {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }
            if ($batch['status'] !== BMG_STATE_AWAITING_OUTPUT) {
                throw StateMachineException::invalidTransition($batch['status'], BMG_STATE_CURING, 'bmg');
            }

            $now = $this->utcNow();
            $aip = $accumulatedKg !== null ? round($accumulatedKg, 2) : 0.00;

            $this->db->table('facilities_bmg_batches')
                ->where('id', $batchId)
                ->update([
                    'status'                    => BMG_STATE_CURING,
                    'accumulated_in_process_kg' => $aip,
                    'updated_at'                => $now,
                ]);

            $this->db->table('facilities_bmg_units')
                ->where('id', (int) $batch['unit_id'])
                ->update(['status' => BMG_STATE_CURING, 'updated_at' => $now]);

            $this->audit->enqueue(
                'bmg.batch_curing',
                'facilities_bmg_batches',
                $batchId,
                $userId,
                [
                    'previous_status'           => BMG_STATE_AWAITING_OUTPUT,
                    'next_status'               => BMG_STATE_CURING,
                    'accumulated_in_process_kg' => $aip,
                ],
            );

            $fresh = $this->db->table('facilities_bmg_batches')->where('id', $batchId)->get()->getRowArray();
            return BmgBatchDto::fromRow($fresh);
        });
    }

    public function finishBatch(int $batchId): BmgBatchDto
    {
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('finish', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $userId): BmgBatchDto {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);

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
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('cancel', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $reasonCode, $userId): BmgBatchDto {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);

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
     * Register a new BMG unit. The `code` is a unique SLUG — lowercase
     * `a-z0-9` groups separated by single hyphens (`drum-01`) — a
     * human-readable, URL-safe identifier distinct from the numeric id
     * (panel revision). Input is normalized before validation so users
     * may type `DRUM 01` and get `drum-01`. Same-transaction audit row.
     *
     * @param array{code:string, display_name:string, location_code?:?string, spec_capacity_kg?:?float, default_category_id?:?int, notes?:?string} $input
     */
    public function createUnit(array $input): BmgUnitDto
    {
        $this->policy->check('manage_units');
        $userId = \App\Auth\CurrentUser::assert();

        $code = $this->assertSlug((string) $input['code'], 'code');

        $defaultCategoryId = $this->resolveCategoryId($input['default_category_id'] ?? null);

        return $this->txn(function () use ($input, $code, $defaultCategoryId, $userId): BmgUnitDto {
            $dup = $this->db->table('facilities_bmg_units')->where('code', $code)->where('tenant_id', CurrentTenant::id())->get()->getRowArray();
            if ($dup !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "A unit with code '{$code}' already exists.", 'field' => 'code'],
                ]);
            }
            $now = $this->utcNow();
            $this->db->table('facilities_bmg_units')->insert([
                'code'                => $code,
                'tenant_id'           => CurrentTenant::id(),
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
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
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
            ->where('tenant_id', CurrentTenant::id())
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
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found or already archived."],
                ]);
            }

            $active = $this->db->table('facilities_bmg_batches')
                ->where('unit_id', $unitId)
                ->where('archived_at', null)
                ->where('tenant_id', CurrentTenant::id())
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
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'tenant_id' => CurrentTenant::id()]);
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
            ->where('b.tenant_id', CurrentTenant::id())
            ->where('u.tenant_id', CurrentTenant::id())
            ->where('c.tenant_id', CurrentTenant::id())
            ->whereIn('b.status', [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT])
            ->orderBy('b.started_at', 'ASC')
            ->get()->getResultArray();

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $a     = new \App\Services\Analytics\BmgAnalytics();

        // Panel revision: the ETA is weighted by each drum's specific
        // waste mix — per-category expected days (historical average,
        // falling back to the manual reference) × weight ratio.
        $batchIds     = array_map(static fn (array $r): int => (int) $r['batch_id'], $rows);
        $compositions = $this->batchCompositions($batchIds);
        $catIds       = [];
        foreach ($compositions as $comps) {
            foreach ($comps as $c) {
                $catIds[] = $c['category_id'];
            }
        }
        foreach ($rows as $r) {
            if ($r['category_id'] !== null) {
                $catIds[] = (int) $r['category_id'];
            }
        }
        $expectedByCat = $this->expectedDaysByCategory($catIds);

        return array_map(function (array $r) use ($a, $today, $compositions, $expectedByCat): array {
            $startDate = substr((string) $r['started_at'], 0, 10);
            $refDays   = $r['reference_duration_days'] !== null ? (int) $r['reference_duration_days'] : 0;

            $comps    = $compositions[(int) $r['batch_id']] ?? [];
            $expDays  = $this->weightedExpectedDays($comps, $expectedByCat);
            if ($expDays === null && $r['category_id'] !== null) {
                $expDays = $expectedByCat[(int) $r['category_id']]['expected_days'] ?? null;
            }
            $effDays = $expDays ?? ($refDays > 0 ? $refDays : 0);

            // expectedCompletionDate() falls back to 45 days when neither
            // history nor a reference exists, so the progress bar always moves.
            $expected = $a->expectedCompletionDate($startDate, $effDays);

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
                'expected_days'            => $expDays ?? ($refDays > 0 ? $refDays : null),
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
        // Tier 3.2 — `logs_read` is owned, so load the batch row to
        // satisfy `canOnRecord`.
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('logs_read', $batch);

        // 404 on missing/archived batch (reuses the read helper).
        $this->peekInputKg($batchId);

        $rows = $this->db->table('facilities_bmg_process_logs')
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('log_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'                  => (int) $r['id'],
            'batch_id'            => (int) $r['batch_id'],
            'log_date'            => (string) $r['log_date'],
            // Tier 2.2: event_type tells WHAT was done (observation,
            // turning, aeration, moisture_adjustment, other).
            'event_type'          => isset($r['event_type']) && $r['event_type'] !== null ? (string) $r['event_type'] : 'observation',
            'observation_note'    => $r['observation_note'] !== null ? (string) $r['observation_note'] : null,
            'temperature_celsius' => $r['temperature_celsius'] !== null ? (float) $r['temperature_celsius'] : null,
            'moisture_level'      => $r['moisture_level'] !== null ? (string) $r['moisture_level'] : null,
            'oxygen_pct'          => isset($r['oxygen_pct']) && $r['oxygen_pct'] !== null ? (float) $r['oxygen_pct'] : null,
            'device_id'           => isset($r['device_id']) && $r['device_id'] !== null ? (string) $r['device_id'] : null,
            'calibration_status'  => isset($r['calibration_status']) && $r['calibration_status'] !== null ? (string) $r['calibration_status'] : null,
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
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('logs_record', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $input, $userId): array {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
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
                'tenant_id'           => CurrentTenant::id(),
                'log_date'            => (string) ($input['log_date'] ?? substr($now, 0, 10)),
                // Tier 2.2/audit: record WHAT was done (turning, aeration,
                // moisture adjustment, observation) so the aeration action
                // is tracked, not just the sensor reading.
                'event_type'          => isset($input['event_type']) && $input['event_type'] !== '' ? (string) $input['event_type'] : 'observation',
                'observation_note'    => isset($input['observation_note']) && $input['observation_note'] !== '' ? (string) $input['observation_note'] : null,
                'temperature_celsius' => isset($input['temperature_celsius']) && $input['temperature_celsius'] !== '' ? (float) $input['temperature_celsius'] : null,
                'moisture_level'      => isset($input['moisture_level']) && $input['moisture_level'] !== '' ? (string) $input['moisture_level'] : null,
                'oxygen_pct'          => isset($input['oxygen_pct']) && $input['oxygen_pct'] !== '' ? (float) $input['oxygen_pct'] : null,
                'device_id'           => isset($input['device_id']) && $input['device_id'] !== '' ? (string) $input['device_id'] : null,
                'calibration_status'  => isset($input['calibration_status']) && $input['calibration_status'] !== '' ? (string) $input['calibration_status'] : null,
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

            // -----------------------------------------------------------------
            // Alert engine: SPC evaluation in the same transaction so a
            // rollback drops both. We compute staleness against the
            // PREVIOUS log (the one we just superseded); the engine
            // uses the freshly-inserted row as `lastLog`.
            // -----------------------------------------------------------------
            $previousLog = $this->db->table('facilities_bmg_process_logs')
                ->select('log_date')
                ->where('batch_id', $batchId)
                ->where('id !=', $id)
                ->orderBy('log_date', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();

            $row = $this->db->table('facilities_bmg_process_logs')->where('id', $id)->get()->getRowArray();

            $daysSince = $this->alertEngine->daysSinceLastLog($previousLog ?: null);
            $alerts = $this->alertEngine->evaluate(
                [
                    'id'          => $batchId,
                    'status'      => (string) $batch['status'],
                    'started_at'  => (string) $batch['started_at'],
                    'archived_at' => null,
                ],
                $row,
                $daysSince,
            );

            $persistedAlerts = [];
            foreach ($alerts as $alert) {
                $this->db->table('facilities_bmg_alerts')->insert([
                    'batch_id'      => $batchId,
                    'tenant_id'     => CurrentTenant::id(),
                    'code'          => (string) $alert['code'],
                    'severity'      => (string) $alert['severity'],
                    'message'       => (string) $alert['message'],
                    'triggered_at'  => $now,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                $alertId = (int) $this->db->insertID();
                $this->audit->enqueue(
                    'bmg.alert_triggered',
                    'facilities_bmg_alerts',
                    $alertId,
                    $userId,
                    [
                        'resource_code' => (string) $batch['reference_code'],
                        'alert_code'    => (string) $alert['code'],
                        'severity'      => (string) $alert['severity'],
                    ],
                );

                // Tier 3: surface the alert globally — every user who can
                // read BMG logs gets an in-app notification (dashboard
                // "at-risk" widget + bell). Runs in the same txn (outbox).
                $this->notify->enqueueToPermissions(
                    ['facilities.bmg.logs.read'],
                    'bmg.alert_triggered',
                    [
                        'resource_code' => (string) $batch['reference_code'],
                        'urgency'       => (string) $alert['severity'],
                        'source_module' => 'facilities',
                    ],
                );
                $persistedAlerts[] = BmgAlertDto::fromRow([
                    'id'                      => $alertId,
                    'batch_id'                => $batchId,
                    'code'                    => (string) $alert['code'],
                    'severity'                => (string) $alert['severity'],
                    'message'                 => (string) $alert['message'],
                    'triggered_at'            => $now,
                    'acknowledged_at'         => null,
                    'acknowledged_by_user_id' => null,
                ])->toArray();
            }

            return [
                'id'                  => (int) $row['id'],
                'batch_id'            => (int) $row['batch_id'],
                'log_date'            => (string) $row['log_date'],
                'event_type'          => isset($row['event_type']) && $row['event_type'] !== null ? (string) $row['event_type'] : 'observation',
                'observation_note'    => $row['observation_note'] !== null ? (string) $row['observation_note'] : null,
                'temperature_celsius' => $row['temperature_celsius'] !== null ? (float) $row['temperature_celsius'] : null,
                'moisture_level'      => $row['moisture_level'] !== null ? (string) $row['moisture_level'] : null,
                'oxygen_pct'          => $row['oxygen_pct'] !== null ? (float) $row['oxygen_pct'] : null,
                'device_id'           => $row['device_id'] !== null ? (string) $row['device_id'] : null,
                'calibration_status'  => $row['calibration_status'] !== null ? (string) $row['calibration_status'] : null,
                'recorded_by_user_id' => (int) $row['recorded_by_user_id'],
                'created_at'          => (string) $row['created_at'],
                'alerts'              => $persistedAlerts,
            ];
        });
    }

    // -------------------------------------------------------- losses

    /**
     * Industry-standard mass-balance tracking. Records a single
     * categorised loss against an ACTIVE batch and recomputes the
     * denormalised `total_loss_kg` on the batch row in the same
     * transaction. Cancellable / finished batches reject losses —
     * post-hoc mass reconciliation runs through `recordOutput` /
     * `finishBatch`, not through the losses log.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addBatchLoss(int $batchId, array $input): array
    {
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('losses_record', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $input, $userId): array {
            $batch = $this->selectForUpdate('facilities_bmg_batches', [
                'id'          => $batchId,
                'tenant_id'   => CurrentTenant::id(),
                'archived_at' => null,
            ]);
            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }
            if (! in_array($batch['status'], [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT], true)) {
                throw new ApiException('statemachine.bmg.loss_terminal_batch', 409, [
                    ['code' => 'statemachine.bmg.loss_terminal_batch', 'message' => 'Losses can only be recorded while a batch is active.'],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('facilities_bmg_losses')->insert([
                'batch_id'            => $batchId,
                'tenant_id'           => CurrentTenant::id(),
                'category_code'       => (string) $input['category_code'],
                'weight_kg'           => (float) $input['weight_kg'],
                'note'                => isset($input['note']) && $input['note'] !== '' ? (string) $input['note'] : null,
                'recorded_by_user_id' => $userId,
                'recorded_at'         => $now,
                'created_at'          => $now,
            ]);
            $id = (int) $this->db->insertID();

            // Recompute the denormalised total from the row-level truth.
            // SUM() returns NULL when no rows; coalesce to 0 so the
            // CHECK (>= 0) passes.
            $sum = $this->db->table('facilities_bmg_losses')
                ->select('COALESCE(SUM(weight_kg), 0) AS s', false)
                ->where('batch_id', $batchId)
                ->where('tenant_id', CurrentTenant::id())
                ->get()->getRowArray();
            $total = $sum !== null ? (float) $sum['s'] : 0.0;

            $this->db->table('facilities_bmg_batches')
                ->where('id', $batchId)
                ->where('tenant_id', CurrentTenant::id())
                ->update([
                    'total_loss_kg' => $total,
                    'updated_at'    => $now,
                ]);

            $this->audit->enqueue('bmg.loss_recorded', 'facilities_bmg_losses', $id, $userId, [
                'resource_code' => (string) $batch['reference_code'],
                'category_code' => (string) $input['category_code'],
                'weight_kg'     => (float) $input['weight_kg'],
                'total_loss_kg' => $total,
            ]);

            return [
                'id'              => $id,
                'batch_id'        => $batchId,
                'category_code'   => (string) $input['category_code'],
                'weight_kg'       => (float) $input['weight_kg'],
                'total_loss_kg'   => $total,
            ];
        });
    }

    /**
     * Read-only feed of losses for a batch (oldest first so operators
     * see the timeline). Includes the running total so the panel
     * doesn't need to re-aggregate client-side.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBatchLosses(int $batchId): array
    {
        // Tier 3.2 — `logs_read` is owned; load the batch row (which
        // is also our tenant guard) and pass it to the policy.
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('logs_read', $batch);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
            ]);
        }

        $rows = $this->db->table('facilities_bmg_losses')
            ->select('id, batch_id, category_code, weight_kg, note, recorded_by_user_id, recorded_at, created_at')
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('recorded_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $running = 0.0;
        return array_map(static function (array $r) use (&$running): array {
            $running += (float) $r['weight_kg'];
            return [
                'id'                  => (int)    $r['id'],
                'batch_id'            => (int)    $r['batch_id'],
                'category_code'       => (string) $r['category_code'],
                'weight_kg'           => (float)  $r['weight_kg'],
                'note'                => $r['note'] !== null ? (string) $r['note'] : null,
                'recorded_by_user_id' => (int)    $r['recorded_by_user_id'],
                'recorded_at'         => (string) $r['recorded_at'],
                'running_total_kg'    => round($running, 2),
            ];
        }, $rows);
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
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('name', 'ASC');
        if ($activeOnly) {
            $builder->where('is_active', 1);
        }
        $rows  = $builder->get()->getResultArray();
        // Panel revision: expected days per category come from validated
        // historical trials (finished batches), with the manual reference
        // duration only as the fallback while no history exists.
        $stats = $this->categoryDurationStats(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        return array_map(static function (array $r) use ($stats): array {
            $id   = (int) $r['id'];
            $hist = $stats[$id] ?? null;
            $ref  = $r['reference_duration_days'] !== null ? (int) $r['reference_duration_days'] : null;
            return [
                'id'                      => $id,
                'code'                    => (string) $r['code'],
                'name'                    => (string) $r['name'],
                'description'             => $r['description'] !== null ? (string) $r['description'] : null,
                'expected_yield_pct'      => $r['expected_yield_pct'] !== null ? (float) $r['expected_yield_pct'] : null,
                'reference_duration_days' => $ref,
                'historical_avg_days'     => $hist !== null ? round($hist['avg_days'], 1) : null,
                'sample_count'            => $hist !== null ? $hist['samples'] : 0,
                'expected_days'           => $hist !== null ? (int) round($hist['avg_days']) : $ref,
                'is_active'               => (bool) $r['is_active'],
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createWasteCategory(array $input): array
    {
        $this->policy->check('categories_manage');
        $userId = \App\Auth\CurrentUser::assert();

        // Waste category codes follow the same slug contract as drum
        // codes (panel revision): lowercase, hyphen-separated.
        $input['code'] = $this->assertSlug((string) $input['code'], 'code');

        return $this->txn(function () use ($input, $userId): array {
            $dup = $this->db->table('facilities_waste_categories')->where('code', (string) $input['code'])->where('tenant_id', CurrentTenant::id())->get()->getRowArray();
            if ($dup !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => 'A waste category with this code already exists.', 'field' => 'code'],
                ]);
            }
            $now = $this->utcNow();
            $this->db->table('facilities_waste_categories')->insert([
                'code'                    => (string) $input['code'],
                'tenant_id'               => CurrentTenant::id(),
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
     * Full decorated DTO for a single waste category — the SAME shape
     * `listWasteCategories` returns (including the derived historical
     * trial stats `historical_avg_days` / `sample_count` / `expected_days`).
     *
     * Every mutating endpoint (update/archive/unarchive) returns this so
     * the frontend `wasteCategorySchema` parse succeeds. Previously these
     * returned a PARTIAL row, which made the edit/archive form surface a
     * spurious `Required` ZodError (toast "Required", form never closed)
     * even though the write itself had succeeded.
     *
     * @return array<string, mixed>
     */
    private function wasteCategoryDto(int $categoryId): array
    {
        $row = $this->db->table('facilities_waste_categories')
            ->where('id', $categoryId)
            ->where('tenant_id', CurrentTenant::id())
            ->get()
            ->getRowArray();

        $stats = $this->categoryDurationStats([$categoryId]);
        $hist  = $stats[$categoryId] ?? null;
        $ref   = $row !== null && $row['reference_duration_days'] !== null ? (int) $row['reference_duration_days'] : null;

        return [
            'id'                      => (int) $row['id'],
            'code'                    => (string) $row['code'],
            'name'                    => (string) $row['name'],
            'description'             => $row['description'] !== null ? (string) $row['description'] : null,
            'expected_yield_pct'      => $row['expected_yield_pct'] !== null ? (float) $row['expected_yield_pct'] : null,
            'reference_duration_days' => $ref,
            'historical_avg_days'     => $hist !== null ? round($hist['avg_days'], 1) : null,
            'sample_count'            => $hist !== null ? $hist['samples'] : 0,
            'expected_days'           => $hist !== null ? (int) round($hist['avg_days']) : $ref,
            'is_active'               => (bool) $row['is_active'],
        ];
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
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId, 'tenant_id' => CurrentTenant::id()]);
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

            return $this->wasteCategoryDto($categoryId);
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
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId, 'tenant_id' => CurrentTenant::id()]);
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

            return $this->wasteCategoryDto($categoryId);
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
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId, 'tenant_id' => CurrentTenant::id()]);
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

            return $this->wasteCategoryDto($categoryId);
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
            $cat = $this->selectForUpdate('facilities_waste_categories', ['id' => $categoryId, 'tenant_id' => CurrentTenant::id()]);
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
            $now = $this->utcNow();

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

    /**
     * Deterministic yield/ETA analytics for a batch (Phase P4).
     *
     * @return array<string, mixed>
     */
    public function batchAnalytics(int $batchId): array
    {
        // Tier 3.2 — `analytics` is owned, so the batch row must be
        // loaded with `started_by_user_id` and tennat/archived filtered
        // before the policy check fires.
        $batch = $this->db->table('facilities_bmg_batches')
            ->select('id, reference_code, category_id, status, total_input_weight_kg, output_weight_kg, total_loss_kg, accumulated_in_process_kg, started_at, finished_at, started_by_user_id, tenant_id, archived_at')
            ->where('id', $batchId)->where('archived_at', null)->where('tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('analytics', $batch);

        $structuredIn  = (float) ($this->db->table('facilities_bmg_inputs')->selectSum('weight_kg', 't')->where('batch_id', $batchId)->get()->getRowArray()['t'] ?? 0);
        $structuredOut = (float) ($this->db->table('facilities_bmg_outputs')->selectSum('output_weight_kg', 't')->where('batch_id', $batchId)->get()->getRowArray()['t'] ?? 0);

        // Per-category loss breakdown (drives the loss pie/bar chart).
        // Group-level SUM is safe here (no PII, no tenant mix-up since
        // we already filtered by batch above and FK guarantees tenancy).
        $lossRows = $this->db->table('facilities_bmg_losses')
            ->select('category_code, SUM(weight_kg) AS w')
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->groupBy('category_code')
            ->orderBy('w', 'DESC')
            ->get()->getResultArray();
        $losses = array_map(static fn (array $r): array => [
            'category_code' => (string) $r['category_code'],
            'weight_kg'     => round((float) $r['w'], 2),
        ], $lossRows);

        $totalLossKg = $this->db->table('facilities_bmg_losses')
            ->select('COALESCE(SUM(weight_kg), 0) AS s', false)
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        $totalLossKg = $totalLossKg !== null ? (float) $totalLossKg['s'] : 0.0;

        // Prefer the row-level recomputed total over the denormalised
        // column — if they drift, the row source wins (it's the
        // truth) and the column is corrected in the same response.
        $denormLoss = isset($batch['total_loss_kg']) && $batch['total_loss_kg'] !== null
            ? (float) $batch['total_loss_kg']
            : 0.0;

        $aip = isset($batch['accumulated_in_process_kg']) && $batch['accumulated_in_process_kg'] !== null
            ? (float) $batch['accumulated_in_process_kg']
            : null;

        $inputKg  = $structuredIn > 0 ? $structuredIn : (float) $batch['total_input_weight_kg'];
        $outputKg = $structuredOut > 0 ? $structuredOut : (float) ($batch['output_weight_kg'] ?? 0);

        $category = null;
        if ($batch['category_id'] !== null) {
            $category = $this->db->table('facilities_waste_categories')
                ->select('name, expected_yield_pct, reference_duration_days')
                ->where('id', (int) $batch['category_id'])->get()->getRowArray();
        }

        // Panel revision: mix-weighted expected duration + per-component
        // breakdown (weight ratios) for the drum detail screen.
        $comps         = $this->batchCompositions([$batchId])[$batchId] ?? [];
        $catIds        = array_map(static fn (array $c): int => $c['category_id'], $comps);
        if ($batch['category_id'] !== null) {
            $catIds[] = (int) $batch['category_id'];
        }
        $expectedByCat = $this->expectedDaysByCategory($catIds);
        $mixDays       = $this->weightedExpectedDays($comps, $expectedByCat);

        $totalCompKg = 0.0;
        foreach ($comps as $c) {
            $totalCompKg += $c['weight_kg'];
        }
        $composition = array_map(static function (array $c) use ($expectedByCat, $totalCompKg): array {
            $meta = $expectedByCat[$c['category_id']] ?? null;
            return [
                'category_id'   => $c['category_id'],
                'category_name' => $c['category_name'],
                'weight_kg'     => round($c['weight_kg'], 2),
                'ratio_pct'     => $totalCompKg > 0 ? round(($c['weight_kg'] / $totalCompKg) * 100, 1) : null,
                'expected_days' => $meta['expected_days'] ?? null,
                'sample_count'  => $meta['sample_count'] ?? 0,
            ];
        }, $comps);

        $a         = new \App\Services\Analytics\BmgAnalytics();
        $yield     = $a->computeYield($inputKg, $outputKg);
        $startDate = substr((string) $batch['started_at'], 0, 10);
        $refDays   = $category !== null && $category['reference_duration_days'] !== null ? (int) $category['reference_duration_days'] : 0;

        $expDays = $mixDays;
        if ($expDays === null && $batch['category_id'] !== null) {
            $expDays = $expectedByCat[(int) $batch['category_id']]['expected_days'] ?? null;
        }
        $effDays  = $expDays ?? $refDays;
        $expected = $a->expectedCompletionDate($startDate, $effDays);
        $today    = $this->utcNow();

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
            'expected_days'           => $expDays ?? ($refDays > 0 ? $refDays : null),
            'composition'             => $composition,
            'expected_completion_date'=> $effDays > 0 ? $expected : null,
            'days_until_expected'     => $effDays > 0 ? $a->daysUntilExpected($expected, $today) : null,
            'progress_pct'            => $effDays > 0 ? $a->progressPercent($startDate, $expected, $today) : null,
            // Mass-balance breakdown.
            'total_loss_kg'           => round($totalLossKg, 2),
            'losses_denormalised_kg'  => round($denormLoss, 2),
            'accumulated_in_process_kg' => $aip !== null ? round($aip, 2) : null,
            'losses'                  => $losses,
        ];
    }

    /** Toggle a unit between Idle and Maintenance (only when not busy). */
    public function setUnitMaintenance(int $unitId, bool $maintenance): array
    {
        // Dedicated `maintenance` action — unit-scoped, not batch-scoped,
        // so it stays outside the OWNED_BATCH_ACTIONS set in BmgPolicy.
        $this->policy->check('maintenance'); // facilities.bmg.transition
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($unitId, $maintenance, $userId): array {
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found."],
                ]);
            }
            $current = (string) $unit['status'];
            // Curing is a long-tail phase (1-3 months) and is intentionally
            // "active" for the one-active-batch-per-unit invariant (see
            // moveToCuring). Putting a unit into maintenance while a batch
            // is curing would orphan the cure and break the generated
            // `active_unit_id` uniqueness. Reject both directions.
            $blocked = [BMG_STATE_CURING];
            $allowed = $maintenance ? [BMG_STATE_IDLE] : [BMG_STATE_MAINTENANCE];
            $next = $maintenance ? BMG_STATE_MAINTENANCE : BMG_STATE_IDLE;
            if (in_array($current, $blocked, true) || ! in_array($current, $allowed, true)) {
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

    // -------------------------------------------- panel-revision helpers

    /**
     * Validate + normalize a batch composition payload: positive weights,
     * no duplicate categories, and the component sum must equal the
     * declared total (±0.01 kg tolerance).
     *
     * @param array<int, mixed> $composition
     * @return array<int, array{category_id:int, weight_kg:float}>
     */
    private function normalizeComposition(array $composition, float $totalInputKg): array
    {
        if ($composition === []) {
            return [];
        }

        $out  = [];
        $sum  = 0.0;
        $seen = [];
        foreach ($composition as $c) {
            $cid = isset($c['category_id']) ? (int) $c['category_id'] : 0;
            $w   = isset($c['weight_kg']) ? (float) $c['weight_kg'] : 0.0;
            if ($cid <= 0) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'Each composition row needs a category_id.', 'field' => 'composition'],
                ]);
            }
            if ($w <= 0) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'Each composition row needs a weight_kg > 0.', 'field' => 'composition'],
                ]);
            }
            if (isset($seen[$cid])) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'Duplicate waste category in composition.', 'field' => 'composition'],
                ]);
            }
            $seen[$cid] = true;
            $sum += $w;
            $out[] = ['category_id' => $cid, 'weight_kg' => round($w, 2)];
        }

        if (abs($sum - $totalInputKg) > 0.01) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => sprintf('Composition weights (%.2f kg) must add up to total_input_weight_kg (%.2f kg).', $sum, $totalInputKg), 'field' => 'composition'],
            ]);
        }

        return $out;
    }

    /**
     * Normalize + assert the slug contract for `code` fields: lowercase
     * `a-z0-9` groups separated by single hyphens. Whitespace and
     * uppercase input are normalized rather than rejected. Delegates the
     * pure normalization/validation to {@see BmgAnalytics} so the rule
     * is unit-tested without booting the DB.
     */
    private function assertSlug(string $raw, string $field): string
    {
        $a    = new \App\Services\Analytics\BmgAnalytics();
        $slug = $a->normalizeSlug($raw);
        if (! $a->isValidSlug($slug)) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'Must be a slug: lowercase letters/digits separated by single hyphens (e.g. drum-01).', 'field' => $field],
            ]);
        }
        return $slug;
    }

    /**
     * Historical duration per category, averaged over FINISHED batches
     * (multi-trial validated data — panel revision). A batch counts for
     * a category when the category is in its structured composition;
     * legacy batches without composition rows count via their single
     * `category_id` tag.
     *
     * @param array<int, int> $categoryIds
     * @return array<int, array{avg_days: float, samples: int}>
     */
    private function categoryDurationStats(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);

        $rows = $this->db->query(
            'SELECT t.cat_id, AVG(t.days) AS avg_days, COUNT(*) AS samples FROM ('
            . ' SELECT c.category_id AS cat_id, DATEDIFF(b.finished_at, b.started_at) AS days'
            . ' FROM facilities_bmg_composition c'
            . ' JOIN facilities_bmg_batches b ON b.id = c.batch_id'
            . " WHERE b.finished_at IS NOT NULL AND b.archived_at IS NULL AND c.category_id IN ({$in})"
            . ' UNION ALL'
            . ' SELECT b.category_id, DATEDIFF(b.finished_at, b.started_at)'
            . ' FROM facilities_bmg_batches b'
            . ' LEFT JOIN facilities_bmg_composition c2 ON c2.batch_id = b.id'
            . ' WHERE c2.id IS NULL AND b.category_id IS NOT NULL AND b.finished_at IS NOT NULL'
            . " AND b.archived_at IS NULL AND b.category_id IN ({$in})"
            . ') t GROUP BY t.cat_id',
        )->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['cat_id']] = [
                'avg_days' => (float) $r['avg_days'],
                'samples'  => (int) $r['samples'],
            ];
        }
        return $out;
    }

    /**
     * Per-category expected days: historical average (rounded) when at
     * least one finished trial exists, else the manual reference days.
     *
     * @param array<int, int> $categoryIds
     * @return array<int, array{expected_days: ?int, sample_count: int}>
     */
    private function expectedDaysByCategory(array $categoryIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($ids === []) {
            return [];
        }

        $stats = $this->categoryDurationStats($ids);
        $refs  = $this->db->table('facilities_waste_categories')
            ->select('id, reference_duration_days')
            ->whereIn('id', $ids)
            ->get()->getResultArray();

        $out = [];
        foreach ($refs as $r) {
            $id   = (int) $r['id'];
            $hist = $stats[$id] ?? null;
            $ref  = $r['reference_duration_days'] !== null ? (int) $r['reference_duration_days'] : null;
            $out[$id] = [
                'expected_days' => $hist !== null ? (int) round($hist['avg_days']) : $ref,
                'sample_count'  => $hist !== null ? $hist['samples'] : 0,
            ];
        }
        return $out;
    }

    /**
     * Structured composition rows (with category names) for a set of
     * batches, keyed by batch id.
     *
     * @param array<int, int> $batchIds
     * @return array<int, array<int, array{category_id:int, category_name:string, weight_kg:float}>>
     */
    private function batchCompositions(array $batchIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $batchIds)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('facilities_bmg_composition AS bc')
            ->select('bc.batch_id, bc.category_id, bc.weight_kg, c.name AS category_name')
            ->join('facilities_waste_categories AS c', 'c.id = bc.category_id')
            ->whereIn('bc.batch_id', $ids)
            ->orderBy('bc.weight_kg', 'DESC')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['batch_id']][] = [
                'category_id'   => (int) $r['category_id'],
                'category_name' => (string) $r['category_name'],
                'weight_kg'     => (float) $r['weight_kg'],
            ];
        }
        return $out;
    }

    /**
     * Weight-ratio-weighted expected duration for a drum's specific mix.
     * Delegates the pure math to {@see BmgAnalytics::weightedExpectedDays}
     * (unit-tested); returns null when no component carries data.
     *
     * @param array<int, array{category_id:int, weight_kg:float}> $components
     * @param array<int, array{expected_days: ?int, sample_count: int}> $expectedByCat
     */
    private function weightedExpectedDays(array $components, array $expectedByCat): ?int
    {
        return (new \App\Services\Analytics\BmgAnalytics())->weightedExpectedDays($components, $expectedByCat);
    }

    // -------------------------------------------------------- alerts

    /**
     * List alerts for a single batch. Read-only; ordered most-recent
     * first, unacknowledged alerts come before acknowledged ones so
     * the UI can render a banner without re-sorting.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAlerts(int $batchId): array
    {
        // Tier 3.2 — `alerts_read` is owned; the tenant-guarded batch
        // lookup doubles as the ownership-row source.
        $batch = $this->db->table('facilities_bmg_batches')
            ->select('id, started_by_user_id, tenant_id, archived_at')
            ->where('id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->get()
            ->getRowArray();
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('alerts_read', $batch);

        $rows = $this->db->table('facilities_bmg_alerts')
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('acknowledged_at', 'ASC', false)
            ->orderBy('triggered_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $r) => BmgAlertDto::fromRow($r)->toArray(), $rows);
    }

    /**
     * Acknowledge an alert. Records the user and timestamp; subsequent
     * UI fetches will rank the alert below unacknowledged ones.
     */
    public function acknowledgeAlert(int $alertId): array
    {
        // Tier 3.2 — `alerts_ack` is owned. Load the alert first to
        // discover its batch_id, then load the batch row for the
        // ownership check. Both reads are tenant-scoped.
        $alert = $this->db->table('facilities_bmg_alerts')
            ->select('id, batch_id, tenant_id')
            ->where('id', $alertId)
            ->where('tenant_id', CurrentTenant::id())
            ->get()->getRowArray();
        if ($alert === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Alert #{$alertId} not found."],
            ]);
        }
        $batch = $this->policy->loadBatchForOwnership((int) $alert['batch_id']);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$alert['batch_id']} not found."],
            ]);
        }
        $this->policy->check('alerts_ack', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($alertId, $userId): array {
            $row = $this->selectForUpdate('facilities_bmg_alerts', [
                'id'        => $alertId,
                'tenant_id' => CurrentTenant::id(),
            ]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Alert #{$alertId} not found."],
                ]);
            }
            if ($row['acknowledged_at'] !== null) {
                // Idempotent — re-acking is allowed and returns the
                // current row, but we don't write a second audit event.
                return BmgAlertDto::fromRow($row)->toArray();
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('facilities_bmg_alerts')
                ->where('id', $alertId)
                ->where('tenant_id', CurrentTenant::id())
                ->update([
                    'acknowledged_at'         => $now,
                    'acknowledged_by_user_id' => $userId,
                    'updated_at'              => $now,
                ]);

            $this->audit->enqueue(
                'bmg.alert_acknowledged',
                'facilities_bmg_alerts',
                $alertId,
                $userId,
                [
                    'batch_id' => (int) $row['batch_id'],
                    'code'     => (string) $row['code'],
                ],
            );

            $row['acknowledged_at'] = $now;
            $row['acknowledged_by_user_id'] = $userId;
            return BmgAlertDto::fromRow($row)->toArray();
        });
    }

    // -------------------------------------------------- open alerts

    /**
     * Global open-alert feed — every unacknowledged alert across ALL
     * batches (dashboard "at-risk" widget + facilities banner). Joined
     * with the batch + unit so staff can act without per-batch hops.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOpenAlerts(): array
    {
        $this->policy->check('list');

        $rows = $this->db->table('facilities_bmg_alerts AS a')
            ->select(
                'a.id AS alert_id, a.code, a.severity, a.message, a.triggered_at, a.acknowledged_at,'
                . ' b.id AS batch_id, b.reference_code, b.status AS batch_status,'
                . ' u.id AS unit_id, u.code AS unit_code, u.display_name AS unit_name'
            )
            ->join('facilities_bmg_batches AS b', 'b.id = a.batch_id')
            ->join('facilities_bmg_units AS u', 'u.id = b.unit_id', 'left')
            ->where('a.tenant_id', CurrentTenant::id())
            ->where('a.acknowledged_at', null)
            ->whereIn('b.status', [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT, BMG_STATE_CURING])
            ->orderBy('a.triggered_at', 'DESC')
            ->orderBy('a.id', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'alert_id'       => (int) $r['alert_id'],
            'code'           => (string) $r['code'],
            'severity'       => (string) $r['severity'],
            'message'        => (string) $r['message'],
            'triggered_at'   => (string) $r['triggered_at'],
            'acknowledged_at'=> $r['acknowledged_at'] !== null ? (string) $r['acknowledged_at'] : null,
            'batch_id'       => (int) $r['batch_id'],
            'reference_code' => (string) $r['reference_code'],
            'batch_status'   => (string) $r['batch_status'],
            'unit_id'        => $r['unit_id'] !== null ? (int) $r['unit_id'] : null,
            'unit_code'      => $r['unit_code'] !== null ? (string) $r['unit_code'] : null,
            'unit_name'      => $r['unit_name'] !== null ? (string) $r['unit_name'] : null,
        ], $rows);
    }

    // ------------------------------------------------- batch history

    /**
     * Batch history listing — terminal + historical batches across all
     * units (or one unit). Keyset-paginated like the unit list. Serves
     * the "batch history" audit surface.
     *
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listBatches(?int $unitId, ?string $status, ?string $cursor, int $limit): array
    {
        $this->policy->check('list');

        $builder = $this->db->table('facilities_bmg_batches AS b')
            ->select(
                'b.id, b.reference_code, b.status, b.total_input_weight_kg, b.output_weight_kg,'
                . ' b.total_loss_kg, b.quality_grade, b.maturity_level, b.started_at, b.finished_at,'
                . ' b.released_at, b.cancelled_at, b.created_at,'
                . ' u.id AS unit_id, u.code AS unit_code, u.display_name AS unit_name,'
                . ' c.name AS category_name'
            )
            ->join('facilities_bmg_units AS u', 'u.id = b.unit_id', 'left')
            ->join('facilities_waste_categories AS c', 'c.id = b.category_id', 'left')
            ->where('b.archived_at', null)
            ->where('b.tenant_id', CurrentTenant::id())
            ->orderBy('b.created_at', 'DESC')
            ->orderBy('b.id', 'DESC');

        if ($unitId !== null) {
            $builder->where('b.unit_id', $unitId);
        }
        if ($status !== null) {
            $builder->where('b.status', $status);
        }

        KeysetPaginator::apply($builder, $cursor, $limit, 'b.created_at', 'b.id');

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit, 'created_at');

        $data = array_map(static fn (array $r): array => [
            'id'                  => (int) $r['id'],
            'reference_code'      => (string) $r['reference_code'],
            'status'              => (string) $r['status'],
            'unit_id'             => (int) $r['unit_id'],
            'unit_code'           => (string) $r['unit_code'],
            'unit_name'           => (string) $r['unit_name'],
            'category_name'       => $r['category_name'] !== null ? (string) $r['category_name'] : null,
            'total_input_weight_kg' => (float) $r['total_input_weight_kg'],
            'output_weight_kg'    => $r['output_weight_kg'] !== null ? (float) $r['output_weight_kg'] : null,
            'total_loss_kg'       => $r['total_loss_kg'] !== null ? (float) $r['total_loss_kg'] : null,
            'quality_grade'       => $r['quality_grade'] !== null ? (string) $r['quality_grade'] : null,
            'maturity_level'      => $r['maturity_level'] !== null ? (string) $r['maturity_level'] : null,
            'started_at'          => (string) $r['started_at'],
            'finished_at'         => $r['finished_at'] !== null ? (string) $r['finished_at'] : null,
            'released_at'         => $r['released_at'] !== null ? (string) $r['released_at'] : null,
            'cancelled_at'        => $r['cancelled_at'] !== null ? (string) $r['cancelled_at'] : null,
        ], $final['rows']);

        return ['data' => $data, 'next' => $final['nextCursor'], 'count' => $limit];
    }

    // ----------------------------------------- final QA release gate

    /**
     * Release a batch for use — the final quality/maturity gate before
     * compost leaves the system. Only an `awaiting_output` / `curing`
     * batch can be released; the operator records a quality grade +
     * maturity level (the batch's "certificate" fields). Terminal state
     * `released`; the unit returns to Idle.
     *
     * @param array{quality_grade?:string, maturity_level?:string, notes?:?string} $input
     */
    public function releaseBatch(int $batchId, array $input): array
    {
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('release', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $input, $userId): array {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }
            if (! in_array($batch['status'], [BMG_STATE_AWAITING_OUTPUT, BMG_STATE_CURING], true)) {
                throw StateMachineException::invalidTransition($batch['status'], BMG_STATE_RELEASED, 'bmg');
            }

            $grade    = (string) ($input['quality_grade'] ?? '');
            $maturity = (string) ($input['maturity_level'] ?? '');
            if ($grade === '' || ! in_array($grade, BMG_QUALITY_GRADES, true)) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'quality_grade is required (excellent, good, fair).', 'field' => 'quality_grade'],
                ]);
            }
            if ($maturity === '' || ! in_array($maturity, BMG_MATURITY_LEVELS, true)) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'maturity_level is required (mature, maturing, immature).', 'field' => 'maturity_level'],
                ]);
            }

            $now = $this->utcNow();
            $notes = (string) ($input['notes'] ?? '');
            $this->db->table('facilities_bmg_batches')
                ->where('id', $batchId)
                ->update([
                    'status'              => BMG_STATE_RELEASED,
                    'released_at'         => $now,
                    'released_by_user_id' => $userId,
                    'quality_grade'       => $grade,
                    'maturity_level'      => $maturity,
                    'notes'               => $notes !== '' ? ($notes) : ($batch['notes'] ?? null),
                    'updated_at'          => $now,
                ]);

            $this->db->table('facilities_bmg_units')
                ->where('id', (int) $batch['unit_id'])
                ->update(['status' => BMG_STATE_IDLE, 'updated_at' => $now]);

            $this->audit->enqueue(
                'bmg.batch_released',
                'facilities_bmg_batches',
                $batchId,
                $userId,
                ['previous_status' => (string) $batch['status'], 'next_status' => BMG_STATE_RELEASED, 'quality_grade' => $grade, 'maturity_level' => $maturity],
            );

            $fresh = $this->db->table('facilities_bmg_batches')->where('id', $batchId)->get()->getRowArray();
            return BmgBatchDto::fromRow($fresh)->toArray();
        });
    }

    // ------------------------------------------------- PFRP compliance

    /**
     * PFRP compliance summary for a batch — the certificate data.
     *
     * Computes from the process-log timeline whether the batch entered
     * the pathogen-reduction window (any log ≥55 °C), the peak temp,
     * consecutive thermophilic days, the mass balance
     * (input − output − losses), and the final quality/maturity when
     * released. Used to render the printable batch certificate.
     *
     * @return array<string, mixed>
     */
    public function batchCompliance(int $batchId): array
    {
        $batch = $this->db->table('facilities_bmg_batches')
            ->select('id, reference_code, status, total_input_weight_kg, output_weight_kg, total_loss_kg, accumulated_in_process_kg, quality_grade, maturity_level, started_at, finished_at, released_at, cancelled_at, started_by_user_id, tenant_id, archived_at')
            ->where('id', $batchId)->where('tenant_id', CurrentTenant::id())->where('archived_at', null)
            ->get()->getRowArray();
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('analytics', $batch);

        $logs = $this->db->table('facilities_bmg_process_logs')
            ->select('log_date, temperature_celsius')
            ->where('batch_id', $batchId)
            ->where('temperature_celsius IS NOT NULL', null, false)
            ->orderBy('log_date', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $thermoDays = 0;      // distinct days ≥55 °C
        $maxTemp    = null;
        $consecutive = 0;     // longest run of consecutive thermo days
        $bestRun    = 0;
        $prevDay    = null;
        foreach ($logs as $l) {
            $temp = (float) $l['temperature_celsius'];
            if ($temp > ($maxTemp ?? -999)) {
                $maxTemp = $temp;
            }
            $day = (string) $l['log_date'];
            if ($temp >= 55.0) {
                $thermoDays++;
                if ($prevDay !== null) {
                    $prev = new \DateTimeImmutable($prevDay);
                    $cur  = new \DateTimeImmutable($day);
                    $diff = (int) $prev->diff($cur)->days;
                    $consecutive = ($diff === 1) ? $consecutive + 1 : 1;
                } else {
                    $consecutive = 1;
                }
                $bestRun = max($bestRun, $consecutive);
                $prevDay = $day;
            }
        }

        $inputKg  = (float) $batch['total_input_weight_kg'];
        $outputKg = $batch['output_weight_kg'] !== null ? (float) $batch['output_weight_kg'] : 0.0;
        $lossKg   = $batch['total_loss_kg'] !== null ? (float) $batch['total_loss_kg'] : 0.0;
        $inProcess = $batch['accumulated_in_process_kg'] !== null ? (float) $batch['accumulated_in_process_kg'] : 0.0;
        $accounted = $outputKg + $lossKg + $inProcess;
        $balanceKg = round($inputKg - $accounted, 2);
        $yieldPct  = $inputKg > 0 ? round(($outputKg / $inputKg) * 100, 1) : null;

        return [
            'batch_id'          => $batchId,
            'reference_code'    => (string) $batch['reference_code'],
            'status'            => (string) $batch['status'],
            'started_at'        => (string) $batch['started_at'],
            'finished_at'       => $batch['finished_at'] !== null ? (string) $batch['finished_at'] : null,
            'released_at'       => $batch['released_at'] !== null ? (string) $batch['released_at'] : null,
            'cancelled_at'      => $batch['cancelled_at'] !== null ? (string) $batch['cancelled_at'] : null,
            // PFRP: thermophilic ≥55 °C (pathogen-reduction window).
            'thermophilic_days' => $thermoDays,
            'max_temperature_c' => $maxTemp !== null ? round($maxTemp, 1) : null,
            'consecutive_pfrp_days' => $bestRun,
            'pfrp_met'          => $bestRun >= 3 || $maxTemp !== null && $maxTemp >= 65.0,
            // Mass balance.
            'input_kg'          => round($inputKg, 2),
            'output_kg'         => round($outputKg, 2),
            'loss_kg'           => round($lossKg, 2),
            'in_process_kg'     => round($inProcess, 2),
            'unaccounted_kg'    => $balanceKg,
            'yield_pct'         => $yieldPct,
            // Final QA (only set when released).
            'quality_grade'     => $batch['quality_grade'] !== null ? (string) $batch['quality_grade'] : null,
            'maturity_level'    => $batch['maturity_level'] !== null ? (string) $batch['maturity_level'] : null,
        ];
    }

    // ------------------------------------------------------ blend C:N

    /**
     * Weighted C:N ratio for a batch's feedstock blend (item #5).
     * Averages the recorded per-input `cn_ratio`, weighted by input
     * weight. Flags when the blend sits outside the operational
     * 15–30 band (too low → ammonia off-gassing, too high →
     * nitrogen-starved decomposition).
     *
     * @return array{blend_cn: ?float, n_inputs: int, status: string, note: ?string}
     */
    public function blendCn(int $batchId): array
    {
        $batch = $this->db->table('facilities_bmg_batches')
            ->select('id, started_by_user_id, tenant_id, archived_at')
            ->where('id', $batchId)->where('tenant_id', CurrentTenant::id())->where('archived_at', null)
            ->get()->getRowArray();
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('analytics', $batch);

        $rows = $this->db->table('facilities_bmg_inputs')
            ->select('weight_kg, cn_ratio')
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->where('cn_ratio IS NOT NULL', null, false)
            ->get()->getResultArray();

        if ($rows === []) {
            return ['blend_cn' => null, 'n_inputs' => 0, 'status' => 'unknown', 'note' => 'No feedstock C:N data recorded.'];
        }

        $weighted = 0.0;
        $weight   = 0.0;
        foreach ($rows as $r) {
            $w = (float) $r['weight_kg'];
            $weighted += (float) $r['cn_ratio'] * $w;
            $weight   += $w;
        }
        $blend = $weight > 0 ? round($weighted / $weight, 1) : null;

        if ($blend === null) {
            return ['blend_cn' => null, 'n_inputs' => count($rows), 'status' => 'unknown', 'note' => 'Could not compute blend C:N.'];
        }
        if ($blend < 15) {
            return ['blend_cn' => $blend, 'n_inputs' => count($rows), 'status' => 'low', 'note' => 'Blend C:N below 15 — risk of ammonia off-gassing; add carbon-rich (brown) material.'];
        }
        if ($blend > 30) {
            return ['blend_cn' => $blend, 'n_inputs' => count($rows), 'status' => 'high', 'note' => 'Blend C:N above 30 — decomposition may be nitrogen-starved; add nitrogen-rich (green) material.'];
        }
        return ['blend_cn' => $blend, 'n_inputs' => count($rows), 'status' => 'optimal', 'note' => 'Blend C:N within the 15–30 operational band.'];
    }

    // ----------------------------------------------- unit utilization

    /**
     * Suggested idle drum for a batch, preferring units whose default
     * category matches the batch category, then by capacity headroom.
     * Returns null when no idle/available unit exists.
     *
     * @return array<string, mixed>|null
     */
    public function suggestUnit(int $categoryId): ?array
    {
        $this->policy->check('list');

        $rows = $this->db->table('facilities_bmg_units')
            ->select('id, code, display_name, status, location_code, spec_capacity_kg, default_category_id')
            ->where('tenant_id', CurrentTenant::id())
            ->where('archived_at', null)
            ->whereIn('status', [BMG_STATE_IDLE, BMG_STATE_MAINTENANCE])
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $candidates = [];
        foreach ($rows as $r) {
            $candidates[] = [
                'unit'      => $r,
                'match_cat' => $r['default_category_id'] !== null && (int) $r['default_category_id'] === $categoryId ? 1 : 0,
                'capacity'  => $r['spec_capacity_kg'] !== null ? (float) $r['spec_capacity_kg'] : 0.0,
                'ready'     => (string) $r['status'] === BMG_STATE_IDLE ? 1 : 0,
            ];
        }
        if ($candidates === []) {
            return null;
        }
        // Rank: ready > category match > capacity (larger first).
        usort($candidates, static fn (array $a, array $b): int => [
            $b['ready'], $b['match_cat'], $b['capacity'],
        ] <=> [
            $a['ready'], $a['match_cat'], $a['capacity'],
        ]);

        $best = $candidates[0]['unit'];
        return [
            'id'               => (int) $best['id'],
            'code'             => (string) $best['code'],
            'display_name'     => (string) $best['display_name'],
            'location_code'    => $best['location_code'] !== null ? (string) $best['location_code'] : null,
            'spec_capacity_kg' => $best['spec_capacity_kg'] !== null ? (float) $best['spec_capacity_kg'] : null,
            'default_category_id' => $best['default_category_id'] !== null ? (int) $best['default_category_id'] : null,
        ];
    }

    // -------------------------------------------------- SOP register

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSopDocuments(bool $includeArchived = false): array
    {
        $this->policy->check('manage_units');

        $builder = $this->db->table('facilities_sop_documents AS s')
            ->select('s.id, s.title, s.document_ref, s.category, s.version, s.owner_user_id, s.notes, s.is_active, s.created_at, s.updated_at, u.first_name, u.last_name')
            ->join('users AS u', 'u.id = s.owner_user_id', 'left')
            ->where('s.tenant_id', CurrentTenant::id())
            ->orderBy('s.document_ref', 'ASC')
            ->orderBy('s.id', 'ASC');
        if (! $includeArchived) {
            $builder->where('s.is_active', 1);
        }
        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'title'        => (string) $r['title'],
            'document_ref' => (string) $r['document_ref'],
            'category'     => $r['category'] !== null ? (string) $r['category'] : null,
            'version'      => $r['version'] !== null ? (string) $r['version'] : null,
            'owner_user_id'=> $r['owner_user_id'] !== null ? (int) $r['owner_user_id'] : null,
            'owner_name'   => ($r['first_name'] ?? '') !== '' ? trim((string) $r['first_name'] . ' ' . (string) ($r['last_name'] ?? '')) : null,
            'notes'        => $r['notes'] !== null ? (string) $r['notes'] : null,
            'is_active'    => (bool) $r['is_active'],
            'created_at'   => (string) $r['created_at'],
            'updated_at'   => (string) $r['updated_at'],
        ], $builder->get()->getResultArray());
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createSopDocument(array $input): array
    {
        $this->policy->check('manage_units');
        $actor = \App\Auth\CurrentUser::assert();
        $now   = $this->utcNow();

        $id = $this->txn(function () use ($input, $actor, $now): int {
            $this->db->table('facilities_sop_documents')->insert([
                'tenant_id'     => CurrentTenant::id(),
                'title'         => (string) $input['title'],
                'document_ref'  => (string) $input['document_ref'],
                'category'      => isset($input['category']) && $input['category'] !== '' ? (string) $input['category'] : null,
                'version'       => isset($input['version']) && $input['version'] !== '' ? (string) $input['version'] : null,
                'owner_user_id' => isset($input['owner_user_id']) && (int) $input['owner_user_id'] > 0 ? (int) $input['owner_user_id'] : null,
                'notes'         => isset($input['notes']) && $input['notes'] !== '' ? (string) $input['notes'] : null,
                'is_active'     => 1,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
            $id = (int) $this->db->insertID();
            $this->audit->enqueue('bmg.sop_created', 'facilities_sop_documents', $id, $actor, ['resource_code' => (string) $input['document_ref']]);
            return $id;
        });

        return $this->findSopDocument($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateSopDocument(int $id, array $input): array
    {
        $this->policy->check('manage_units');
        $actor = \App\Auth\CurrentUser::assert();

        $this->txn(function () use ($id, $input, $actor): void {
            $row = $this->selectForUpdate('facilities_sop_documents', ['id' => $id, 'tenant_id' => CurrentTenant::id()]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "SOP document #{$id} not found."],
                ]);
            }
            $update = ['updated_at' => $this->utcNow()];
            foreach (['title', 'document_ref', 'category', 'version', 'notes'] as $f) {
                if (array_key_exists($f, $input)) {
                    $update[$f] = $input[$f] !== null && $input[$f] !== '' ? (string) $input[$f] : null;
                }
            }
            if (array_key_exists('owner_user_id', $input)) {
                $update['owner_user_id'] = (int) $input['owner_user_id'] > 0 ? (int) $input['owner_user_id'] : null;
            }
            if (array_key_exists('is_active', $input)) {
                $update['is_active'] = (int) $input['is_active'];
            }
            $this->db->table('facilities_sop_documents')->where('id', $id)->update($update);
            $this->audit->enqueue('bmg.sop_updated', 'facilities_sop_documents', $id, $actor, []);
        });

        return $this->findSopDocument($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function findSopDocument(int $id): array
    {
        $rows = $this->listSopDocuments(true);
        foreach ($rows as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        throw new ApiException('resource.not_found', 404, [
            ['code' => 'resource.not_found', 'message' => "SOP document #{$id} not found."],
        ]);
    }

    // ------------------------------------------- category deviations

    /**
     * Actual vs expected yield/duration per waste category (item #10).
     * Compares finished/released batches against the category's
     * reference values to spot chronic under- or over-performance.
     *
     * @return array<int, array<string, mixed>>
     */
    public function wasteCategoryDeviation(): array
    {
        $this->policy->check('manage_units');

        $cats = $this->db->table('facilities_waste_categories')
            ->select('id, code, name, expected_yield_pct, reference_duration_days')
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();

        $out = [];
        foreach ($cats as $c) {
            $cid = (int) $c['id'];
            $stats = $this->db->table('facilities_bmg_batches')
                ->select('COUNT(*) AS n, AVG(output_weight_kg / NULLIF(total_input_weight_kg, 0)) * 100 AS avg_yield, AVG(DATEDIFF(COALESCE(released_at, finished_at), started_at)) AS avg_days')
                ->where('category_id', $cid)
                ->whereIn('status', [BMG_STATE_RELEASED, BMG_STATE_IDLE])
                ->where('archived_at', null)
                ->where('tenant_id', CurrentTenant::id())
                ->get()->getRowArray();

            $n = (int) ($stats['n'] ?? 0);
            $avgYield = $stats['avg_yield'] !== null ? round((float) $stats['avg_yield'], 1) : null;
            $avgDays  = $stats['avg_days'] !== null ? (int) round((float) $stats['avg_days']) : null;
            $expYield = $c['expected_yield_pct'] !== null ? (float) $c['expected_yield_pct'] : null;
            $expDays  = $c['reference_duration_days'] !== null ? (int) $c['reference_duration_days'] : null;

            $out[] = [
                'category_id'            => $cid,
                'code'                   => (string) $c['code'],
                'name'                   => (string) $c['name'],
                'batch_count'            => $n,
                'actual_yield_pct'       => $avgYield,
                'expected_yield_pct'     => $expYield,
                'yield_delta_pp'         => $expYield !== null && $avgYield !== null ? round($avgYield - $expYield, 1) : null,
                'actual_days'            => $avgDays,
                'expected_days'          => $expDays,
                'days_delta'             => $expDays !== null && $avgDays !== null ? $avgDays - $expDays : null,
            ];
        }
        return $out;
    }
}
