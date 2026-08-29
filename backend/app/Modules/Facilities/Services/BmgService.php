<?php

declare(strict_types=1);

namespace Modules\Facilities\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Analytics\BmgAnalytics;
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
use Modules\Facilities\Services\Bmg\AlertService;
use Modules\Facilities\Services\Bmg\AnalyticsReader;
use Modules\Facilities\Services\Bmg\BatchIoService;
use Modules\Facilities\Services\Bmg\BmgSupport;
use Modules\Facilities\Services\Bmg\CategoryService;
use Modules\Facilities\Services\Bmg\HistoryService;

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
    private readonly BmgAnalytics $analytics;
    private readonly BmgSupport $support;
    private readonly BatchIoService $batchIo;
    private readonly AnalyticsReader $analyticsReader;
    private readonly AlertService $alerts;
    private readonly HistoryService $history;
    private readonly CategoryService $categories;

    public function __construct(
        private readonly BmgPolicy $policy,
        private readonly AuditOutboxService $audit,
        private ?BmgAlertEngine $alertEngine = null,
        private ?NotificationOutboxService $notify = null,
    ) {
        parent::__construct();
        $this->alertEngine ??= new BmgAlertEngine();
        $this->notify ??= new NotificationOutboxService();

        // Stateless/pure collaborators — the analytics instance is shared
        // with BmgSupport and AnalyticsReader. One BmgPolicy instance is
        // shared across every cluster so the action-name contract and the
        // ownership toggle stay identical everywhere.
        $this->analytics       = new BmgAnalytics();
        $this->support         = new BmgSupport($this->db, $this->analytics);
        $this->batchIo         = new BatchIoService($this->db, $this->policy, $this->audit, $this->support);
        $this->analyticsReader = new AnalyticsReader($this->db, $this->policy, $this->support, $this->analytics);
        $this->alerts          = new AlertService($this->db, $this->policy, $this->audit);
        $this->history         = new HistoryService($this->db, $this->policy);
        $this->categories      = new CategoryService($this->db, $this->policy, $this->audit, $this->support);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUnits(?string $cursor, int $limit, bool $includeArchived = false): array
    {
        $this->policy->check('list');

        $builder = $this->db->table('facilities_bmg_units AS u')
            ->select('u.id, u.code, u.display_name, u.status, u.location_code, u.spec_capacity_kg, u.default_category_id, u.notes, u.created_at, u.updated_at, u.archived_at, c.name AS default_category_name, b.id AS active_batch_id, b.total_input_weight_kg AS active_batch_weight_kg, b.started_at AS active_batch_started_at, b.expected_completion_date AS active_batch_expected_completion_date')
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

        // Attach expected completion + progress for any active batch
        // (mirrors the `listActiveBatches` computation, so the drum card
        // can show an ETA and progress bar on Start / while In Use).
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $a     = $this->analytics;
        foreach ($final['rows'] as &$r) {
            if ($r['active_batch_started_at'] === null) {
                $r['active_batch_expected_completion_date'] = null;
                $r['active_batch_progress_pct']             = null;
                continue;
            }
            $startDate = substr((string) $r['active_batch_started_at'], 0, 10);
            $expected  = $r['active_batch_expected_completion_date'] !== null
                ? substr((string) $r['active_batch_expected_completion_date'], 0, 10)
                : $a->expectedCompletionDate($startDate, BmgAnalytics::DEFAULT_DURATION_DAYS);
            $r['active_batch_progress_pct'] = $a->progressPercent($startDate, $expected, $today);
        }
        unset($r);

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
        $composition = $this->support->normalizeComposition($composition, $totalInputKg);

        return $this->txn(function () use ($unitId, $inputItems, $totalInputKg, $composition, $userId): BmgBatchDto {
            // Lock the unit row.
            $unit = $this->selectForUpdate('facilities_bmg_units', ['id' => $unitId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);

            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "BMG unit #{$unitId} not found."],
                ]);
            }

            // A batch's total input can never exceed the drum's rated
            // capacity — a drum can't hold more than it was built for.
            if ($unit['spec_capacity_kg'] !== null
                && (float) $unit['spec_capacity_kg'] > 0
                && $totalInputKg > (float) $unit['spec_capacity_kg']) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'Total input weight ' . $totalInputKg
                        . ' kg exceeds this drum\'s capacity (' . $unit['spec_capacity_kg'] . ' kg).', 'field' => 'total_input_weight_kg'],
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

            // Expected completion = started_at + the waste category's
            // reference duration. For a mixed composition, the expected
            // days are the weight-weighted average of each category's
            // reference_duration_days (the longest-running component
            // governs the cure). Drives the "expected completion" +
            // progress indicator surfaced on Start.
            $expectedDays = $this->support->expectedDurationDays($composition, $categoryId);
            $expectedAt  = null;
            if ($expectedDays !== null) {
                $expectedAt = (new DateTimeImmutable($now, new DateTimeZone('UTC')))
                    ->modify("+{$expectedDays} days")
                    ->format('Y-m-d H:i:s');
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
                'expected_completion_date' => $expectedAt,
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
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
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

            $batch = $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $batchId)->get()->getRowArray();
            return BmgBatchDto::fromRow($batch);
        });
    }

    /**
     * Unified "Add update" on an active batch — appends ONE immutable,
     * timestamped entry to the batch-updates ledger, branching internally
     * by `update_type`:
     *
     *   - `output` — output weight recorded. Validated against the CUMULATIVE
     *     output (ledger sum + new) ≤ total input; otherwise blocked.
     *   - `curing` — marks the batch moved into the curing phase. At most
     *     ONE active curing transition per batch (a second one is rejected).
     *   - `log` — free-form observation (temperature / turning / aeration /
     *     moisture / notes).
     *
     * The batch row's `output_weight_kg` / `status` are DENORMALIZED
     * aggregates kept in sync for fast reads, but the ledger row is the
     * source of truth and is never overwritten (append-only).
     *
     * @param array{update_type:string, output_weight_kg?:float, curing_note?:?string, event_type?:?string, observation_note?:?string, temperature_celsius?:?float, moisture_level?:?string} $input
     * @return array<string, mixed> the created entry
     */
    public function addBatchUpdate(int $batchId, array $input): array
    {
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('update', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        $type = (string) ($input['update_type'] ?? '');
        if (! in_array($type, ['output', 'curing', 'log'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'update_type must be output, curing, or log.', 'field' => 'update_type'],
            ]);
        }

        return $this->txn(function () use ($batchId, $type, $input, $userId): array {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }
            if (in_array($batch['status'], [BMG_STATE_IDLE, BMG_STATE_RELEASED, BMG_STATE_CANCELLED], true)) {
                throw StateMachineException::invalidTransition($batch['status'], 'update', 'bmg');
            }

            $now = $this->support->utcNow();

            if ($type === 'output') {
                $kg = (float) ($input['output_weight_kg'] ?? 0);
                if ($kg <= 0) {
                    throw ApiException::validationFailure([
                        ['code' => 'validation.field', 'message' => 'output_weight_kg must be > 0.', 'field' => 'output_weight_kg'],
                    ]);
                }
                // Cumulative output = ledger sum + this entry. Never exceeds input.
                $sum = (float) $this->db->table('facilities_bmg_batch_updates')
                    ->where('facilities_bmg_batch_updates.tenant_id', CurrentTenant::id())
                    ->selectSum('output_weight_kg', 'total')
                    ->where('batch_id', $batchId)
                    ->where('update_type', 'output')
                    ->get()->getRowArray()['total'] ?? 0.0;
                $cumulative = round($sum + $kg, 4);
                if ($cumulative > (float) $batch['total_input_weight_kg']) {
                    throw StateMachineException::massInvariant();
                }

                $this->db->table('facilities_bmg_batch_updates')->insert([
                    'tenant_id'           => CurrentTenant::id(),
                    'batch_id'            => $batchId,
                    'update_type'         => 'output',
                    'output_weight_kg'    => $kg,
                    'recorded_by_user_id' => $userId,
                    'created_at'          => $now,
                ]);
                $entryId = (int) $this->db->insertID();

                // Denormalized aggregate + phase advance (Processing → AwaitingOutput).
                $this->db->table('facilities_bmg_batches')
                    ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                    ->where('id', $batchId)
                    ->update([
                        'output_weight_kg'   => $cumulative,
                        'status'             => $batch['status'] === BMG_STATE_PROCESSING ? BMG_STATE_AWAITING_OUTPUT : $batch['status'],
                        'awaiting_output_at' => $batch['status'] === BMG_STATE_PROCESSING ? $now : ($batch['awaiting_output_at'] ?? null),
                        'updated_at'         => $now,
                    ]);

                $this->audit->enqueue('bmg.output_recorded', 'facilities_bmg_batches', $batchId, $userId, [
                    'update_entry_id' => $entryId, 'output_weight_kg' => $kg, 'cumulative_kg' => $cumulative,
                ]);

                return $this->batchUpdateRow($entryId);
            }

            if ($type === 'curing') {
                // Only ONE active curing transition per batch.
                $existing = $this->db->table('facilities_bmg_batch_updates')
                    ->where('facilities_bmg_batch_updates.tenant_id', CurrentTenant::id())
                    ->where('batch_id', $batchId)
                    ->where('update_type', 'curing')
                    ->get()->getRowArray();
                if ($existing !== null) {
                    throw StateMachineException::invalidTransition($batch['status'], BMG_STATE_CURING, 'bmg');
                }
                if (in_array($batch['status'], [BMG_STATE_CURING], true)) {
                    throw StateMachineException::invalidTransition($batch['status'], BMG_STATE_CURING, 'bmg');
                }

                $this->db->table('facilities_bmg_batch_updates')->insert([
                    'tenant_id'           => CurrentTenant::id(),
                    'batch_id'            => $batchId,
                    'update_type'         => 'curing',
                    'curing_note'         => (string) ($input['curing_note'] ?? ''),
                    'recorded_by_user_id' => $userId,
                    'created_at'          => $now,
                ]);
                $entryId = (int) $this->db->insertID();

                $this->db->table('facilities_bmg_batches')
                    ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                    ->where('id', $batchId)
                    ->update(['status' => BMG_STATE_CURING, 'updated_at' => $now]);
                $this->db->table('facilities_bmg_units')
                    ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                    ->where('id', (int) $batch['unit_id'])
                    ->update(['status' => BMG_STATE_CURING, 'updated_at' => $now]);

                $this->audit->enqueue('bmg.batch_curing', 'facilities_bmg_batches', $batchId, $userId, [
                    'update_entry_id' => $entryId, 'next_status' => BMG_STATE_CURING,
                ]);

                return $this->batchUpdateRow($entryId);
            }

            // log entry
            $eventType = (string) ($input['event_type'] ?? 'observation');
            $allowedEvents = ['observation', 'turning', 'aeration', 'moisture_adjustment', 'other'];
            if (! in_array($eventType, $allowedEvents, true)) {
                $eventType = 'observation';
            }
            $this->db->table('facilities_bmg_batch_updates')->insert([
                'tenant_id'           => CurrentTenant::id(),
                'batch_id'            => $batchId,
                'update_type'         => 'log',
                'event_type'          => $eventType,
                'observation_note'    => (string) ($input['observation_note'] ?? ''),
                'temperature_celsius' => isset($input['temperature_celsius']) && $input['temperature_celsius'] !== null && $input['temperature_celsius'] !== ''
                    ? (float) $input['temperature_celsius']
                    : null,
                'moisture_level'      => ($input['moisture_level'] ?? '') !== '' ? (string) $input['moisture_level'] : null,
                'recorded_by_user_id' => $userId,
                'created_at'          => $now,
            ]);
            $entryId = (int) $this->db->insertID();

            $this->audit->enqueue('bmg.log_added', 'facilities_bmg_batches', $batchId, $userId, [
                'update_entry_id' => $entryId, 'event_type' => $eventType,
            ]);

            return $this->batchUpdateRow($entryId);
        });
    }

    /**
     * Combined, append-only "Updates" feed for a batch — output / curing /
     * log entries ordered oldest → newest. Read-only.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBatchUpdates(int $batchId): array
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

        $rows = $this->db->table('facilities_bmg_batch_updates')
            ->select('id, update_type, output_weight_kg, curing_note, event_type, observation_note, temperature_celsius, moisture_level, recorded_by_user_id, created_at')
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'                  => (int) $r['id'],
            'update_type'         => (string) $r['update_type'],
            'output_weight_kg'    => $r['output_weight_kg'] !== null ? (float) $r['output_weight_kg'] : null,
            'curing_note'         => $r['curing_note'] !== null && $r['curing_note'] !== '' ? (string) $r['curing_note'] : null,
            'event_type'          => $r['event_type'] !== null ? (string) $r['event_type'] : null,
            'observation_note'    => $r['observation_note'] !== null && $r['observation_note'] !== '' ? (string) $r['observation_note'] : null,
            'temperature_celsius' => $r['temperature_celsius'] !== null ? (float) $r['temperature_celsius'] : null,
            'moisture_level'      => $r['moisture_level'] !== null ? (string) $r['moisture_level'] : null,
            'recorded_by_user_id' => $r['recorded_by_user_id'] !== null ? (int) $r['recorded_by_user_id'] : null,
            'created_at'          => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * Shared validation for the two graded-release transitions —
     * `finishBatch` (any active state, records a final output entry,
     * stamps finished_at) and `releaseBatch` (awaiting_output/curing
     * only, no output entry). They are deliberately SEPARATE operations
     * with different state guards, side effects, and audit events; this
     * helper deduplicates only the QA-fields validation they share.
     *
     * @return array{grade:string, maturity:string}
     */
    private function assertGradedReleaseInput(array $input): array
    {
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
        return ['grade' => $grade, 'maturity' => $maturity];
    }

    /**
     * Fetches a single ledger row by id (typed).
     *
     * @return array<string, mixed>
     */
    private function batchUpdateRow(int $id): array
    {
        $row = $this->db->table('facilities_bmg_batch_updates')
            ->where('facilities_bmg_batch_updates.tenant_id', CurrentTenant::id())
            ->where('id', $id)->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Update entry #{$id} not found."],
            ]);
        }
        return [
            'id'                  => (int) $row['id'],
            'update_type'         => (string) $row['update_type'],
            'output_weight_kg'    => $row['output_weight_kg'] !== null ? (float) $row['output_weight_kg'] : null,
            'curing_note'         => $row['curing_note'] !== null && $row['curing_note'] !== '' ? (string) $row['curing_note'] : null,
            'event_type'          => $row['event_type'] !== null ? (string) $row['event_type'] : null,
            'observation_note'    => $row['observation_note'] !== null && $row['observation_note'] !== '' ? (string) $row['observation_note'] : null,
            'temperature_celsius' => $row['temperature_celsius'] !== null ? (float) $row['temperature_celsius'] : null,
            'moisture_level'      => $row['moisture_level'] !== null ? (string) $row['moisture_level'] : null,
            'recorded_by_user_id' => $row['recorded_by_user_id'] !== null ? (int) $row['recorded_by_user_id'] : null,
            'created_at'          => (string) $row['created_at'],
        ];
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
                    ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
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

            $fresh = $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $batchId)->get()->getRowArray();
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

            $now = $this->support->utcNow();
            $aip = $accumulatedKg !== null ? round($accumulatedKg, 2) : 0.00;

            $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $batchId)
                ->update([
                    'status'                    => BMG_STATE_CURING,
                    'accumulated_in_process_kg' => $aip,
                    'updated_at'                => $now,
                ]);

            $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
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

            $fresh = $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $batchId)->get()->getRowArray();
            return BmgBatchDto::fromRow($fresh);
        });
    }

    /**
     * Finish = GRADED RELEASE. The single "Finish batch" action requires
     * a valid quality outcome (quality_grade + maturity_level); the batch
     * leaves the system as `released` and the drum returns to Idle.
     *
     * A run being closed WITHOUT a valid quality outcome (failed /
     * discarded) is NOT a finish — route it through `cancelBatch`
     * instead (the spec: "Finish always implies a successful, graded
     * release").
     *
     * @param array{quality_grade?:string, maturity_level?:string, notes?:?string} $input
     */
    public function finishBatch(int $batchId, array $input = []): BmgBatchDto
    {
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('finish', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $input, $userId): BmgBatchDto {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);

            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }

            // Finish is available from ANY active state (processing /
            // awaiting_output / curing) — e.g. the operator recorded a
            // process log confirming the desired output is being met, so
            // they can finalize the batch without forcing the intermediate
            // "record output" step. Only terminal/idle batches can't be
            // finished.
            if (! in_array($batch['status'], [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT, BMG_STATE_CURING], true)) {
                throw StateMachineException::invalidTransition($batch['status'], BMG_STATE_RELEASED, 'bmg');
            }

            // A finish is a GRADED release — both QA fields are required.
            ['grade' => $grade, 'maturity' => $maturity] = $this->assertGradedReleaseInput($input);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            // The final output (yield) is recorded AT FINISH — one output
            // ledger entry, validated so cumulative output never exceeds
            // the batch's total input. Optional only when the run produced
            // nothing measurable (then it stays null).
            $outputKg = isset($input['output_weight_kg']) && $input['output_weight_kg'] !== '' && $input['output_weight_kg'] !== null
                ? (float) $input['output_weight_kg']
                : null;
            if ($outputKg !== null) {
                if ($outputKg <= 0) {
                    throw ApiException::validationFailure([
                        ['code' => 'validation.field', 'message' => 'output_weight_kg must be > 0.', 'field' => 'output_weight_kg'],
                    ]);
                }
                $sum = (float) ($this->db->table('facilities_bmg_batch_updates')
                    ->where('facilities_bmg_batch_updates.tenant_id', CurrentTenant::id())
                    ->selectSum('output_weight_kg', 'total')
                    ->where('batch_id', $batchId)
                    ->where('update_type', 'output')
                    ->get()->getRowArray()['total'] ?? 0.0);
                $cumulative = round($sum + $outputKg, 4);
                if ($cumulative > (float) $batch['total_input_weight_kg']) {
                    throw StateMachineException::massInvariant();
                }
                $this->db->table('facilities_bmg_batch_updates')->insert([
                    'tenant_id'           => CurrentTenant::id(),
                    'batch_id'            => $batchId,
                    'update_type'         => 'output',
                    'output_weight_kg'    => $outputKg,
                    'recorded_by_user_id' => $userId,
                    'created_at'          => $now,
                ]);
                $this->audit->enqueue('bmg.output_recorded', 'facilities_bmg_batches', $batchId, $userId, [
                    'output_weight_kg' => $outputKg, 'cumulative_kg' => $cumulative, 'context' => 'finish',
                ]);
            }

            $notes = (string) ($input['notes'] ?? '');

            $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $batchId)
                ->update([
                    'status'              => BMG_STATE_RELEASED,
                    'finished_at'         => $now,
                    'finished_by_user_id' => $userId,
                    'released_at'         => $now,
                    'released_by_user_id' => $userId,
                    'quality_grade'       => $grade,
                    'maturity_level'      => $maturity,
                    'output_weight_kg'    => $outputKg !== null ? $outputKg : $batch['output_weight_kg'],
                    'notes'               => $notes !== '' ? $notes : ($batch['notes'] ?? null),
                    'updated_at'          => $now,
                ]);

            $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', (int) $batch['unit_id'])
                ->update(['status' => BMG_STATE_IDLE, 'updated_at' => $now]);

            $this->audit->enqueue(
                'bmg.batch_finished',
                'facilities_bmg_batches',
                $batchId,
                $userId,
                ['previous_status' => (string) $batch['status'], 'next_status' => BMG_STATE_RELEASED, 'quality_grade' => $grade, 'maturity_level' => $maturity],
            );

            $fresh = $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $batchId)->get()->getRowArray();
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
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
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
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', (int) $batch['unit_id'])
                ->update(['status' => BMG_STATE_IDLE, 'updated_at' => $now]);

            $this->audit->enqueue(
                'bmg.batch_cancelled',
                'facilities_bmg_batches',
                $batchId,
                $userId,
                ['previous_status' => (string) $batch['status'], 'next_status' => BMG_STATE_CANCELLED, 'reason_code' => $reasonCode],
            );

            $fresh = $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $batchId)->get()->getRowArray();
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

        $code = $this->support->assertSlug((string) $input['code'], 'code');

        $defaultCategoryId = $this->resolveCategoryId($input['default_category_id'] ?? null);

        return $this->txn(function () use ($input, $code, $defaultCategoryId, $userId): BmgUnitDto {
            $dup = $this->db->table('facilities_bmg_units')->where('code', $code)->where('tenant_id', CurrentTenant::id())->get()->getRowArray();
            if ($dup !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "A unit with code '{$code}' already exists.", 'field' => 'code'],
                ]);
            }
            $now = $this->support->utcNow();
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
                ->where('u.tenant_id', CurrentTenant::id())
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

            $update = ['updated_at' => $this->support->utcNow()];
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

            $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', $unitId)->update($update);

            $this->audit->enqueue(
                'bmg.unit_updated',
                'facilities_bmg_units',
                $unitId,
                $userId,
                ['resource_code' => (string) $unit['code']],
            );

            $fresh = $this->db->table('facilities_bmg_units AS u')
                ->where('u.tenant_id', CurrentTenant::id())
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

            $now = $this->support->utcNow();
            $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', $unitId)->update([
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

            $fresh = $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', $unitId)->get()->getRowArray();
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
                    ->where('u.tenant_id', CurrentTenant::id())
                    ->select('u.*, c.name AS default_category_name')
                    ->join('facilities_waste_categories AS c', 'c.id = u.default_category_id', 'left')
                    ->where('u.id', $unitId)
                    ->get()->getRowArray();
                return BmgUnitDto::fromRow($fresh);
            }

            $now = $this->support->utcNow();
            $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', $unitId)->update([
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
                ->where('u.tenant_id', CurrentTenant::id())
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
        return $this->analyticsReader->listActiveBatches();
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
                ->where('facilities_bmg_process_logs.tenant_id', CurrentTenant::id())
                ->select('log_date')
                ->where('batch_id', $batchId)
                ->where('id !=', $id)
                ->orderBy('log_date', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();

            $row = $this->db->table('facilities_bmg_process_logs')
                ->where('facilities_bmg_process_logs.tenant_id', CurrentTenant::id())
                ->where('id', $id)->get()->getRowArray();

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

            $now = $this->support->utcNow();
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
        return $this->categories->listWasteCategories($activeOnly);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createWasteCategory(array $input): array
    {
        return $this->categories->createWasteCategory($input);
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
        return $this->categories->updateWasteCategory($categoryId, $input);
    }

    /**
     * Soft-archive a waste category by setting `is_active = 0` (refuses
     * while any unit still uses it as its default).
     */
    public function archiveWasteCategory(int $categoryId): array
    {
        return $this->categories->archiveWasteCategory($categoryId);
    }

    /**
     * Restore an archived waste category (`is_active = 1`) so it
     * reappears in pickers.
     */
    public function unarchiveWasteCategory(int $categoryId): array
    {
        return $this->categories->unarchiveWasteCategory($categoryId);
    }

    /**
     * Hard-delete a waste category (refused while any batch or unit
     * still references it).
     */
    public function deleteWasteCategory(int $categoryId): void
    {
        $this->categories->deleteWasteCategory($categoryId);
    }

    // --------------------------------------------------- structured I/O

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addBatchInput(int $batchId, array $input): array
    {
        return $this->batchIo->addBatchInput($batchId, $input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addBatchOutput(int $batchId, array $input): array
    {
        return $this->batchIo->addBatchOutput($batchId, $input);
    }

    /**
     * Deterministic yield/ETA analytics for a batch (Phase P4).
     *
     * @return array<string, mixed>
     */
    public function batchAnalytics(int $batchId): array
    {
        return $this->analyticsReader->batchAnalytics($batchId);
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
            $now = $this->support->utcNow();
            $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', $unitId)->update([
                    'status'     => $next,
                    'updated_at' => $now,
                ]);
            $this->audit->enqueue('bmg.unit_maintenance', 'facilities_bmg_units', $unitId, $userId, [
                'next_status' => $next,
            ]);
            return ['id' => $unitId, 'status' => $next];
        });
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
        return $this->alerts->listAlerts($batchId);
    }

    /**
     * Acknowledge an alert. Records the user and timestamp; subsequent
     * UI fetches will rank the alert below unacknowledged ones.
     */
    public function acknowledgeAlert(int $alertId): array
    {
        return $this->alerts->acknowledgeAlert($alertId);
    }

    /**
     * Global open-alert feed — every unacknowledged alert across ALL
     * batches (dashboard "at-risk" widget + facilities banner). Joined
     * with the batch + unit so staff can act without per-batch hops.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOpenAlerts(): array
    {
        return $this->alerts->listOpenAlerts();
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
        return $this->history->listBatches($unitId, $status, $cursor, $limit);
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

            ['grade' => $grade, 'maturity' => $maturity] = $this->assertGradedReleaseInput($input);

            $now = $this->support->utcNow();
            $notes = (string) ($input['notes'] ?? '');
            $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
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
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', (int) $batch['unit_id'])
                ->update(['status' => BMG_STATE_IDLE, 'updated_at' => $now]);

            $this->audit->enqueue(
                'bmg.batch_released',
                'facilities_bmg_batches',
                $batchId,
                $userId,
                ['previous_status' => (string) $batch['status'], 'next_status' => BMG_STATE_RELEASED, 'quality_grade' => $grade, 'maturity_level' => $maturity],
            );

            $fresh = $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $batchId)->get()->getRowArray();
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
        return $this->analyticsReader->batchCompliance($batchId);
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
        return $this->analyticsReader->blendCn($batchId);
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
        return $this->analyticsReader->wasteCategoryDeviation();
    }
}

