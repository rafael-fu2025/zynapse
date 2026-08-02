<?php

declare(strict_types=1);

namespace Modules\Reports\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use DateTimeImmutable;
use DateTimeZone;
use Generator;

/**
 * Cross-module analytics reader.
 *
 * Calendar ranges are resolved in Asia/Manila and timestamp predicates use
 * UTC inclusive-start/exclusive-end bounds. Exports are aggregated,
 * privacy-safe, formula-hardened, and streamed from the database.
 */
final class ReportService extends BaseService
{
    public const MODULES = ['clinic', 'counselling', 'inventory', 'referrals', 'facilities'];
    public const MAX_EXPORT_ROWS = 50000;

    private readonly ReportRange $ranges;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null, ?ReportRange $ranges = null)
    {
        parent::__construct($db);
        $this->ranges = $ranges ?? new ReportRange();
    }

    /** @return array{start: string, end: string} */
    public function range(?string $start, ?string $end): array
    {
        return $this->ranges->resolve($start, $end);
    }

    /**
     * @param array{start: string, end: string} $range
     * @return array<string, mixed>
     */
    public function summary(array $range): array
    {
        $previous = $this->ranges->previous($range);
        $currentBounds = $this->ranges->timestampBounds($range);
        $previousBounds = $this->ranges->timestampBounds($previous);

        $clinic = $this->summaryCounts(
            'clinic_encounters',
            'created_at',
            $currentBounds,
            $previousBounds,
            ['archived_at' => null],
        );
        $checkins = $this->summaryCounts('clinic_checkins', 'scanned_at', $currentBounds, $previousBounds);
        $appointments = $this->dateSummaryCounts('counselling_appointments', 'appointment_date', $range, $previous);
        $sessions = $this->summaryCounts('counselling_sessions', 'started_at', $currentBounds, $previousBounds);
        $dispensed = $this->sumSummaryCounts(
            'clinic_medicine_transactions',
            'quantity',
            'created_at',
            $currentBounds,
            $previousBounds,
            ['type' => 'dispensed'],
        );
        $referrals = $this->summaryCounts(
            'referral_referrals',
            'created_at',
            $currentBounds,
            $previousBounds,
            ['archived_at' => null],
        );
        $facilities = $this->summaryCounts(
            'facilities_bmg_batches',
            'finished_at',
            $currentBounds,
            $previousBounds,
            ['archived_at' => null],
        );

        return [
            'range' => $range,
            'previous_range' => $previous,
            'snapshot_at' => $this->utcNow(),
            'clinic' => [
                'encounters' => $clinic['current'],
                'previous_encounters' => $clinic['previous'],
                'encounters_delta_pct' => $this->deltaPct($clinic['current'], $clinic['previous']),
                'checkins' => $checkins['current'],
            ],
            'counselling' => [
                'appointments' => $appointments['current'],
                'previous_appointments' => $appointments['previous'],
                'appointments_delta_pct' => $this->deltaPct($appointments['current'], $appointments['previous']),
                'sessions' => $sessions['current'],
            ],
            'inventory' => [
                'active_batches' => $this->countWhere('clinic_medicine_batches', [
                    'status' => 'active',
                    'quantity_remaining >' => 0,
                ]),
                'dispensed_qty' => $dispensed['current'],
                'previous_dispensed_qty' => $dispensed['previous'],
                'dispensed_delta_pct' => $this->deltaPct($dispensed['current'], $dispensed['previous']),
            ],
            'referrals' => [
                'created' => $referrals['current'],
                'previous_created' => $referrals['previous'],
                'created_delta_pct' => $this->deltaPct($referrals['current'], $referrals['previous']),
            ],
            'facilities' => [
                'completed_batches' => $facilities['current'],
                'previous_completed_batches' => $facilities['previous'],
                'completed_delta_pct' => $this->deltaPct($facilities['current'], $facilities['previous']),
            ],
        ];
    }

    /** @param array{start: string, end: string} $range */
    public function clinic(array $range): array
    {
        $bounds = $this->ranges->timestampBounds($range);

        $statusBreakdown = $this->timestampBuilder('clinic_encounters', 'created_at', $bounds)
            ->select('status, COUNT(*) AS cnt')
            ->where('archived_at', null)
            ->groupBy('status')->orderBy('cnt', 'DESC')->get()->getResultArray();

        $dailyTrend = $this->timestampBuilder('clinic_encounters', 'created_at', $bounds)
            ->select('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) AS day, COUNT(*) AS cnt', false)
            ->where('archived_at', null)
            ->groupBy('day')->orderBy('day', 'ASC')->get()->getResultArray();

        // Privacy-safe categories replace raw free-text chief complaints.
        $complaintCategories = $this->timestampBuilder('clinic_encounters', 'created_at', $bounds)
            ->select(
                "CASE
                    WHEN LOWER(chief_complaint) REGEXP 'cough|cold|flu|asthma|breath|throat' THEN 'Respiratory'
                    WHEN LOWER(chief_complaint) REGEXP 'pain|injur|wound|sprain|fracture' THEN 'Pain or injury'
                    WHEN LOWER(chief_complaint) REGEXP 'stomach|abdom|nausea|vomit|diarr' THEN 'Digestive'
                    WHEN LOWER(chief_complaint) REGEXP 'fever|infection' THEN 'Fever or infection'
                    WHEN LOWER(chief_complaint) REGEXP 'check.?up|clearance|routine' THEN 'Routine care'
                    ELSE 'Other recorded concern'
                 END AS category, COUNT(*) AS cnt",
                false,
            )
            ->where('archived_at', null)
            ->groupBy('category')->orderBy('cnt', 'DESC')->get()->getResultArray();

        $checkinOutcomes = $this->timestampBuilder('clinic_checkins', 'scanned_at', $bounds)
            ->select('outcome, COUNT(*) AS cnt')
            ->groupBy('outcome')->orderBy('cnt', 'DESC')->get()->getResultArray();

        $referralFlows = $this->timestampBuilder('referral_referrals', 'created_at', $bounds)
            ->select('source_module, target_module, status, COUNT(*) AS cnt')
            ->where('archived_at', null)
            ->groupBy('source_module, target_module, status')
            ->orderBy('cnt', 'DESC')->get()->getResultArray();

        return [
            'range' => $range,
            'total_encounters' => array_sum(array_map(static fn (array $r): int => (int) $r['cnt'], $statusBreakdown)),
            'status_breakdown' => $this->intify($statusBreakdown),
            'daily_trend' => $this->intify($dailyTrend),
            'complaint_categories' => $this->intify($complaintCategories),
            'checkin_outcomes' => $this->intify($checkinOutcomes),
            'referral_flows' => $this->intify($referralFlows),
        ];
    }

    /** @param array{start: string, end: string} $range */
    public function counselling(array $range): array
    {
        $bounds = $this->ranges->timestampBounds($range);

        $statusBreakdown = $this->db->table('counselling_appointments')
            ->select('status, COUNT(*) AS cnt')
            ->where('appointment_date >=', $range['start'])
            ->where('appointment_date <=', $range['end'])
            ->groupBy('status')->orderBy('cnt', 'DESC')->get()->getResultArray();

        $typeBreakdown = $this->db->table('counselling_appointments')
            ->select('type, COUNT(*) AS cnt')
            ->where('appointment_date >=', $range['start'])
            ->where('appointment_date <=', $range['end'])
            ->groupBy('type')->orderBy('cnt', 'DESC')->get()->getResultArray();

        $dailyTrend = $this->db->table('counselling_appointments')
            ->select('appointment_date AS day, COUNT(*) AS cnt')
            ->where('appointment_date >=', $range['start'])
            ->where('appointment_date <=', $range['end'])
            ->groupBy('day')->orderBy('day', 'ASC')->get()->getResultArray();

        $total = array_sum(array_map(static fn (array $r): int => (int) $r['cnt'], $statusBreakdown));
        $noShow = 0;
        foreach ($statusBreakdown as $row) {
            if ((string) $row['status'] === 'no_show') {
                $noShow = (int) $row['cnt'];
            }
        }

        return [
            'range' => $range,
            'total_appointments' => $total,
            'status_breakdown' => $this->intify($statusBreakdown),
            'type_breakdown' => $this->intify($typeBreakdown),
            'daily_trend' => $this->intify($dailyTrend),
            'no_show_count' => $noShow,
            'no_show_rate' => $total > 0 ? round(($noShow / $total) * 100, 1) : 0.0,
            'sessions_opened' => $this->countTimestampWhere('counselling_sessions', 'started_at', $bounds),
        ];
    }

    /** @param array{start: string, end: string} $range */
    public function inventory(array $range): array
    {
        $bounds = $this->ranges->timestampBounds($range);
        $today = (new DateTimeImmutable('today', new DateTimeZone(ReportRange::APP_TIMEZONE)))->format('Y-m-d');
        $horizon = (new DateTimeImmutable($today, new DateTimeZone(ReportRange::APP_TIMEZONE)))
            ->modify('+90 days')->format('Y-m-d');

        $lowStock = $this->db->table('clinic_medicines m')
            ->select('m.id, m.generic_name, m.brand_name, m.unit, m.reorder_threshold, COALESCE(SUM(b.quantity_remaining), 0) AS total_stock', false)
            ->join('clinic_medicine_batches b', "b.medicine_id = m.id AND b.status = 'active'", 'left', false)
            ->where('m.archived_at', null)
            ->groupBy('m.id')
            ->having('total_stock <= m.reorder_threshold', null, false)
            ->orderBy('total_stock', 'ASC')->get()->getResultArray();

        $expired = $this->db->table('clinic_medicine_batches b')
            ->select('b.id, b.batch_number, b.quantity_remaining, b.expiration_date, m.generic_name, m.brand_name, m.unit')
            ->join('clinic_medicines m', 'm.id = b.medicine_id')
            ->where('b.status', 'active')->where('b.quantity_remaining >', 0)
            ->where('b.expiration_date <', $today)
            ->orderBy('b.expiration_date', 'ASC')->get()->getResultArray();

        $expiring = $this->db->table('clinic_medicine_batches b')
            ->select('b.id, b.batch_number, b.quantity_remaining, b.expiration_date, m.generic_name, m.brand_name, m.unit')
            ->join('clinic_medicines m', 'm.id = b.medicine_id')
            ->where('b.status', 'active')->where('b.quantity_remaining >', 0)
            ->where('b.expiration_date >=', $today)
            ->where('b.expiration_date <=', $horizon)
            ->orderBy('b.expiration_date', 'ASC')->get()->getResultArray();

        $dispensingTrend = $this->timestampBuilder('clinic_medicine_transactions', 'created_at', $bounds)
            ->select('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) AS day, SUM(quantity) AS qty', false)
            ->where('type', 'dispensed')
            ->groupBy('day')->orderBy('day', 'ASC')->get()->getResultArray();

        $topDispensed = $this->timestampBuilder('clinic_medicine_transactions t', 't.created_at', $bounds)
            ->select('m.generic_name, m.brand_name, m.unit, SUM(t.quantity) AS qty', false)
            ->join('clinic_medicines m', 'm.id = t.medicine_id')
            ->where('t.type', 'dispensed')
            ->groupBy('m.id')->orderBy('qty', 'DESC')->limit(8)->get()->getResultArray();

        return [
            'range' => $range,
            'snapshot_at' => $this->utcNow(),
            'total_medicines' => $this->countWhere('clinic_medicines', ['archived_at' => null]),
            'low_stock' => $this->intify($lowStock),
            'expired' => $this->intify($expired),
            'expiring' => $this->intify($expiring),
            'dispensing_trend' => $this->intify($dispensingTrend),
            'total_dispensed' => array_sum(array_map(static fn (array $r): int => (int) $r['qty'], $dispensingTrend)),
            'top_dispensed' => $this->intify($topDispensed),
        ];
    }

    /** @param array{start: string, end: string} $range */
    public function referrals(array $range): array
    {
        $bounds = $this->ranges->timestampBounds($range);
        $status = $this->timestampBuilder('referral_referrals', 'created_at', $bounds)
            ->select('status, COUNT(*) AS cnt')->where('archived_at', null)
            ->groupBy('status')->orderBy('cnt', 'DESC')->get()->getResultArray();
        $flows = $this->timestampBuilder('referral_referrals', 'created_at', $bounds)
            ->select('source_module, target_module, COUNT(*) AS cnt')->where('archived_at', null)
            ->groupBy('source_module, target_module')->orderBy('cnt', 'DESC')->get()->getResultArray();
        $trend = $this->timestampBuilder('referral_referrals', 'created_at', $bounds)
            ->select('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) AS day, COUNT(*) AS cnt', false)
            ->where('archived_at', null)->groupBy('day')->orderBy('day', 'ASC')->get()->getResultArray();
        $total = array_sum(array_map(static fn (array $r): int => (int) $r['cnt'], $status));
        $closed = 0;
        foreach ($status as $row) {
            if ((string) $row['status'] === 'closed') {
                $closed = (int) $row['cnt'];
            }
        }

        return [
            'range' => $range,
            'total_referrals' => $total,
            'closed_count' => $closed,
            'closed_rate' => $total > 0 ? round(($closed / $total) * 100, 1) : 0.0,
            'status_breakdown' => $this->intify($status),
            'flow_breakdown' => $this->intify($flows),
            'daily_trend' => $this->intify($trend),
        ];
    }

    /** @param array{start: string, end: string} $range */
    public function facilities(array $range): array
    {
        $bounds = $this->ranges->timestampBounds($range);
        $status = $this->timestampBuilder('facilities_bmg_batches', 'started_at', $bounds)
            ->select('status, COUNT(*) AS cnt')->where('archived_at', null)
            ->groupBy('status')->orderBy('cnt', 'DESC')->get()->getResultArray();
        $trend = $this->timestampBuilder('facilities_bmg_batches', 'started_at', $bounds)
            ->select('DATE(DATE_ADD(started_at, INTERVAL 8 HOUR)) AS day, COUNT(*) AS cnt', false)
            ->where('archived_at', null)->groupBy('day')->orderBy('day', 'ASC')->get()->getResultArray();
        $mass = $this->timestampBuilder('facilities_bmg_batches', 'started_at', $bounds)
            ->select('COALESCE(SUM(total_input_weight_kg), 0) AS input_kg, COALESCE(SUM(output_weight_kg), 0) AS output_kg', false)
            ->where('archived_at', null)->get()->getRowArray() ?? [];
        $categories = $this->timestampBuilder('facilities_bmg_batches b', 'b.started_at', $bounds)
            ->select("COALESCE(c.name, 'Uncategorised') AS category, COUNT(*) AS cnt", false)
            ->join('facilities_waste_categories c', 'c.id = b.category_id', 'left')
            ->where('b.archived_at', null)->groupBy('category')->orderBy('cnt', 'DESC')->get()->getResultArray();
        $total = array_sum(array_map(static fn (array $r): int => (int) $r['cnt'], $status));
        $input = round((float) ($mass['input_kg'] ?? 0), 2);
        $output = round((float) ($mass['output_kg'] ?? 0), 2);

        return [
            'range' => $range,
            'total_batches' => $total,
            'completed_batches' => $this->countTimestampWhere(
                'facilities_bmg_batches',
                'finished_at',
                $bounds,
                ['archived_at' => null],
            ),
            'input_kg' => $input,
            'output_kg' => $output,
            'yield_rate' => $input > 0 ? round(($output / $input) * 100, 1) : 0.0,
            'status_breakdown' => $this->intify($status),
            'daily_trend' => $this->intify($trend),
            'category_breakdown' => $this->intify($categories),
        ];
    }

    /**
     * @param array{start: string, end: string} $range
     * @return array{0: array<int, string>, 1: iterable<int, array<int, mixed>>}
     */
    public function exportStream(string $module, array $range): array
    {
        if (! in_array($module, self::MODULES, true)) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Unknown report module.', 'field' => 'module'],
            ]);
        }

        $bounds = $this->ranges->timestampBounds($range);
        [$headers, $builder, $map] = match ($module) {
            'clinic' => [
                ['Date', 'Status', 'Encounter count'],
                $this->timestampBuilder('clinic_encounters', 'created_at', $bounds)
                    ->select('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) AS day, status, COUNT(*) AS cnt', false)
                    ->where('archived_at', null)->groupBy('day, status')->orderBy('day', 'ASC'),
                static fn (array $r): array => [$r['day'], $r['status'], (int) $r['cnt']],
            ],
            'counselling' => [
                ['Date', 'Type', 'Status', 'Appointment count'],
                $this->db->table('counselling_appointments')
                    ->select('appointment_date AS day, type, status, COUNT(*) AS cnt')
                    ->where('appointment_date >=', $range['start'])->where('appointment_date <=', $range['end'])
                    ->groupBy('day, type, status')->orderBy('day', 'ASC'),
                static fn (array $r): array => [$r['day'], $r['type'], $r['status'], (int) $r['cnt']],
            ],
            'inventory' => [
                ['Date', 'Transaction type', 'Medicine', 'Unit', 'Quantity'],
                $this->timestampBuilder('clinic_medicine_transactions t', 't.created_at', $bounds)
                    ->select('DATE(DATE_ADD(t.created_at, INTERVAL 8 HOUR)) AS day, t.type, m.generic_name, m.unit, SUM(t.quantity) AS qty', false)
                    ->join('clinic_medicines m', 'm.id = t.medicine_id')
                    ->groupBy('day, t.type, m.id')->orderBy('day', 'ASC'),
                static fn (array $r): array => [$r['day'], $r['type'], $r['generic_name'], $r['unit'], (int) $r['qty']],
            ],
            'referrals' => [
                ['Date', 'Source', 'Target', 'Status', 'Referral count'],
                $this->timestampBuilder('referral_referrals', 'created_at', $bounds)
                    ->select('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) AS day, source_module, target_module, status, COUNT(*) AS cnt', false)
                    ->where('archived_at', null)->groupBy('day, source_module, target_module, status')->orderBy('day', 'ASC'),
                static fn (array $r): array => [$r['day'], $r['source_module'], $r['target_module'], $r['status'], (int) $r['cnt']],
            ],
            'facilities' => [
                ['Date', 'Status', 'Input kg', 'Output kg', 'Batch count'],
                $this->timestampBuilder('facilities_bmg_batches', 'started_at', $bounds)
                    ->select('DATE(DATE_ADD(started_at, INTERVAL 8 HOUR)) AS day, status, SUM(total_input_weight_kg) AS input_kg, SUM(COALESCE(output_weight_kg, 0)) AS output_kg, COUNT(*) AS cnt', false)
                    ->where('archived_at', null)->groupBy('day, status')->orderBy('day', 'ASC'),
                static fn (array $r): array => [$r['day'], $r['status'], (float) $r['input_kg'], (float) $r['output_kg'], (int) $r['cnt']],
            ],
        };

        $query = $builder->limit(self::MAX_EXPORT_ROWS + 1)->get();
        $rows = $this->iterateExport($query, $map);

        return [$headers, $rows];
    }

    /**
     * Generate and persist a deterministic narrative.
     *
     * @param array{start: string, end: string} $range
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public function summarize(string $module, array $range, array $report, ?int $actorUserId = null): array
    {
        $data = $this->normalize($module, $report);
        $summarizer = new \App\Services\Analytics\ReportSummarizer();
        $narrative = $summarizer->generate($module, $range['start'], $range['end'], $data);
        $userId = $actorUserId ?? \App\Auth\CurrentUser::assert();
        $now = $this->utcNow();

        $this->db->table('report_summaries')->insert([
            'module' => $module,
            'period_start' => $range['start'],
            'period_end' => $range['end'],
            'input_data' => json_encode($data, JSON_THROW_ON_ERROR),
            'generated_summary' => $narrative,
            'generation_method' => \App\Services\Analytics\ReportSummarizer::METHOD,
            'model_used' => \App\Services\Analytics\ReportSummarizer::MODEL,
            'generated_by_user_id' => $userId,
            'created_at' => $now,
        ]);
        $id = (int) $this->db->insertID();

        \Config\Services::auditOutbox()->enqueue(
            'reports.summarized',
            'report_summaries',
            $id,
            $userId,
            ['resource_code' => $module . ':' . $range['start'] . '..' . $range['end']],
        );

        return [
            'narrative' => $narrative,
            'generation_method' => \App\Services\Analytics\ReportSummarizer::METHOD,
            'model_used' => \App\Services\Analytics\ReportSummarizer::MODEL,
            'generated_at' => $now,
            'range' => $range,
        ];
    }

    /** @param array<string, mixed> $report @return array<string, int|string> */
    private function normalize(string $module, array $report): array
    {
        return match ($module) {
            'clinic' => [
                'total' => (int) ($report['total_encounters'] ?? 0),
                'referrals' => array_sum(array_map(
                    static fn (array $r): int => (int) ($r['cnt'] ?? 0),
                    $report['referral_flows'] ?? [],
                )),
            ],
            'counselling' => [
                'total' => (int) ($report['total_appointments'] ?? 0),
                'no_shows' => (int) ($report['no_show_count'] ?? 0),
                'sessions' => (int) ($report['sessions_opened'] ?? 0),
            ],
            'inventory' => [
                'total_medicines' => (int) ($report['total_medicines'] ?? 0),
                'low_stock' => count($report['low_stock'] ?? []),
                'expired' => count($report['expired'] ?? []),
                'expiring' => count($report['expiring'] ?? []),
                'dispensed' => (int) ($report['total_dispensed'] ?? 0),
            ],
            'referrals' => [
                'total' => (int) ($report['total_referrals'] ?? 0),
                'closed' => (int) ($report['closed_count'] ?? 0),
            ],
            'facilities' => [
                'total' => (int) ($report['total_batches'] ?? 0),
                'completed' => (int) ($report['completed_batches'] ?? 0),
                'input_kg' => (string) ($report['input_kg'] ?? 0),
                'output_kg' => (string) ($report['output_kg'] ?? 0),
            ],
        };
    }

    /** @return Generator<int, array<int, mixed>> */
    private function iterateExport(\CodeIgniter\Database\ResultInterface $query, callable $map): Generator
    {
        $count = 0;
        try {
            while (($row = $query->getUnbufferedRow('array')) !== null) {
                $count++;
                if ($count > self::MAX_EXPORT_ROWS) {
                    throw ApiException::conflict(
                        'reports.export_too_large',
                        'Export exceeds the ' . self::MAX_EXPORT_ROWS . '-row limit. Choose a shorter range.',
                    );
                }
                yield ReportExportPolicy::sanitizeRow($map($row));
            }
        } finally {
            $query->freeResult();
        }
    }

    /**
     * @param array{start_utc: string, end_utc_exclusive: string} $bounds
     */
    private function timestampBuilder(string $table, string $column, array $bounds): \CodeIgniter\Database\BaseBuilder
    {
        return $this->db->table($table)
            ->where($column . ' >=', $bounds['start_utc'])
            ->where($column . ' <', $bounds['end_utc_exclusive']);
    }

    /**
     * @param array{start_utc: string, end_utc_exclusive: string} $bounds
     * @param array<string, mixed> $where
     */
    private function countTimestampWhere(string $table, string $column, array $bounds, array $where = []): int
    {
        $builder = $this->timestampBuilder($table, $column, $bounds);
        foreach ($where as $key => $value) {
            $builder->where($key, $value);
        }
        return (int) $builder->countAllResults();
    }

    /**
     * @param array{start_utc: string, end_utc_exclusive: string} $current
     * @param array{start_utc: string, end_utc_exclusive: string} $previous
     * @param array<string, mixed> $where
     * @return array{current: int, previous: int}
     */
    private function summaryCounts(string $table, string $column, array $current, array $previous, array $where = []): array
    {
        return [
            'current' => $this->countTimestampWhere($table, $column, $current, $where),
            'previous' => $this->countTimestampWhere($table, $column, $previous, $where),
        ];
    }

    /**
     * @param array{start: string, end: string} $current
     * @param array{start: string, end: string} $previous
     * @return array{current: int, previous: int}
     */
    private function dateSummaryCounts(string $table, string $column, array $current, array $previous): array
    {
        $count = function (array $range) use ($table, $column): int {
            return (int) $this->db->table($table)
                ->where($column . ' >=', $range['start'])
                ->where($column . ' <=', $range['end'])
                ->countAllResults();
        };
        return ['current' => $count($current), 'previous' => $count($previous)];
    }

    /**
     * @param array{start_utc: string, end_utc_exclusive: string} $current
     * @param array{start_utc: string, end_utc_exclusive: string} $previous
     * @param array<string, mixed> $where
     * @return array{current: int, previous: int}
     */
    private function sumSummaryCounts(
        string $table,
        string $sumColumn,
        string $dateColumn,
        array $current,
        array $previous,
        array $where = [],
    ): array {
        $sum = function (array $bounds) use ($table, $sumColumn, $dateColumn, $where): int {
            $builder = $this->timestampBuilder($table, $dateColumn, $bounds)->selectSum($sumColumn, 'total');
            foreach ($where as $key => $value) {
                $builder->where($key, $value);
            }
            return (int) ($builder->get()->getRowArray()['total'] ?? 0);
        };
        return ['current' => $sum($current), 'previous' => $sum($previous)];
    }

    /** @param array<string, mixed> $where */
    private function countWhere(string $table, array $where): int
    {
        $builder = $this->db->table($table);
        foreach ($where as $column => $value) {
            $builder->where($column, $value);
        }
        return (int) $builder->countAllResults();
    }

    private function deltaPct(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return $current === 0 ? 0.0 : null;
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function intify(array $rows): array
    {
        return array_map(static function (array $row): array {
            foreach (['cnt', 'qty', 'id', 'total_stock', 'reorder_threshold', 'quantity_remaining'] as $column) {
                if (array_key_exists($column, $row) && $row[$column] !== null) {
                    $row[$column] = (int) $row[$column];
                }
            }
            return $row;
        }, $rows);
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
