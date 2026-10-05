<?php

declare(strict_types=1);

namespace Modules\Facilities\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\ManilaDay;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Analytics\BmgAnalytics;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use App\Services\Notify\NotificationOutboxService;
use Config\Services;
use DateInterval;
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
    /**
     * How long a breached SPC rule stays suppressed for a batch while an
     * earlier, unacknowledged alert for the same code is still open.
     * Acknowledging re-arms the rule immediately, so a genuine change in
     * condition still surfaces without waiting out the window.
     */
    private const ALERT_DEDUPE_WINDOW_HOURS = 24;

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
            ->select('u.id, u.code, u.display_name, u.status, u.location_code, u.spec_capacity_kg, u.default_category_id, u.notes, u.created_at, u.updated_at, u.archived_at, c.name AS default_category_name, b.id AS active_batch_id, b.total_input_weight_kg AS active_batch_weight_kg, b.started_at AS active_batch_started_at, b.expected_completion_date AS active_batch_expected_completion_date, d.id AS device_id, d.code AS device_code, d.display_name AS device_display_name, d.status AS device_status, d.last_seen_at AS device_last_seen_at')
            ->where('u.tenant_id', CurrentTenant::id())
            ->join(
                'facilities_bmg_batches AS b',
                "b.unit_id = u.id AND b.archived_at IS NULL AND b.status IN ('" . BMG_STATE_PROCESSING . "', '" . BMG_STATE_AWAITING_OUTPUT . "')",
                'left',
                false, // no identifier escaping — the ON clause carries quoted literals
            )
            ->join('facilities_waste_categories AS c', 'c.id = u.default_category_id', 'left')
            // The drum's integrated ESP32. `uq_bmg_devices_unit` keeps the
            // binding 1:1, so the join can never fan a unit row out.
            ->join('facilities_bmg_devices AS d', 'd.unit_id = u.id', 'left')
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

        // Attach each drum's designated waste categories in one grouped
        // query — the pivot cannot go into the main JOIN without fanning
        // rows out under keyset pagination.
        $unitIds = array_map(static fn (array $r): int => (int) $r['id'], $final['rows']);
        $catsByUnit = [];
        if ($unitIds !== []) {
            $pivotRows = $this->db->table('facilities_bmg_unit_categories AS uc')
                ->select('uc.unit_id, c.id AS category_id, c.name AS category_name')
                ->join('facilities_waste_categories AS c', 'c.id = uc.category_id', 'left')
                ->where('uc.tenant_id', CurrentTenant::id())
                ->whereIn('uc.unit_id', $unitIds)
                ->orderBy('uc.id', 'ASC')
                ->get()->getResultArray();
            foreach ($pivotRows as $pr) {
                if ($pr['category_id'] === null) {
                    continue;
                }
                $catsByUnit[(int) $pr['unit_id']][] = ['id' => (int) $pr['category_id'], 'name' => (string) $pr['category_name']];
            }
        }
        foreach ($final['rows'] as &$r) {
            $r['categories'] = $catsByUnit[(int) $r['id']] ?? [];
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

            // The unit is STARTED through its integrated ESP32: starting
            // a batch commands the drum's bound board to run. A drum
            // without a live board (replacement window) or with a
            // revoked one cannot start — the machine has no controller.
            $board = $this->db->table('facilities_bmg_devices')
                ->where('tenant_id', CurrentTenant::id())
                ->where('unit_id', $unitId)
                ->get()->getRowArray();
            if ($board === null || $board['archived_at'] !== null) {
                throw new ApiException('statemachine.bmg.unit_has_no_device', 409, [
                    ['code' => 'statemachine.bmg.unit_has_no_device', 'message' => 'This drum has no assigned ESP32 — assign one before starting a batch.'],
                ]);
            }
            if ($board['status'] !== 'active') {
                throw new ApiException('statemachine.bmg.unit_device_disabled', 409, [
                    ['code' => 'statemachine.bmg.unit_device_disabled', 'message' => "The drum's ESP32 ({$board['code']}) is disabled — re-enable it before starting a batch."],
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

            // The drum's waste mix is profiled at drum-configuration time:
            // when the drum designates categories, every composition
            // component must be one of them (the start form pre-seeds one
            // weight row per configured category). Unprofiled drums keep
            // the free-form mix.
            $designated = $this->unitCategoryIds($unitId);
            if ($designated !== [] && $composition !== []) {
                $allowed = array_fill_keys($designated, true);
                foreach ($composition as $component) {
                    if (! isset($allowed[(int) $component['category_id']])) {
                        throw new ApiException('validation.invalid', 422, [
                            ['code' => 'validation.invalid', 'message' => "Drum {$unit['code']} is designated for a fixed set of waste categories — category #{$component['category_id']} is not part of its mix.", 'field' => 'composition'],
                        ]);
                    }
                }
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

            // Tell the drum's ESP32 to start the unit — the command is
            // picked up on the board's next poll of the device surface.
            $this->enqueueDeviceCommand((int) $board['id'], (string) $board['code'], 'start', [
                'batch_id'              => $batchId,
                'batch_reference'       => $ref,
                'unit_code'             => (string) $unit['code'],
                'total_input_weight_kg' => $totalInputKg,
            ], $now, $userId);

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
     *   - `log` — free-form observation (temperature / turning / aeration /
     *     moisture / notes).
     *
     * The batch row's `output_weight_kg` / `status` are DENORMALIZED
     * aggregates kept in sync for fast reads, but the ledger row is the
     * source of truth and is never overwritten (append-only).
     *
     * Historical `curing` rows remain readable in the feed — that ledger
     * member predates the retirement of the state and is never rewritten.
     *
     * @param array{update_type:string, output_weight_kg?:float, event_type?:?string, observation_note?:?string, temperature_celsius?:?float, moisture_level?:?string} $input
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
        if (! in_array($type, ['output', 'log'], true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'update_type must be output or log.', 'field' => 'update_type'],
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

                // The unit mirrors the batch's phase — see the same
                // correction in `recordOutput`.
                if ($batch['status'] === BMG_STATE_PROCESSING) {
                    $this->db->table('facilities_bmg_units')
                        ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                        ->where('id', (int) $batch['unit_id'])
                        ->update(['status' => BMG_STATE_AWAITING_OUTPUT, 'updated_at' => $now]);
                }

                $this->audit->enqueue('bmg.output_recorded', 'facilities_bmg_batches', $batchId, $userId, [
                    'update_entry_id' => $entryId, 'output_weight_kg' => $kg, 'cumulative_kg' => $cumulative,
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
     * Combined, append-only "Updates" feed for a batch — output / log
     * entries ordered oldest → newest, plus any historical `curing` rows
     * written before that state was retired. Read-only.
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
     * stamps finished_at) and `releaseBatch` (awaiting_output only, no
     * output entry). They are deliberately SEPARATE operations
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

            // The unit mirrors the batch's phase. Without this the drum
            // keeps reporting `processing` while its batch is
            // `awaiting_output`, so the table badge reverts on the next
            // refetch and the dashboard's awaiting-output counter stays 0.
            $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', (int) $batch['unit_id'])
                ->update(['status' => BMG_STATE_AWAITING_OUTPUT, 'updated_at' => $now]);

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
            // awaiting_output) — e.g. the operator recorded a
            // process log confirming the desired output is being met, so
            // they can finalize the batch without forcing the intermediate
            // "record output" step. Only terminal/idle batches can't be
            // finished.
            if (! in_array($batch['status'], [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT], true)) {
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

    public function cancelBatch(int $batchId, string $reasonCode, string $note = ''): BmgBatchDto
    {
        $batch = $this->policy->loadBatchForOwnership($batchId);
        if ($batch === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "BMG batch #{$batchId} not found."],
            ]);
        }
        $this->policy->check('cancel', $batch);
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($batchId, $reasonCode, $note, $userId): BmgBatchDto {
            $batch = $this->selectForUpdate('facilities_bmg_batches', ['id' => $batchId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);

            if ($batch === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Batch #{$batchId} not found."],
                ]);
            }

            // `released` is terminal — the batch has left the system and
            // its unit is idle again; re-cancelling would corrupt the
            // state machine (2026-09 audit: only idle/cancelled were
            // blocked, so the API could flip a released batch).
            if (in_array($batch['status'], [BMG_STATE_IDLE, BMG_STATE_RELEASED, BMG_STATE_CANCELLED], true)) {
                throw StateMachineException::invalidTransition($batch['status'], BMG_STATE_CANCELLED, 'bmg');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            // The operator's free-text detail, when supplied, is folded
            // into the same `notes` ledger the reason code uses — the
            // reason code alone loses "why this run died" in a way the
            // history view can't show.
            $cancellation = 'cancel: ' . $reasonCode . ($note !== '' ? ' — ' . $note : '');

            $this->db->table('facilities_bmg_batches')
                ->where('facilities_bmg_batches.tenant_id', CurrentTenant::id())
                ->where('id', $batchId)
                ->update([
                    'status'       => BMG_STATE_CANCELLED,
                    'cancelled_at' => $now,
                    'notes'        => ($batch['notes'] ?? '') !== ''
                        ? (string) $batch['notes'] . ' | ' . $cancellation
                        : $cancellation,
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
     * Every drum integrates exactly ONE registered ESP32: `device_id`
     * is mandatory and must reference a registered, unarchived device
     * that no other drum holds. When every device in the registry is
     * already bound, drum creation is refused — register another board
     * first (the UI surfaces the same precondition before the form).
     *
     * The unit houses TWO drums: `drum_one_capacity_kg` +
     * `drum_two_capacity_kg` (each ≥ 4 kg) are stored individually and
     * `spec_capacity_kg` is RECOMPUTED as their sum — the batch input
     * ceiling and utilization read the sum. `category_ids` profiles the
     * waste categories the drum is designated for at creation time;
     * batches started on it may only mix from that set, and the first
     * selected category seeds `default_category_id` so the suggest
     * heuristic and batch fallback keep working.
     *
     * @param array{code:string, display_name:string, location_code?:?string, spec_capacity_kg?:?float, drum_one_capacity_kg?:?float, drum_two_capacity_kg?:?float, default_category_id?:?int, category_ids?:list<int>, notes?:?string, device_id:int} $input
     */
    public function createUnit(array $input): BmgUnitDto
    {
        $this->policy->check('manage_units');
        $userId = \App\Auth\CurrentUser::assert();

        $code = $this->support->assertSlug((string) $input['code'], 'code');

        $drums      = $this->resolveDrumCapacities($input);
        $categoryIds = $this->resolveCategoryIds($input['category_ids'] ?? null);
        $defaultCategoryId = $categoryIds !== []
            ? $categoryIds[0]
            : $this->resolveCategoryId($input['default_category_id'] ?? null);

        $deviceId = (int) ($input['device_id'] ?? 0);
        if ($deviceId <= 0) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'An ESP32 device is required — every drum integrates exactly one registered device.', 'field' => 'device_id'],
            ]);
        }

        return $this->txn(function () use ($input, $code, $defaultCategoryId, $categoryIds, $drums, $userId, $deviceId): BmgUnitDto {
            $device = $this->assertDeviceAvailable($deviceId);

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
                // The drums' sum wins over any client-supplied total.
                'spec_capacity_kg'    => $drums['sum'] ?? (isset($input['spec_capacity_kg']) && $input['spec_capacity_kg'] !== ''
                    ? (float) $input['spec_capacity_kg'] : null),
                'drum_one_capacity_kg' => $drums['one'],
                'drum_two_capacity_kg' => $drums['two'],
                'default_category_id' => $defaultCategoryId,
                'notes'               => isset($input['notes']) && $input['notes'] !== ''
                    ? (string) $input['notes'] : null,
                'archived_at'         => null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->bindDeviceToUnit($deviceId, $id);
            $this->syncUnitCategories($id, $categoryIds, $now);

            $this->audit->enqueue(
                'bmg.unit_created',
                'facilities_bmg_units',
                $id,
                $userId,
                ['resource_code' => $code, 'next_status' => BMG_STATE_IDLE, 'device_code' => (string) $device['code']],
            );

            $row = $this->db->table('facilities_bmg_units AS u')
                ->where('u.tenant_id', CurrentTenant::id())
                ->select('u.*, c.name AS default_category_name, d.id AS device_id, d.code AS device_code, d.display_name AS device_display_name, d.status AS device_status, d.last_seen_at AS device_last_seen_at')
                ->join('facilities_waste_categories AS c', 'c.id = u.default_category_id', 'left')
                ->join('facilities_bmg_devices AS d', 'd.unit_id = u.id', 'left')
                ->where('u.id', $id)
                ->get()->getRowArray();
            $row['categories'] = $this->unitCategories((int) $row['id']);
            return BmgUnitDto::fromRow($row);
        });
    }

    /**
     * Update a unit's mutable fields. `code` is immutable (matches the
     * legacy "Drum code cannot be changed" rule). The state machine
     * is owned elsewhere — this method refuses to mutate `status`.
     *
     * `device_id` (re)assigns the drum's integrated ESP32 after
     * creation: pass a registered, unbound device to swap the hardware
     * (the previous board is released back to the available pool), or
     * `null` to unbind (e.g. a dead board awaiting replacement). The
     * target device must not be held by another drum.
     *
     * `drum_one_capacity_kg` / `drum_two_capacity_kg` re-profile the
     * unit's two drums; both must be given together and `spec_capacity_kg`
     * is recomputed as their sum. `category_ids` replaces the drum's
     * designated waste-category set (first id seeds `default_category_id`).
     *
     * @param array{display_name?:string, location_code?:?string, spec_capacity_kg?:?float, drum_one_capacity_kg?:?float, drum_two_capacity_kg?:?float, default_category_id?:?int, category_ids?:list<int>, notes?:?string, device_id?:int|null} $input
     */
    public function updateUnit(int $unitId, array $input): BmgUnitDto
    {
        $this->policy->check('manage_units');
        $userId = \App\Auth\CurrentUser::assert();

        $drums = $this->resolveDrumCapacities($input);
        $categoryIds = array_key_exists('category_ids', $input)
            ? $this->resolveCategoryIds($input['category_ids'])
            : null;

        return $this->txn(function () use ($unitId, $input, $userId, $drums, $categoryIds): BmgUnitDto {
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
            if (array_key_exists('spec_capacity_kg', $input) && $drums['one'] === null) {
                $update['spec_capacity_kg'] = $input['spec_capacity_kg'] !== null && $input['spec_capacity_kg'] !== ''
                    ? (float) $input['spec_capacity_kg'] : null;
            }
            if ($drums['one'] !== null) {
                // Per-drum capacities win: the unit's rated capacity IS
                // the sum of its two drums.
                $update['drum_one_capacity_kg'] = $drums['one'];
                $update['drum_two_capacity_kg'] = $drums['two'];
                $update['spec_capacity_kg']     = $drums['sum'];
            }
            if (array_key_exists('default_category_id', $input)) {
                $update['default_category_id'] = $this->resolveCategoryId($input['default_category_id']);
            }
            if (array_key_exists('notes', $input)) {
                $update['notes'] = $input['notes'] !== null && $input['notes'] !== ''
                    ? (string) $input['notes'] : null;
            }

            $deviceOutcome = null;
            if (array_key_exists('device_id', $input)) {
                $deviceId = $input['device_id'] === null || $input['device_id'] === ''
                    ? null : (int) $input['device_id'];
                if ($deviceId !== null && $deviceId > 0) {
                    $this->assertDeviceAvailable($deviceId, $unitId);
                    $this->bindDeviceToUnit($deviceId, $unitId);
                    $deviceOutcome = 'device_assigned';
                } else {
                    $this->unbindUnitDevices($unitId);
                    $deviceOutcome = 'device_unbound';
                }
            }

            if ($categoryIds !== null) {
                $this->syncUnitCategories($unitId, $categoryIds, $this->support->utcNow());
                // The first selected category seeds the default so the
                // suggest heuristic and the batch category fallback keep
                // working unchanged.
                $update['default_category_id'] = $categoryIds[0] ?? null;
            }

            $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', $unitId)->update($update);

            $this->audit->enqueue(
                'bmg.unit_updated',
                'facilities_bmg_units',
                $unitId,
                $userId,
                [
                    'resource_code' => (string) $unit['code'],
                    ...($deviceOutcome !== null ? ['outcome' => $deviceOutcome] : []),
                ],
            );

            $fresh = $this->db->table('facilities_bmg_units AS u')
                ->where('u.tenant_id', CurrentTenant::id())
                ->select('u.*, c.name AS default_category_name, d.id AS device_id, d.code AS device_code, d.display_name AS device_display_name, d.status AS device_status, d.last_seen_at AS device_last_seen_at')
                ->join('facilities_waste_categories AS c', 'c.id = u.default_category_id', 'left')
                ->join('facilities_bmg_devices AS d', 'd.unit_id = u.id', 'left')
                ->where('u.id', $unitId)
                ->get()->getRowArray();
            $fresh['categories'] = $this->unitCategories($unitId);
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
     * Resolve a registered ESP32 for drum-side assignment. The device
     * must exist in this tenant, be unarchived, and be FREE — or, when
     * `$currentUnitId` is given, already be the drum's own board (the
     * assign-same-device no-op). Bound elsewhere → 409 naming the
     * holding drum, so the UI can point the operator at it.
     *
     * Must run inside the caller's transaction: the row is read FOR
     * UPDATE, so two drums racing on the same board serialize here and
     * the loser sees the binding. `uq_bmg_devices_unit` remains the
     * DB-level final guard.
     *
     * @return array<string, mixed> the locked device row
     */
    private function assertDeviceAvailable(int $deviceId, ?int $currentUnitId = null): array
    {
        $device = $this->selectForUpdate('facilities_bmg_devices', ['id' => $deviceId, 'tenant_id' => CurrentTenant::id()]);
        if ($device === null || $device['archived_at'] !== null) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'ESP32 device not found (or archived) for that id.', 'field' => 'device_id'],
            ]);
        }
        if ($device['unit_id'] !== null && (int) $device['unit_id'] !== (int) ($currentUnitId ?? -1)) {
            $holder = $this->db->table('facilities_bmg_units')
                ->select('code, display_name')
                ->where('id', (int) $device['unit_id'])
                ->where('tenant_id', CurrentTenant::id())
                ->get()->getRowArray();
            $held = $holder !== null
                ? "{$holder['display_name']} ({$holder['code']})"
                : "#{$device['unit_id']}";
            throw new ApiException('resource.conflict', 409, [
                ['code' => 'resource.conflict', 'message' => "ESP32 '{$device['code']}' is already bound to drum {$held}.", 'field' => 'device_id'],
            ]);
        }
        return $device;
    }

    /**
     * Point a device at a drum: release whatever board the drum
     * currently holds (the drum keeps exactly one), then bind the new
     * one. Caller holds the device lock (see `assertDeviceAvailable`).
     */
    private function bindDeviceToUnit(int $deviceId, int $unitId): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db->table('facilities_bmg_devices')
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->where('id !=', $deviceId)
            ->update(['unit_id' => null, 'updated_at' => $now]);
        $this->db->table('facilities_bmg_devices')
            ->where('tenant_id', CurrentTenant::id())
            ->where('id', $deviceId)
            ->update(['unit_id' => $unitId, 'updated_at' => $now]);
    }

    /**
     * Release every device bound to a drum (drum archived, or board
     * swapped out). The board returns to the available pool so another
     * drum can integrate it.
     */
    private function unbindUnitDevices(int $unitId): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db->table('facilities_bmg_devices')
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->update(['unit_id' => null, 'updated_at' => $now]);
    }

    /**
     * Resolve the two drums' capacities. A BMG unit houses TWO drums,
     * each holding at least 4 kg — the unit's rated capacity is their
     * SUM, so both values must arrive together (a lone drum capacity is
     * a client bug, not a half-filled form).
     *
     * @param array<string, mixed> $input
     * @return array{one:?float, two:?float, sum:?float}
     */
    private function resolveDrumCapacities(array $input): array
    {
        $one = isset($input['drum_one_capacity_kg']) && $input['drum_one_capacity_kg'] !== ''
            ? (float) $input['drum_one_capacity_kg'] : null;
        $two = isset($input['drum_two_capacity_kg']) && $input['drum_two_capacity_kg'] !== ''
            ? (float) $input['drum_two_capacity_kg'] : null;

        foreach (['drum_one_capacity_kg' => $one, 'drum_two_capacity_kg' => $two] as $field => $value) {
            if ($value !== null && $value < 4.0) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'Each drum holds at least 4 kg.', 'field' => $field],
                ]);
            }
        }
        if (($one === null) !== ($two === null)) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'Provide both drums\' capacities — the unit capacity is their sum.', 'field' => 'drum_one_capacity_kg'],
            ]);
        }

        return ['one' => $one, 'two' => $two, 'sum' => $one !== null ? $one + $two : null];
    }

    /**
     * Validate a drum's designated waste-category set (order preserved —
     * the first id seeds `default_category_id`). Returns [] for absent /
     * empty input; unknown ids 404 naming the first one.
     *
     * @return list<int>
     */
    private function resolveCategoryIds(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_array($raw)) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'category_ids must be an array of waste-category ids.', 'field' => 'category_ids'],
            ]);
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('facilities_waste_categories')
            ->select('id')
            ->where('tenant_id', CurrentTenant::id())
            ->whereIn('id', array_keys($ids))
            ->get()->getResultArray();
        $known = array_map(static fn ($v): int => (int) $v, array_column($rows, 'id'));
        $unknown = array_diff(array_keys($ids), $known);
        if ($unknown !== []) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => 'Waste category #' . reset($unknown) . ' not found.', 'field' => 'category_ids'],
            ]);
        }

        return array_values($ids);
    }

    /** Replace a drum's designated-category set (create / re-profile). */
    private function syncUnitCategories(int $unitId, array $categoryIds, string $now): void
    {
        $this->db->table('facilities_bmg_unit_categories')
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->delete();
        if ($categoryIds === []) {
            return;
        }
        $this->db->table('facilities_bmg_unit_categories')->insertBatch(array_map(
            static fn (int $categoryId): array => [
                'tenant_id'   => CurrentTenant::id(),
                'unit_id'     => $unitId,
                'category_id' => $categoryId,
                'created_at'  => $now,
            ],
            $categoryIds,
        ));
    }

    /**
     * The designated waste categories of one drum (id + name, in
     * configuration order).
     *
     * @return list<array{id:int, name:string}>
     */
    private function unitCategories(int $unitId): array
    {
        $rows = $this->db->table('facilities_bmg_unit_categories AS uc')
            ->select('c.id, c.name')
            ->join('facilities_waste_categories AS c', 'c.id = uc.category_id', 'left')
            ->where('uc.tenant_id', CurrentTenant::id())
            ->where('uc.unit_id', $unitId)
            ->orderBy('uc.id', 'ASC')
            ->get()->getResultArray();

        return array_values(array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            array_filter($rows, static fn (array $r): bool => $r['id'] !== null),
        ));
    }

    /**
     * Just the configured category ids of one drum — the allow-list
     * `startBatch` checks the composition against.
     *
     * @return list<int>
     */
    private function unitCategoryIds(int $unitId): array
    {
        $rows = $this->db->table('facilities_bmg_unit_categories')
            ->select('category_id')
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $unitId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): int => (int) $r['category_id'], $rows);
    }

    /**
     * Soft-archive a unit. Sets `archived_at`, refuses if the unit still
     * has an active batch (must be finished or cancelled first — same
     * invariant the legacy controller enforced by checking
     * `bmg_batches.status IN ('input','processing')`).
     *
     * The drum's ESP32 always leaves with it: by default the board is
     * released to the available pool, but with `relocate_device_to_unit_id`
     * it moves straight onto the named drum in the SAME transaction —
     * the archive and the rebinding land together or not at all. The
     * target must be a live drum whose device slot is free (this drum's
     * own slot is vacated by the archive, so the board can never move
     * back onto the drum being retired).
     *
     * @param array{relocate_device_to_unit_id?:int|null} $input
     */
    public function archiveUnit(int $unitId, array $input = []): BmgUnitDto
    {
        $this->policy->check('manage_units');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($unitId, $input, $userId): BmgUnitDto {
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

            $relocateTo = isset($input['relocate_device_to_unit_id']) && (int) $input['relocate_device_to_unit_id'] > 0
                ? (int) $input['relocate_device_to_unit_id'] : null;

            // The board leaving this drum — captured BEFORE the unbind so
            // the relocation below knows what it is moving.
            $board = $this->db->table('facilities_bmg_devices')
                ->where('tenant_id', CurrentTenant::id())
                ->where('unit_id', $unitId)
                ->get()->getRowArray();

            if ($relocateTo !== null && $board === null) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.invalid', 'message' => 'This drum has no assigned ESP32 to relocate.', 'field' => 'relocate_device_to_unit_id'],
                ]);
            }

            $now = $this->support->utcNow();
            $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', $unitId)->update([
                    'archived_at' => $now,
                    'updated_at'  => $now,
                ]);

            // The drum leaves service, but its ESP32 does not: release the
            // board back to the available pool — or straight onto the
            // replacement drum when one was named. Unarchiving does not
            // re-bind — assignment is an explicit act from the drum or
            // device screen.
            $this->unbindUnitDevices($unitId);

            $relocatedToCode = null;
            if ($relocateTo !== null && $board !== null) {
                $relocatedToCode = $this->relocateFreedDevice((int) $board['id'], (string) $board['code'], $unitId, $relocateTo);
            }

            $this->audit->enqueue(
                'bmg.unit_archived',
                'facilities_bmg_units',
                $unitId,
                $userId,
                [
                    'resource_code' => (string) $unit['code'],
                    'device_code'   => $board !== null ? (string) $board['code'] : null,
                    'device_relocated_to' => $relocatedToCode,
                ],
            );

            $fresh = $this->db->table('facilities_bmg_units')
                ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                ->where('id', $unitId)->get()->getRowArray();
            return BmgUnitDto::fromRow($fresh);
        });
    }

    /**
     * Move a just-freed board onto another drum inside the archiving
     * transaction. The target must be live (unarchived), different from
     * the drum being retired, and have no device of its own — a target
     * that already holds a board is a 409 naming that board, never a
     * silent steal.
     *
     * @return string the target drum's code, for the audit trail
     */
    private function relocateFreedDevice(int $deviceId, string $deviceCode, int $archivedUnitId, int $targetUnitId): string
    {
        $target = $this->selectForUpdate('facilities_bmg_units', ['id' => $targetUnitId, 'tenant_id' => CurrentTenant::id(), 'archived_at' => null]);
        if ($target === null) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'Relocation target not found (or archived).', 'field' => 'relocate_device_to_unit_id'],
            ]);
        }
        if ($targetUnitId === $archivedUnitId) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'The ESP32 cannot be relocated to the drum being archived.', 'field' => 'relocate_device_to_unit_id'],
            ]);
        }
        $occupant = $this->db->table('facilities_bmg_devices')
            ->select('code')
            ->where('tenant_id', CurrentTenant::id())
            ->where('unit_id', $targetUnitId)
            ->get()->getRowArray();
        if ($occupant !== null) {
            throw new ApiException('resource.conflict', 409, [
                ['code' => 'resource.conflict', 'message' => "Drum {$target['display_name']} ({$target['code']}) already has ESP32 '{$occupant['code']}' bound — pick another drum.", 'field' => 'relocate_device_to_unit_id'],
            ]);
        }

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db->table('facilities_bmg_devices')
            ->where('tenant_id', CurrentTenant::id())
            ->where('id', $deviceId)
            ->update(['unit_id' => $targetUnitId, 'updated_at' => $now]);

        return (string) $target['code'];
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
    /**
     * Active batches for the "Processing Drums" widget, wrapped with the
     * turning-cadence threshold the client would otherwise have to
     * hard-code and keep in sync with `BmgAlertEngine::TURNING_DUE_DAYS`.
     *
     * @return array{data: list<array<string, mixed>>, turning_due_days: int}
     */
    public function listActiveBatches(): array
    {
        return [
            'data'             => $this->analyticsReader->listActiveBatches(),
            'turning_due_days' => BmgAlertEngine::TURNING_DUE_DAYS,
        ];
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
            'session_uid'         => isset($r['session_uid']) && $r['session_uid'] !== null ? (string) $r['session_uid'] : null,
            'turns_count'         => isset($r['turns_count']) && $r['turns_count'] !== null ? (int) $r['turns_count'] : null,
            'duration_seconds'    => isset($r['duration_seconds']) && $r['duration_seconds'] !== null ? (int) $r['duration_seconds'] : null,
            'session_started_at'  => isset($r['session_started_at']) && $r['session_started_at'] !== null ? (string) $r['session_started_at'] : null,
            'session_ended_at'    => isset($r['session_ended_at']) && $r['session_ended_at'] !== null ? (string) $r['session_ended_at'] : null,
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
                // Day-grouping column: the Manila business calendar the
                // drum surface displays, not the UTC storage clock.
                'log_date'            => (string) ($input['log_date'] ?? ManilaDay::fromUtcSql($now)),
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

            $persistedAlerts = $row === null
                ? []
                : $this->persistAlertsForLog($batchId, $batch, $row, $previousLog ?: null, $userId, $now);

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

    // -------------------------------------------------- device ingest

    /**
     * Record a device-reported turning session against the ACTIVE batch
     * on the device's own unit.
     *
     * Unlike `addProcessLog` — whose caller is a human whose write right
     * is `started_by` ownership — the caller here is the drum's
     * mechanized tumbler. It can never be the batch starter, so its
     * record-level right comes from the device→unit binding: the unit
     * row is locked FOR UPDATE, which serializes device sessions against
     * concurrent batch state changes (start/finish/cancel take the same
     * lock). The `bmg_device` machine user bound by DeviceAuthFilter
     * supplies `recorded_by_user_id`, so the NOT NULL FK, the audit
     * chain, and the notification outbox all behave exactly as for a
     * human entry.
     *
     * Idempotent on (tenant_id, session_uid): a retried report (offline
     * queue replay, WiFi blip) returns the original row unchanged
     * (`created: false`) instead of duplicating it.
     *
     * @param array<string, mixed> $device row resolved by DeviceAuthFilter
     *        (expects keys: id, code, unit_id, linked_user_id)
     * @param array<string, mixed> $input validated payload (session_uid,
     *        turns_count, duration_seconds, optional sets_count, firmware, note,
     *        optional RTC epoch pair started_at_epoch/ended_at_epoch)
     * @return array<string, mixed> process-log DTO + `created: bool`
     */
    public function recordDeviceTurnSession(array $device, array $input): array
    {
        $this->policy->check('device_logs_record');
        $deviceId = (int) $device['id'];

        return $this->txn(function () use ($device, $deviceId, $input): array {
            // Re-read AND lock the device inside the txn: a concurrent
            // revoke must not race a session that is already in flight.
            $device = $this->selectForUpdate('facilities_bmg_devices', [
                'id'        => $deviceId,
                'tenant_id' => CurrentTenant::id(),
            ]);
            if ($device === null || $device['status'] !== 'active' || $device['archived_at'] !== null) {
                throw new ApiException('device.disabled', 403, [
                    ['code' => 'device.disabled', 'message' => 'This device has been disabled.'],
                ]);
            }

            // Idempotency first: a retried session must resolve to the
            // original row without touching the batch state machine.
            $sessionUid = (string) $input['session_uid'];
            $existing   = $this->db->table('facilities_bmg_process_logs')
                ->where('tenant_id', CurrentTenant::id())
                ->where('session_uid', $sessionUid)
                ->get()->getRowArray();
            if ($existing !== null) {
                return $this->deviceLogDto($existing, false);
            }

            $unitId = $device['unit_id'] !== null ? (int) $device['unit_id'] : null;
            if ($unitId === null) {
                throw new ApiException('device.not_bound', 409, [
                    ['code' => 'device.not_bound', 'message' => 'This device is not bound to a BMG unit.'],
                ]);
            }

            $unit = $this->selectForUpdate('facilities_bmg_units', [
                'id'          => $unitId,
                'tenant_id'   => CurrentTenant::id(),
                'archived_at' => null,
            ]);
            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'The bound BMG unit no longer exists.'],
                ]);
            }

            $batch = $this->db->table('facilities_bmg_batches')
                ->where('unit_id', $unitId)
                ->where('tenant_id', CurrentTenant::id())
                ->where('archived_at', null)
                ->whereIn('status', [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT])
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()->getRowArray();
            if ($batch === null) {
                throw new ApiException('statemachine.bmg.log_terminal_batch', 409, [
                    ['code' => 'statemachine.bmg.log_terminal_batch', 'message' => 'Process logs can only be added while a batch is active.'],
                ]);
            }

            $actorId  = (int) $device['linked_user_id'];
            $now      = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $turns    = (int) $input['turns_count'];
            $duration = (int) $input['duration_seconds'];
            $sets     = isset($input['sets_count']) && $input['sets_count'] !== '' ? (int) $input['sets_count'] : null;
            $note     = trim((string) ($input['observation_note'] ?? ''));

            // RTC-stamped session window. Sane = ended >= started, ended
            // no further than 7 days in the past (offline-queue horizon)
            // and no more than 10 min in the future (clock skew). Outside
            // the window — unsynced DS3231, tampered payload — fall back
            // to receipt time and leave the columns null. created_at
            // ALWAYS stays at receipt: that is the audit fact.
            $startedEpoch = isset($input['started_at_epoch']) && $input['started_at_epoch'] !== null ? (int) $input['started_at_epoch'] : null;
            $endedEpoch   = isset($input['ended_at_epoch']) && $input['ended_at_epoch'] !== null ? (int) $input['ended_at_epoch'] : null;
            $nowUnix      = time();
            $sessionStartedSql = null;
            $sessionEndedSql   = null;
            if ($startedEpoch !== null && $endedEpoch !== null
                && $endedEpoch >= $startedEpoch
                && $endedEpoch <= $nowUnix + 600
                && $endedEpoch >= $nowUnix - 604800) {
                $sessionStartedSql = gmdate('Y-m-d H:i:s', $startedEpoch);
                $sessionEndedSql   = gmdate('Y-m-d H:i:s', $endedEpoch);
            }
            $logDate = $sessionStartedSql !== null
                ? ManilaDay::fromUtcSql($sessionStartedSql)
                : ManilaDay::fromUtcSql($now);

            if ($note === '') {
                // Auto-summary from the device's own report — always
                // truthful to the payload, never to compile-time numbers.
                $note = $sets !== null
                    ? sprintf('Automated turning session: %d sets, %d rotations, %d s', $sets, $turns, $duration)
                    : sprintf('Automated turning session: %d rotations, %d s', $turns, $duration);
            }

            $this->db->table('facilities_bmg_process_logs')->insert([
                'batch_id'            => (int) $batch['id'],
                'tenant_id'           => CurrentTenant::id(),
                // Manila business calendar, same day-grouping as humans.
                // Uses the RTC-stamped session START when sane, so a
                // queued session delivered a day later still lands on
                // the day the drum actually turned.
                'log_date'            => $logDate,
                'event_type'          => 'turning',
                'observation_note'    => $note !== '' ? $note : null,
                'temperature_celsius' => null,
                'moisture_level'      => null,
                'oxygen_pct'          => null,
                'device_id'           => (string) $device['code'],
                'calibration_status'  => null,
                'session_uid'         => $sessionUid,
                'turns_count'         => $turns,
                'duration_seconds'    => $duration,
                'session_started_at'  => $sessionStartedSql,
                'session_ended_at'    => $sessionEndedSql,
                'recorded_by_user_id' => $actorId,
                'created_at'          => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'bmg.process_log_recorded',
                'facilities_bmg_process_logs',
                $id,
                $actorId,
                ['resource_code' => (string) $batch['reference_code']],
            );

            $deviceUpdate = [
                'last_seen_at' => $now,
                'updated_at'   => $now,
            ];
            $firmware = trim((string) ($input['firmware'] ?? ''));
            if ($firmware !== '') {
                $deviceUpdate['firmware'] = $firmware;
            }
            $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->update($deviceUpdate);

            $persistedAlerts = $this->evaluateAlertsForLog(
                (int) $batch['id'],
                $batch,
                $id,
                $actorId,
                $now,
            );

            return $this->deviceLogDto(
                $this->db->table('facilities_bmg_process_logs')
                    ->where('tenant_id', CurrentTenant::id())
                    ->where('id', $id)
                    ->get()->getRowArray() ?? [],
                true,
                $persistedAlerts,
            );
        });
    }

    /**
     * Days since the newest `turning` log for a batch, from the
     * caller's perspective AFTER inserting `$row`: 0 when the fresh row
     * is itself a turning (the cadence clock just reset), else the
     * day-diff to the previous newest turning — `null` when the batch
     * has never been turned (the engine then measures from batch start).
     *
     * @param array<string, mixed> $row freshly inserted process-log row
     */
    private function daysSinceLastTurning(int $batchId, array $row): ?int
    {
        if ((string) ($row['event_type'] ?? '') === 'turning') {
            return 0;
        }

        $last = $this->db->table('facilities_bmg_process_logs')
            ->select('log_date')
            ->where('batch_id', $batchId)
            ->where('tenant_id', CurrentTenant::id())
            ->where('event_type', 'turning')
            ->orderBy('log_date', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        return $last === null ? null : $this->alertEngine->daysSinceDate((string) $last['log_date']);
    }

    /**
     * Device-path entry point: resolves the freshly-inserted log row and
     * its predecessor, then defers to the shared alert path below.
     *
     * @param array<string, mixed> $batch locked batch row
     * @return list<array<string, mixed>> persisted alert DTOs
     */
    private function evaluateAlertsForLog(int $batchId, array $batch, int $id, int $actorId, string $now): array
    {
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
        if ($row === null) {
            return [];
        }

        return $this->persistAlertsForLog($batchId, $batch, $row, $previousLog ?: null, $actorId, $now);
    }

    /**
     * SPC evaluation + persistence for a freshly-inserted process-log row.
     * Shared by the human path (`addProcessLog`) and the device path
     * (`recordDeviceTurnSession`), so both get identical rules, identical
     * repeat suppression, and identical audit/notification side effects.
     *
     * Runs inside the caller's transaction so a rollback drops both the
     * log and its alerts. Staleness is measured against the PREVIOUS log
     * (the one just superseded); the engine sees the new row as `lastLog`.
     *
     * @param array<string, mixed> $batch        locked batch row
     * @param array<string, mixed> $row          the newly-inserted log row
     * @param array<string, mixed>|null $previousLog prior log's `log_date`, or null
     * @return list<array<string, mixed>> persisted alert DTOs
     */
    private function persistAlertsForLog(int $batchId, array $batch, array $row, ?array $previousLog, int $actorId, string $now): array
    {
        $daysSince = $this->alertEngine->daysSinceLastLog($previousLog);
        $daysSinceLastTurning = $this->daysSinceLastTurning($batchId, $row);
        $alerts = $this->alertEngine->evaluate(
            [
                'id'          => $batchId,
                'status'      => (string) $batch['status'],
                'started_at'  => (string) $batch['started_at'],
                'archived_at' => null,
            ],
            $row,
            $daysSince,
            $daysSinceLastTurning,
        );

        $persistedAlerts = [];
        foreach ($alerts as $alert) {
            $code = (string) $alert['code'];
            if ($this->hasUnackedAlertWithin($batchId, $code, $now)) {
                continue;
            }

            $this->db->table('facilities_bmg_alerts')->insert([
                'batch_id'      => $batchId,
                'tenant_id'     => CurrentTenant::id(),
                'code'          => $code,
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
                $actorId,
                [
                    'resource_code' => (string) $batch['reference_code'],
                    'alert_code'    => $code,
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
                'code'                    => $code,
                'severity'                => (string) $alert['severity'],
                'message'                 => (string) $alert['message'],
                'triggered_at'            => $now,
                'acknowledged_at'         => null,
                'acknowledged_by_user_id' => null,
            ])->toArray();
        }

        return $persistedAlerts;
    }

    /**
     * True when this batch already has an OPEN (unacknowledged) alert
     * for `$code` inside the dedupe window. An operator acknowledging
     * the alert re-arms the rule at once, so a condition that genuinely
     * changes still notifies on the very next observation.
     */
    private function hasUnackedAlertWithin(int $batchId, string $code, string $now): bool
    {
        $since = (new DateTimeImmutable($now, new DateTimeZone('UTC')))
            ->sub(new DateInterval('PT' . self::ALERT_DEDUPE_WINDOW_HOURS . 'H'))
            ->format('Y-m-d H:i:s');

        return $this->db->table('facilities_bmg_alerts')
            ->where('facilities_bmg_alerts.tenant_id', CurrentTenant::id())
            ->where('batch_id', $batchId)
            ->where('code', $code)
            ->where('acknowledged_at', null)
            // CI4 puts the operator in the key, not the third argument —
            // the CI3 three-arg form lands $since in ?bool $escape (fatal).
            ->where('triggered_at >=', $since)
            ->countAllResults() > 0;
    }

    /**
     * DTO for a process-log row on the device path. Adds the session
     * fields and, for the fresh-insert response, the persisted alerts.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function deviceLogDto(array $row, bool $created, array $alerts = []): array
    {
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
            'session_uid'         => $row['session_uid'] !== null ? (string) $row['session_uid'] : null,
            'turns_count'         => $row['turns_count'] !== null ? (int) $row['turns_count'] : null,
            'duration_seconds'    => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null,
            'session_started_at'  => $row['session_started_at'] !== null ? (string) $row['session_started_at'] : null,
            'session_ended_at'    => $row['session_ended_at'] !== null ? (string) $row['session_ended_at'] : null,
            'recorded_by_user_id' => (int) $row['recorded_by_user_id'],
            'created_at'          => (string) $row['created_at'],
            'created'             => $created,
            'alerts'              => $alerts,
        ];
    }

    // -------------------------------------------------- device admin

    /**
     * List registered BMG devices (with their bound unit's name).
     * Read-gated at units.read (operators may see devices); writes are
     * gated at units.manage (bmg_admin only) — mirrors the "operators
     * run the drums; the admin configures them" split.
     *
     * @return array{data: list<array<string, mixed>>, next: ?string, count: int}
     */
    public function listDevices(?string $cursor, int $limit, bool $includeArchived = false): array
    {
        $this->policy->check('devices_list');

        $builder = $this->db->table('facilities_bmg_devices AS d')
            ->select('d.id, d.code, d.display_name, d.status, d.unit_id, d.token_prefix, d.firmware, d.last_seen_at, d.silence_notified_at, d.created_at, d.updated_at, d.archived_at, u.display_name AS unit_name, u.code AS unit_code')
            ->join('facilities_bmg_units AS u', 'u.id = d.unit_id', 'left')
            ->where('d.tenant_id', CurrentTenant::id())
            ->orderBy('d.created_at', 'DESC')
            ->orderBy('d.id', 'DESC');

        if (! $includeArchived) {
            $builder->where('d.archived_at', null);
        }

        KeysetPaginator::apply($builder, $cursor, $limit, 'd.created_at', 'd.id');

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit, 'd.created_at');

        return [
            'data'  => array_map(static fn (array $r): array => [
                'id'           => (int) $r['id'],
                'code'         => (string) $r['code'],
                'display_name' => (string) $r['display_name'],
                'status'       => (string) $r['status'],
                'unit_id'      => $r['unit_id'] !== null ? (int) $r['unit_id'] : null,
                'unit_name'    => $r['unit_name'] !== null ? (string) $r['unit_name'] : null,
                'unit_code'    => $r['unit_code'] !== null ? (string) $r['unit_code'] : null,
                'token_prefix' => $r['token_prefix'] !== null ? (string) $r['token_prefix'] : null,
                'firmware'     => $r['firmware'] !== null ? (string) $r['firmware'] : null,
                'last_seen_at' => $r['last_seen_at'] !== null ? (string) $r['last_seen_at'] : null,
                // When the device-silence watchdog last flagged this
                // device, so the UI can keep a persistent "silent" signal
                // instead of relying on the one-shot notification.
                'silence_notified_at' => $r['silence_notified_at'] !== null ? (string) $r['silence_notified_at'] : null,
                'created_at'   => (string) $r['created_at'],
                'updated_at'   => (string) $r['updated_at'],
                'archived_at'  => $r['archived_at'] !== null ? (string) $r['archived_at'] : null,
            ], $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * Register a device and mint its ingest token. The plaintext token
     * is returned ONCE and only from this method and
     * `regenerateDeviceToken` — the UI/CLI must surface it immediately;
     * only the SHA-256 hash is stored.
     *
     * Accepts either `code` (slug) or `mac` (six hex byte pairs, any
     * separator) — the MAC normalizes to the default code. Also
     * provisions the machine user the device writes as. Idempotency is
     * a refusal: an existing code must not be silently rekeyed; use
     * `regenerateDeviceToken` explicitly.
     *
     * @param array{code?:string, mac?:string, display_name?:string, unit_id?:int|null} $input
     * @return array{device: array<string, mixed>, token: string}
     */
    public function registerDevice(array $input): array
    {
        $this->policy->check('devices_manage');

        return $this->registerDeviceUnchecked($input);
    }

    /**
     * CLI variant of `registerDevice` (`synapse:bmg-device-register`).
     * No acting user exists on the shell, so the permission gate lives
     * at the HTTP boundary — the same posture as PromoteSuperadmin
     * ("CLI bootstrap has no acting user"; audit actor stays null).
     *
     * @param array{code?:string, mac?:string, display_name?:string, unit_id?:int|null} $input
     * @return array{device: array<string, mixed>, token: string}
     */
    public function registerDeviceUnchecked(array $input): array
    {
        $code  = $this->normalizeDeviceCode($input);
        $name  = trim((string) ($input['display_name'] ?? ''));
        $unitId = isset($input['unit_id']) && (int) $input['unit_id'] > 0 ? (int) $input['unit_id'] : null;

        if ($unitId !== null) {
            $unit = $this->db->table('facilities_bmg_units')
                ->where('id', $unitId)
                ->where('tenant_id', CurrentTenant::id())
                ->where('archived_at', null)
                ->get()->getRowArray();
            if ($unit === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'BMG unit not found (or archived) for that id.'],
                ]);
            }
            // 1:1 — a drum integrates exactly one ESP32. Registering into
            // a drum that already holds a board must not silently steal
            // the binding.
            $holder = $this->db->table('facilities_bmg_devices')
                ->select('code')
                ->where('tenant_id', CurrentTenant::id())
                ->where('unit_id', $unitId)
                ->where('archived_at', null)
                ->get()->getRowArray();
            if ($holder !== null) {
                throw new ApiException('resource.conflict', 409, [
                    ['code' => 'resource.conflict', 'message' => "Drum {$unit['display_name']} ({$unit['code']}) already has ESP32 '{$holder['code']}' bound — reassign it from the drum instead.", 'field' => 'unit_id'],
                ]);
            }
        }

        $existing = $this->db->table('facilities_bmg_devices')->where('code', $code)->get()->getRowArray();
        if ($existing !== null) {
            throw new ApiException('resource.conflict', 409, [
                ['code' => 'resource.conflict', 'message' => "Device '{$code}' is already registered. Regenerate its token instead of re-registering."],
            ]);
        }

        return $this->txn(function () use ($code, $name, $unitId): array {
            $now  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $userId = $this->provisionDeviceMachineUser($code, $now);
            $token  = $this->mintDeviceToken();

            $this->db->table('facilities_bmg_devices')->insert([
                'tenant_id'      => CurrentTenant::id(),
                'unit_id'        => $unitId,
                'code'           => $code,
                'display_name'   => $name !== '' ? $name : 'BMG Device ' . $code,
                'token_hash'     => hash('sha256', $token),
                'token_prefix'   => substr($token, 0, 12),
                'status'         => 'active',
                'linked_user_id' => $userId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $deviceId = (int) $this->db->insertID();

            $this->audit->enqueue(
                'bmg.device_registered',
                'facilities_bmg_devices',
                $deviceId,
                null,
                ['resource_code' => $code, 'reason_code' => 'facilities.device.register', 'outcome' => 'registered'],
            );

            $row = $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->get()->getRowArray() ?? [];
            return ['device' => $this->deviceAdminDto($row), 'token' => $token];
        });
    }

    /**
     * Mint a NEW token for an existing device: the old one stops
     * working on the next request. The machine user is intentionally
     * NOT churned — historical process-log rows FK to it.
     *
     * @return array{device: array<string, mixed>, token: string}
     */
    public function regenerateDeviceToken(int $deviceId): array
    {
        $this->policy->check('devices_manage');

        return $this->regenerateDeviceTokenUnchecked($deviceId);
    }

    /** CLI variant — see `registerDeviceUnchecked` for the posture. */
    public function regenerateDeviceTokenUnchecked(int $deviceId): array
    {
        return $this->txn(function () use ($deviceId): array {
            $device = $this->selectForUpdate('facilities_bmg_devices', ['id' => $deviceId, 'tenant_id' => CurrentTenant::id()]);
            if ($device === null || $device['archived_at'] !== null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'Device not found.'],
                ]);
            }

            $now   = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $token = $this->mintDeviceToken();
            $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->update([
                'token_hash'   => hash('sha256', $token),
                'token_prefix' => substr($token, 0, 12),
                'status'       => 'active',
                'archived_at'  => null,
                'updated_at'   => $now,
            ]);

            $this->audit->enqueue(
                'bmg.device_registered',
                'facilities_bmg_devices',
                $deviceId,
                null,
                ['resource_code' => (string) $device['code'], 'reason_code' => 'facilities.device.regenerate', 'outcome' => 'token_regenerated'],
            );

            $row = $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->get()->getRowArray() ?? [];
            return ['device' => $this->deviceAdminDto($row), 'token' => $token];
        });
    }

    /**
     * Flip a device between active / disabled. Disabled devices fail
     * DeviceAuthFilter on their next request — revocation is instant.
     *
     * @return array<string, mixed> the updated device row
     */
    public function setDeviceStatus(int $deviceId, string $status): array
    {
        $this->policy->check('devices_manage');

        return $this->setDeviceStatusUnchecked($deviceId, $status);
    }

    /** CLI variant — see `registerDeviceUnchecked` for the posture. */
    public function setDeviceStatusUnchecked(int $deviceId, string $status): array
    {
        if (! in_array($status, ['active', 'disabled'], true)) {
            throw new ApiException('request.validation_failed', 422, [
                ['code' => 'request.validation_failed', 'message' => 'Status must be active or disabled.', 'field' => 'status'],
            ]);
        }

        return $this->txn(function () use ($deviceId, $status): array {
            $device = $this->selectForUpdate('facilities_bmg_devices', ['id' => $deviceId, 'tenant_id' => CurrentTenant::id()]);
            if ($device === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'Device not found.'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->update([
                'status'     => $status,
                'updated_at' => $now,
            ]);

            $this->audit->enqueue(
                'bmg.device_status_changed',
                'facilities_bmg_devices',
                $deviceId,
                null,
                ['resource_code' => (string) $device['code'], 'reason_code' => 'facilities.device.status', 'outcome' => $status],
            );

            $row = $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->get()->getRowArray() ?? [];
            return $this->deviceAdminDto($row);
        });
    }

    /**
     * Patch a device's mutable admin fields. Only `display_name` and the
     * drum binding are mutable — the `code` is the device's identity on
     * the wire (it is the `device_id` stamped onto every process log), and
     * the token is re-keyed through `regenerateDeviceToken`, never edited.
     *
     * Rebinding is the recovery path for a device flashed against the
     * wrong drum: without it, correcting the binding means re-registering
     * the device, which mints a new token and forces a re-flash.
     *
     * Passing `unit_id: null` unbinds. A bound drum that is archived is
     * rejected — an archived drum can never start a batch, so a device
     * bound to one would silently 409 on every report.
     *
     * @param array{display_name?:string, unit_id?:int|null} $input
     */
    public function updateDevice(int $deviceId, array $input): array
    {
        $this->policy->check('devices_manage');

        return $this->txn(function () use ($deviceId, $input): array {
            $device = $this->selectForUpdate('facilities_bmg_devices', ['id' => $deviceId, 'tenant_id' => CurrentTenant::id()]);
            if ($device === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'Device not found.'],
                ]);
            }

            $patch = [];

            if (array_key_exists('display_name', $input)) {
                $name = trim((string) ($input['display_name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 128) {
                    throw ApiException::validationFailure([
                        ['code' => 'validation.field', 'message' => 'Display name must be 1-128 characters.', 'field' => 'display_name'],
                    ]);
                }
                $patch['display_name'] = $name;
            }

            if (array_key_exists('unit_id', $input)) {
                $unitId = $input['unit_id'] === null || $input['unit_id'] === '' ? null : (int) $input['unit_id'];
                if ($unitId !== null) {
                    $unit = $this->db->table('facilities_bmg_units')
                        ->where('facilities_bmg_units.tenant_id', CurrentTenant::id())
                        ->where('id', $unitId)
                        ->where('archived_at', null)
                        ->get()->getRowArray();
                    if ($unit === null) {
                        throw ApiException::validationFailure([
                            ['code' => 'validation.field', 'message' => 'Bound drum not found, or is archived.', 'field' => 'unit_id'],
                        ]);
                    }
                    // 1:1 — the drum-side assignment owns swaps; rebinding
                    // here may not silently steal a board another drum
                    // already holds. Unbind that drum (or reassign from
                    // the drum screen) first.
                    $holder = $this->db->table('facilities_bmg_devices')
                        ->select('code')
                        ->where('tenant_id', CurrentTenant::id())
                        ->where('unit_id', $unitId)
                        ->where('id !=', $deviceId)
                        ->where('archived_at', null)
                        ->get()->getRowArray();
                    if ($holder !== null) {
                        throw new ApiException('resource.conflict', 409, [
                            ['code' => 'resource.conflict', 'message' => "Drum {$unit['display_name']} ({$unit['code']}) already has ESP32 '{$holder['code']}' bound — reassign it from the drum instead.", 'field' => 'unit_id'],
                        ]);
                    }
                }
                $patch['unit_id'] = $unitId;
            }

            if ($patch !== []) {
                $changed  = array_values(array_diff(array_keys($patch), ['updated_at']));
                $now      = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                $patch['updated_at'] = $now;
                $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->update($patch);

                $this->audit->enqueue(
                    'bmg.device_updated',
                    'facilities_bmg_devices',
                    $deviceId,
                    \App\Auth\CurrentUser::assert(),
                    [
                        'resource_code' => (string) $device['code'],
                        'reason_code'   => 'facilities.device.update',
                        'outcome'       => implode(',', $changed),
                    ],
                );
            }

            $row = $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->get()->getRowArray() ?? [];
            return $this->deviceAdminDto($row);
        });
    }

    /**
     * Soft-archive a device. The token hash stays on the row (so the
     * audit trail of which credential existed is preserved) but the
     * ingest filter refuses archived devices, so a decommissioned board
     * cannot report and cannot be silently rebound by mistake.
     *
     * Archiving also drops the drum binding: an archived device must not
     * keep a live drum pinned to it in the registry.
     */
    public function archiveDevice(int $deviceId): array
    {
        $this->policy->check('devices_manage');

        return $this->txn(function () use ($deviceId): array {
            $device = $this->selectForUpdate('facilities_bmg_devices', ['id' => $deviceId, 'tenant_id' => CurrentTenant::id()]);
            if ($device === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'Device not found.'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->update([
                'archived_at' => $now,
                'unit_id'     => null,
                'status'      => 'disabled',
                'updated_at'  => $now,
            ]);

            $this->audit->enqueue(
                'bmg.device_archived',
                'facilities_bmg_devices',
                $deviceId,
                \App\Auth\CurrentUser::assert(),
                ['resource_code' => (string) $device['code']],
            );

            $row = $this->db->table('facilities_bmg_devices')->where('id', $deviceId)->get()->getRowArray() ?? [];
            return $this->deviceAdminDto($row);
        });
    }

    /**
     * Device-silence watchdog (`synapse:bmg-device-watchdog`).
     *
     * For every ACTIVE device whose bound unit holds an ACTIVE batch:
     * when the device hasn't checked in (`last_seen_at`, falling back to
     * `created_at` for never-seen devices) for more than `$silenceHours`,
     * notify the logs.read audience — one notification per silence
     * episode, deduped via `silence_notified_at`: the marker re-arms
     * automatically the moment the device checks back in (last_seen
     * advances past it).
     *
     * Silence is NORMAL between sessions (the device only reports when a
     * session runs) — the threshold should exceed the turning cadence,
     * which is why the default is 48 h against a 4-day cadence.
     *
     * CLI variant: no acting user exists on the shell, so there is no
     * permission gate here (same posture as `registerDeviceUnchecked`);
     * the notify/audit fan-out is the system speaking.
     *
     * @return int devices notified this run
     */
    public function deviceWatchdogUnchecked(int $silenceHours): int
    {
        $nowUnix = time();
        $notified = 0;

        // Candidate devices: active, bound, tenant-scoped, unit holds an
        // active batch. The silence/dedupe decision is re-validated per
        // device under lock.
        $candidates = $this->db->table('facilities_bmg_devices AS d')
            ->select('d.id, d.code, d.last_seen_at, d.created_at, d.silence_notified_at')
            ->join('facilities_bmg_batches AS b', 'b.unit_id = d.unit_id AND b.archived_at IS NULL', 'inner', false)
            ->where('d.tenant_id', CurrentTenant::id())
            ->where('d.status', 'active')
            ->where('d.archived_at', null)
            ->where('d.unit_id IS NOT NULL', null, false)
            ->whereIn('b.status', [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT])
            ->get()->getResultArray();

        foreach ($candidates as $candidate) {
            try {
                $notified += $this->txn(function () use ($candidate, $silenceHours, $nowUnix): int {
                    $device = $this->selectForUpdate('facilities_bmg_devices', [
                        'id'        => (int) $candidate['id'],
                        'tenant_id' => CurrentTenant::id(),
                    ]);
                    if ($device === null || $device['status'] !== 'active' || $device['archived_at'] !== null) {
                        return 0;
                    }

                    $lastSeen = $device['last_seen_at'] ?? $device['created_at'];
                    if ($lastSeen === null) {
                        return 0;
                    }
                    $hoursSilent = ($nowUnix - (strtotime((string) $lastSeen) ?: $nowUnix)) / 3600;
                    if ($hoursSilent <= $silenceHours) {
                        return 0;
                    }

                    // One notification per silence episode: skip when we
                    // already notified AFTER the device's last check-in.
                    $notifiedAt = $device['silence_notified_at'] ?? null;
                    if ($notifiedAt !== null && (string) $notifiedAt >= (string) $lastSeen) {
                        return 0;
                    }

                    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                    $this->db->table('facilities_bmg_devices')->where('id', (int) $device['id'])->update([
                        'silence_notified_at' => $now,
                        'updated_at'          => $now,
                    ]);

                    $this->notify->enqueueToPermissions(
                        ['facilities.bmg.logs.read'],
                        'bmg.device_silent',
                        [
                            'resource_code' => (string) $device['code'],
                            'urgency'       => 'warning',
                            'source_module' => 'facilities',
                        ],
                    );
                    $this->audit->enqueue(
                        'bmg.device_silent',
                        'facilities_bmg_devices',
                        (int) $device['id'],
                        null,
                        [
                            'resource_code' => (string) $device['code'],
                            'reason_code'   => 'synapse:bmg-device-watchdog',
                            'outcome'       => sprintf('silent %.1fh', $hoursSilent),
                        ],
                    );

                    return 1;
                });
            } catch (\Throwable $e) {
                // One wedged device must not starve the rest of the sweep.
                log_message('warning', 'bmg device watchdog: device #{id} failed: {m}', [
                    'id' => (int) $candidate['id'],
                    'm'  => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Resolve the device code from an explicit slug or a MAC. The MAC
     * normalizes to dash-separated lowercase hex pairs
     * (b8-1f-3f-d7-ec-18), which fits VARCHAR(32).
     *
     * @param array{code?:string, mac?:string} $input
     */
    private function normalizeDeviceCode(array $input): string
    {
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        $mac  = trim((string) ($input['mac'] ?? ''));

        if ($code === '' && $mac === '') {
            throw new ApiException('request.validation_failed', 422, [
                ['code' => 'request.validation_failed', 'message' => 'Provide a MAC address or a device code.', 'field' => 'mac'],
            ]);
        }

        if ($code === '') {
            $pairs = preg_split('/[:\-\s]+/', strtolower($mac)) ?: [];
            $pairs = array_values(array_filter(array_map('trim', $pairs), static fn (string $p): bool => $p !== ''));
            $pairsAreHex = count($pairs) === 6 && in_array(
                false,
                array_map(static fn (string $p): bool => preg_match('/^[0-9a-f]{2}$/', $p) === 1, $pairs),
                true,
            ) === false;
            if (! $pairsAreHex) {
                throw new ApiException('request.validation_failed', 422, [
                    ['code' => 'request.validation_failed', 'message' => 'The MAC must be six hex byte pairs (e.g. b8:1f:3f:d7:ec:18).', 'field' => 'mac'],
                ]);
            }
            $code = implode('-', $pairs);
        }

        if (! preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $code)) {
            throw new ApiException('request.validation_failed', 422, [
                ['code' => 'request.validation_failed', 'message' => 'Device code must be 3–32 chars: lowercase letters, digits, dots, dashes, underscores.', 'field' => 'code'],
            ]);
        }

        return $code;
    }

    /**
     * Create (or reuse) the device's machine user in the `bmg_device`
     * group. The identity exists so every device write satisfies the
     * NOT NULL `recorded_by_user_id` FK, the audit chain, and the
     * notification outbox. Its password is random and never surfaced —
     * there is no interactive login path. Reused across token
     * regenerations so historical rows keep their attribution.
     */
    private function provisionDeviceMachineUser(string $code, string $now): int
    {
        $email = 'device.' . $code . '@devices.local';
        $existing = $this->db->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['user_id'];
        }

        $group = $this->db->table('auth_groups')->where('name', 'bmg_device')->get()->getRowArray();
        if ($group === null) {
            throw new ApiException('internal.error', 500, [
                ['code' => 'internal.error', 'message' => "The 'bmg_device' group is missing — run migrations and the PermissionsAndGroupsSeeder."],
            ]);
        }

        $this->db->table('users')->insert([
            'username'   => 'dev-' . preg_replace('/[^a-z0-9]/', '', $code),
            'status'     => 'active',
            'active'     => 1,
            'tenant_id'  => CurrentTenant::id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) $this->db->insertID();

        $this->db->table('auth_identities')->insert([
            'user_id'     => $userId,
            'type'        => 'email_password',
            'secret'      => $email,
            'secret2'     => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'force_reset' => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $this->db->table('auth_groups_users')->insert([
            'group_id'   => (int) $group['id'],
            'user_id'    => $userId,
            'created_at' => $now,
        ]);

        return $userId;
    }

    private function mintDeviceToken(): string
    {
        return 'dev_' . bin2hex(random_bytes(32));
    }

    /**
     * @param array<string, mixed> $row facilities_bmg_devices row (+ optional unit join fields)
     * @return array<string, mixed>
     */
    private function deviceAdminDto(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'code'         => (string) $row['code'],
            'display_name' => (string) $row['display_name'],
            'status'       => (string) $row['status'],
            'unit_id'      => $row['unit_id'] !== null ? (int) $row['unit_id'] : null,
            'unit_name'    => isset($row['unit_name']) && $row['unit_name'] !== null ? (string) $row['unit_name'] : null,
            'unit_code'    => isset($row['unit_code']) && $row['unit_code'] !== null ? (string) $row['unit_code'] : null,
            'token_prefix' => $row['token_prefix'] !== null ? (string) $row['token_prefix'] : null,
            'firmware'     => $row['firmware'] !== null ? (string) $row['firmware'] : null,
            'last_seen_at' => $row['last_seen_at'] !== null ? (string) $row['last_seen_at'] : null,
            'silence_notified_at' => isset($row['silence_notified_at']) && $row['silence_notified_at'] !== null ? (string) $row['silence_notified_at'] : null,
            'created_at'   => (string) $row['created_at'],
            'updated_at'   => (string) $row['updated_at'],
            'archived_at'  => $row['archived_at'] !== null ? (string) $row['archived_at'] : null,
        ];
    }

    // -------------------------------------------------- device commands

    /**
     * Queue an outbound command for a device (`start` at batch start
     * today; the table's CHECK admits `stop` for a later lifecycle
     * hook). Same transaction as the caller's state change, so the
     * command can never outlive the thing that produced it.
     */
    private function enqueueDeviceCommand(int $deviceId, string $deviceCode, string $command, array $payload, string $now, ?int $actorId): void
    {
        $this->db->table('facilities_bmg_device_commands')->insert([
            'tenant_id'  => CurrentTenant::id(),
            'device_id'  => $deviceId,
            'command'    => $command,
            'payload'    => json_encode($payload, JSON_THROW_ON_ERROR),
            'status'     => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $commandId = (int) $this->db->insertID();

        $this->audit->enqueue(
            'bmg.device_command_enqueued',
            'facilities_bmg_device_commands',
            $commandId,
            $actorId,
            [
                'resource_code' => $deviceCode . ':' . $command,
                'reason_code'   => 'facilities.device.command',
                'outcome'       => $command,
            ],
        );
    }

    /**
     * The device's pending command feed — the pull half of the start
     * integration (`GET /api/v1/devices/bmg/commands`, device-token
     * auth). Oldest first, capped per poll. Fetching stamps
     * `delivered_at` but leaves the row `pending` until the board ACKs:
     * at-least-once delivery, so a board that crashes mid-cycle
     * re-receives the command and must dedupe by id.
     *
     * @return list<array<string, mixed>>
     */
    public function listDeviceCommands(array $device): array
    {
        $rows = $this->db->table('facilities_bmg_device_commands')
            ->where('tenant_id', CurrentTenant::id())
            ->where('device_id', (int) $device['id'])
            ->where('status', 'pending')
            ->orderBy('id', 'ASC')
            ->limit(20)
            ->get()->getResultArray();

        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        if ($rows !== []) {
            // First delivery only — a redelivered row keeps its original
            // stamp so the watchdog can reason about staleness later.
            $this->db->table('facilities_bmg_device_commands')
                ->where('tenant_id', CurrentTenant::id())
                ->where('device_id', (int) $device['id'])
                ->where('status', 'pending')
                ->where('delivered_at', null)
                ->whereIn('id', array_map(static fn (array $r): int => (int) $r['id'], $rows))
                ->update(['delivered_at' => $now, 'updated_at' => $now]);
        }

        // Rows fetched before the stamp get the delivery time they were
        // just written with; already-delivered rows keep their stored one.
        return array_map(static fn (array $r): array => [
            'id'             => (int) $r['id'],
            'command'        => (string) $r['command'],
            'payload'        => $r['payload'] !== null
                ? json_decode((string) $r['payload'], true, 512, JSON_THROW_ON_ERROR)
                : null,
            'status'         => (string) $r['status'],
            'delivered_at'   => $r['delivered_at'] !== null ? (string) $r['delivered_at'] : $now,
            'created_at'     => (string) $r['created_at'],
        ], $rows);
    }

    /**
     * The board confirms it actuated a command (`POST
     * /api/v1/devices/bmg/commands/ack`). Idempotent: re-acking an
     * acknowledged command returns the row unchanged; a foreign or
     * unknown command id is a 404 — the device identity scopes every
     * lookup.
     *
     * @return array<string, mixed>
     */
    public function ackDeviceCommand(array $device, int $commandId): array
    {
        return $this->txn(function () use ($device, $commandId): array {
            $cmd = $this->selectForUpdate('facilities_bmg_device_commands', [
                'id'        => $commandId,
                'tenant_id' => CurrentTenant::id(),
                'device_id' => (int) $device['id'],
            ]);
            if ($cmd === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'Command not found for this device.'],
                ]);
            }

            if ($cmd['status'] === 'pending') {
                $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                $this->db->table('facilities_bmg_device_commands')
                    ->where('tenant_id', CurrentTenant::id())
                    ->where('id', $commandId)
                    ->update(['status' => 'acknowledged', 'acknowledged_at' => $now, 'updated_at' => $now]);

                $this->audit->enqueue(
                    'bmg.device_command_acknowledged',
                    'facilities_bmg_device_commands',
                    $commandId,
                    \App\Auth\CurrentUser::assert(),
                    ['resource_code' => (string) $cmd['command'], 'reason_code' => 'facilities.device.command.ack', 'outcome' => 'acknowledged'],
                );
                $cmd['status']          = 'acknowledged';
                $cmd['acknowledged_at'] = $now;
            }

            return [
                'id'              => (int) $cmd['id'],
                'command'         => (string) $cmd['command'],
                'status'          => (string) $cmd['status'],
                'acknowledged_at' => $cmd['acknowledged_at'] !== null ? (string) $cmd['acknowledged_at'] : null,
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
            // Strictly idle <-> maintenance. A drum with a live batch
            // cannot be parked — that would strand the batch and break
            // the generated `active_unit_id` uniqueness.
            $allowed = $maintenance ? [BMG_STATE_IDLE] : [BMG_STATE_MAINTENANCE];
            $next = $maintenance ? BMG_STATE_MAINTENANCE : BMG_STATE_IDLE;
            if (! in_array($current, $allowed, true)) {
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
     * compost leaves the system. Only an `awaiting_output` batch can be
     * released; the operator records a quality grade + maturity level
     * (the batch's "certificate" fields). Terminal state `released`; the
     * unit returns to Idle.
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
            if ($batch['status'] !== BMG_STATE_AWAITING_OUTPUT) {
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

