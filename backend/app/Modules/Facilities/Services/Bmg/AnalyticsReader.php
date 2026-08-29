<?php

declare(strict_types=1);

namespace Modules\Facilities\Services\Bmg;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Analytics\BmgAnalytics;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Facilities\Policies\BmgPolicy;

/**
 * AnalyticsReader — READ-ONLY analytics cluster for the BMG module.
 *
 * Extracted verbatim from BmgService: the deterministic yield/ETA
 * analytics, the PFRP compliance certificate, the feedstock blend C:N
 * read, and the per-category deviation report. No transactions, no
 * audit rows, no writes — every method is a pure read over the
 * tenant-scoped queries (unchanged from the pre-extraction BmgService).
 */
final class AnalyticsReader extends BaseService
{
    public function __construct(
        ?BaseConnection $db,
        private readonly BmgPolicy $policy,
        private readonly BmgSupport $support,
        private readonly BmgAnalytics $analytics,
    ) {
        parent::__construct($db);
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
            // `c` is LEFT-JOINed: a bare c.tenant_id = X predicate turns
            // the join into an INNER JOIN and silently drops every batch
            // whose category_id is NULL. Keep NULL-category batches
            // visible (they belong to this tenant by construction — the
            // batch row itself is tenant-scoped above).
            ->groupStart()
                ->where('c.tenant_id', CurrentTenant::id())
                ->orWhere('c.id', null)
            ->groupEnd()
            ->whereIn('b.status', [BMG_STATE_PROCESSING, BMG_STATE_AWAITING_OUTPUT])
            ->orderBy('b.started_at', 'ASC')
            ->get()->getResultArray();

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $a     = $this->analytics;

        // Panel revision: the ETA is weighted by each drum's specific
        // waste mix — per-category expected days (historical average,
        // falling back to the manual reference) × weight ratio.
        $batchIds     = array_map(static fn (array $r): int => (int) $r['batch_id'], $rows);
        $compositions = $this->support->batchCompositions($batchIds);
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
        $expectedByCat = $this->support->expectedDaysByCategory($catIds);

        return array_map(function (array $r) use ($a, $today, $compositions, $expectedByCat): array {
            $startDate = substr((string) $r['started_at'], 0, 10);
            $refDays   = $r['reference_duration_days'] !== null ? (int) $r['reference_duration_days'] : 0;

            $comps    = $compositions[(int) $r['batch_id']] ?? [];
            $expDays  = $this->support->weightedExpectedDays($comps, $expectedByCat);
            if ($expDays === null && $r['category_id'] !== null) {
                $expDays = $expectedByCat[(int) $r['category_id']]['expected_days'] ?? null;
            }
            $effDays = $expDays ?? ($refDays > 0 ? $refDays : 0);

            // expectedCompletionDate() falls back to the panel-approved baseline when neither
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

        $structuredIn  = (float) ($this->db->table('facilities_bmg_inputs')->where('facilities_bmg_inputs.tenant_id', CurrentTenant::id())->selectSum('weight_kg', 't')->where('batch_id', $batchId)->get()->getRowArray()['t'] ?? 0);
        $structuredOut = (float) ($this->db->table('facilities_bmg_outputs')->where('facilities_bmg_outputs.tenant_id', CurrentTenant::id())->selectSum('output_weight_kg', 't')->where('batch_id', $batchId)->get()->getRowArray()['t'] ?? 0);

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
                ->where('facilities_waste_categories.tenant_id', CurrentTenant::id())
                ->select('name, expected_yield_pct, reference_duration_days')
                ->where('id', (int) $batch['category_id'])->get()->getRowArray();
        }

        // Panel revision: mix-weighted expected duration + per-component
        // breakdown (weight ratios) for the drum detail screen.
        $comps         = $this->support->batchCompositions([$batchId])[$batchId] ?? [];
        $catIds        = array_map(static fn (array $c): int => $c['category_id'], $comps);
        if ($batch['category_id'] !== null) {
            $catIds[] = (int) $batch['category_id'];
        }
        $expectedByCat = $this->support->expectedDaysByCategory($catIds);
        $mixDays       = $this->support->weightedExpectedDays($comps, $expectedByCat);

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

        $a         = $this->analytics;
        $yield     = $a->computeYield($inputKg, $outputKg);
        $startDate = substr((string) $batch['started_at'], 0, 10);
        $refDays   = $category !== null && $category['reference_duration_days'] !== null ? (int) $category['reference_duration_days'] : 0;

        $expDays = $mixDays;
        if ($expDays === null && $batch['category_id'] !== null) {
            $expDays = $expectedByCat[(int) $batch['category_id']]['expected_days'] ?? null;
        }
        $effDays  = $expDays ?? $refDays;
        $expected = $a->expectedCompletionDate($startDate, $effDays);
        $today    = $this->support->utcNow();

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
            ->where('facilities_bmg_process_logs.tenant_id', CurrentTenant::id())
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
