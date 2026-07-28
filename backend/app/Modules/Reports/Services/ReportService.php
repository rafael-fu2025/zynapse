<?php

declare(strict_types=1);

namespace Modules\Reports\Services;

use App\Modules\Shared\BaseService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * ReportService — read-only analytics (Phase 18, recycled from legacy
 * synapse_ag Reports\ReportController).
 *
 * Reporting is the ONE sanctioned cross-module READER: it aggregates
 * over clinic_*, counselling_* and referral_* tables but never joins
 * them to each other and never writes anything. All queries are
 * grouped under a validated [start, end] date range (legacy
 * getDateRange semantics: YYYY-MM-DD, swapped if inverted, defaulting
 * to the last 30 days).
 *
 * Adaptation from legacy: CSV exports carry NO patient_school_id —
 * the project treats it as sensitive in export surfaces (same policy
 * as the audit CSV redaction list).
 */
final class ReportService extends BaseService
{
    /**
     * @return array{start: string, end: string}
     */
    public function range(?string $start, ?string $end): array
    {
        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $endValid = is_string($end) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) === 1 ? $end : $today;
        $startValid = is_string($start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1
            ? $start
            : (new DateTimeImmutable($endValid))->modify('-30 days')->format('Y-m-d');

        if ($startValid > $endValid) {
            [$startValid, $endValid] = [$endValid, $startValid];
        }

        return ['start' => $startValid, 'end' => $endValid];
    }

    /**
     * Module picker KPIs (legacy reports landing page).
     *
     * @param array{start: string, end: string} $range
     * @return array<string, mixed>
     */
    public function summary(array $range): array
    {
        [$startDT, $endDT] = [$range['start'] . ' 00:00:00', $range['end'] . ' 23:59:59'];

        return [
            'range'  => $range,
            'clinic' => [
                'encounters' => $this->countWhere('clinic_encounters', [
                    'created_at >=' => $startDT, 'created_at <=' => $endDT, 'archived_at' => null,
                ]),
                'checkins' => $this->countWhere('clinic_checkins', [
                    'scanned_at >=' => $startDT, 'scanned_at <=' => $endDT,
                ]),
            ],
            'counselling' => [
                'appointments' => $this->countWhere('counselling_appointments', [
                    'appointment_date >=' => $range['start'], 'appointment_date <=' => $range['end'],
                ]),
                'sessions' => $this->countWhere('counselling_sessions', [
                    'started_at >=' => $startDT, 'started_at <=' => $endDT,
                ]),
            ],
            'inventory' => [
                'active_batches' => $this->countWhere('clinic_medicine_batches', [
                    'status' => 'active', 'quantity_remaining >' => 0,
                ]),
                'dispensed_qty' => $this->sumWhere('clinic_medicine_transactions', 'quantity', [
                    'type' => 'dispensed', 'created_at >=' => $startDT, 'created_at <=' => $endDT,
                ]),
            ],
            'referrals' => [
                'created' => $this->countWhere('referral_referrals', [
                    'created_at >=' => $startDT, 'created_at <=' => $endDT, 'archived_at' => null,
                ]),
            ],
        ];
    }

    /**
     * @param array{start: string, end: string} $range
     * @return array<string, mixed>
     */
    public function clinic(array $range): array
    {
        [$startDT, $endDT] = [$range['start'] . ' 00:00:00', $range['end'] . ' 23:59:59'];

        $statusBreakdown = $this->db->table('clinic_encounters')
            ->select('status, COUNT(*) AS cnt')
            ->where('created_at >=', $startDT)->where('created_at <=', $endDT)
            ->where('archived_at', null)
            ->groupBy('status')->get()->getResultArray();

        $dailyTrend = $this->db->table('clinic_encounters')
            ->select('DATE(created_at) AS day, COUNT(*) AS cnt', false)
            ->where('created_at >=', $startDT)->where('created_at <=', $endDT)
            ->where('archived_at', null)
            ->groupBy('day')->orderBy('day', 'ASC')->get()->getResultArray();

        $topComplaints = $this->db->table('clinic_encounters')
            ->select('chief_complaint, COUNT(*) AS cnt')
            ->where('created_at >=', $startDT)->where('created_at <=', $endDT)
            ->where('archived_at', null)
            ->groupBy('chief_complaint')->orderBy('cnt', 'DESC')->limit(8)
            ->get()->getResultArray();

        $checkinOutcomes = $this->db->table('clinic_checkins')
            ->select('outcome, COUNT(*) AS cnt')
            ->where('scanned_at >=', $startDT)->where('scanned_at <=', $endDT)
            ->groupBy('outcome')->get()->getResultArray();

        $referralFlows = $this->db->table('referral_referrals')
            ->select('source_module, target_module, status, COUNT(*) AS cnt')
            ->where('created_at >=', $startDT)->where('created_at <=', $endDT)
            ->where('archived_at', null)
            ->groupBy('source_module, target_module, status')->get()->getResultArray();

        return [
            'range'             => $range,
            'total_encounters'  => array_sum(array_map(static fn (array $r): int => (int) $r['cnt'], $statusBreakdown)),
            'status_breakdown'  => $this->intify($statusBreakdown),
            'daily_trend'       => $this->intify($dailyTrend),
            'top_complaints'    => $this->intify($topComplaints),
            'checkin_outcomes'  => $this->intify($checkinOutcomes),
            'referral_flows'    => $this->intify($referralFlows),
        ];
    }

    /**
     * @param array{start: string, end: string} $range
     * @return array<string, mixed>
     */
    public function counselling(array $range): array
    {
        [$startDT, $endDT] = [$range['start'] . ' 00:00:00', $range['end'] . ' 23:59:59'];

        $statusBreakdown = $this->db->table('counselling_appointments')
            ->select('status, COUNT(*) AS cnt')
            ->where('appointment_date >=', $range['start'])->where('appointment_date <=', $range['end'])
            ->groupBy('status')->get()->getResultArray();

        $typeBreakdown = $this->db->table('counselling_appointments')
            ->select('type, COUNT(*) AS cnt')
            ->where('appointment_date >=', $range['start'])->where('appointment_date <=', $range['end'])
            ->groupBy('type')->get()->getResultArray();

        $dailyTrend = $this->db->table('counselling_appointments')
            ->select('appointment_date AS day, COUNT(*) AS cnt')
            ->where('appointment_date >=', $range['start'])->where('appointment_date <=', $range['end'])
            ->groupBy('day')->orderBy('day', 'ASC')->get()->getResultArray();

        $total  = array_sum(array_map(static fn (array $r): int => (int) $r['cnt'], $statusBreakdown));
        $noShow = 0;
        foreach ($statusBreakdown as $s) {
            if ((string) $s['status'] === 'no_show') {
                $noShow = (int) $s['cnt'];
            }
        }

        return [
            'range'              => $range,
            'total_appointments' => $total,
            'status_breakdown'   => $this->intify($statusBreakdown),
            'type_breakdown'     => $this->intify($typeBreakdown),
            'daily_trend'        => $this->intify($dailyTrend),
            'no_show_count'      => $noShow,
            'no_show_rate'       => $total > 0 ? round(($noShow / $total) * 100, 1) : 0.0,
            'sessions_opened'    => $this->countWhere('counselling_sessions', [
                'started_at >=' => $startDT, 'started_at <=' => $endDT,
            ]),
        ];
    }

    /**
     * @param array{start: string, end: string} $range
     * @return array<string, mixed>
     */
    public function inventory(array $range): array
    {
        [$startDT, $endDT] = [$range['start'] . ' 00:00:00', $range['end'] . ' 23:59:59'];

        $totalMedicines = $this->countWhere('clinic_medicines', ['archived_at' => null]);

        // Low stock: SUM(active batch remaining) <= reorder_threshold.
        $lowStock = $this->db->table('clinic_medicines m')
            ->select('m.id, m.generic_name, m.brand_name, m.unit, m.reorder_threshold, COALESCE(SUM(b.quantity_remaining), 0) AS total_stock', false)
            ->join('clinic_medicine_batches b', "b.medicine_id = m.id AND b.status = 'active'", 'left', false)
            ->where('m.archived_at', null)
            ->groupBy('m.id')
            ->having('total_stock <= m.reorder_threshold', null, false)
            ->orderBy('total_stock', 'ASC')
            ->get()->getResultArray();

        // Expiring within 90 days (snapshot — legacy semantics).
        $horizon = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+90 days')->format('Y-m-d');
        $expiring = $this->db->table('clinic_medicine_batches b')
            ->select('b.id, b.batch_number, b.quantity_remaining, b.expiration_date, m.generic_name, m.brand_name, m.unit')
            ->join('clinic_medicines m', 'm.id = b.medicine_id')
            ->where('b.status', 'active')
            ->where('b.quantity_remaining >', 0)
            ->where('b.expiration_date <=', $horizon)
            ->orderBy('b.expiration_date', 'ASC')
            ->get()->getResultArray();

        $dispensingTrend = $this->db->table('clinic_medicine_transactions')
            ->select('DATE(created_at) AS day, SUM(quantity) AS qty', false)
            ->where('type', 'dispensed')
            ->where('created_at >=', $startDT)->where('created_at <=', $endDT)
            ->groupBy('day')->orderBy('day', 'ASC')->get()->getResultArray();

        $topDispensed = $this->db->table('clinic_medicine_transactions t')
            ->select('m.generic_name, m.brand_name, m.unit, SUM(t.quantity) AS qty', false)
            ->join('clinic_medicines m', 'm.id = t.medicine_id')
            ->where('t.type', 'dispensed')
            ->where('t.created_at >=', $startDT)->where('t.created_at <=', $endDT)
            ->groupBy('m.id')->orderBy('qty', 'DESC')->limit(8)
            ->get()->getResultArray();

        return [
            'range'            => $range,
            'total_medicines'  => $totalMedicines,
            'low_stock'        => $this->intify($lowStock),
            'expiring'         => $this->intify($expiring),
            'dispensing_trend' => $this->intify($dispensingTrend),
            'total_dispensed'  => array_sum(array_map(static fn (array $r): int => (int) $r['qty'], $dispensingTrend)),
            'top_dispensed'    => $this->intify($topDispensed),
        ];
    }

    /**
     * CSV rows per module. NO patient identifiers in export surfaces.
     *
     * @param array{start: string, end: string} $range
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    public function exportRows(string $module, array $range): array
    {
        [$startDT, $endDT] = [$range['start'] . ' 00:00:00', $range['end'] . ' 23:59:59'];

        if ($module === 'clinic') {
            $rows = $this->db->table('clinic_encounters')
                ->select('created_at, status, chief_complaint, closed_at')
                ->where('created_at >=', $startDT)->where('created_at <=', $endDT)
                ->where('archived_at', null)
                ->orderBy('created_at', 'ASC')->get()->getResultArray();

            return [
                ['Created', 'Status', 'Chief Complaint', 'Closed'],
                array_map(static fn (array $r): array => [
                    $r['created_at'], $r['status'], $r['chief_complaint'], $r['closed_at'] ?? '',
                ], $rows),
            ];
        }

        if ($module === 'counselling') {
            $rows = $this->db->table('counselling_appointments')
                ->select('appointment_date, start_time, end_time, type, status, cancellation_reason')
                ->where('appointment_date >=', $range['start'])->where('appointment_date <=', $range['end'])
                ->orderBy('appointment_date', 'ASC')->orderBy('start_time', 'ASC')
                ->get()->getResultArray();

            return [
                ['Date', 'Start', 'End', 'Type', 'Status', 'Cancellation Reason'],
                array_map(static fn (array $r): array => [
                    $r['appointment_date'], $r['start_time'], $r['end_time'], $r['type'], $r['status'], $r['cancellation_reason'] ?? '',
                ], $rows),
            ];
        }

        // inventory
        $rows = $this->db->table('clinic_medicine_transactions t')
            ->select('t.created_at, t.type, t.quantity, COALESCE(t.note, "") AS note, b.batch_number, m.generic_name, m.brand_name, m.unit', false)
            ->join('clinic_medicine_batches b', 'b.id = t.batch_id')
            ->join('clinic_medicines m', 'm.id = t.medicine_id')
            ->where('t.created_at >=', $startDT)->where('t.created_at <=', $endDT)
            ->orderBy('t.created_at', 'ASC')->get()->getResultArray();

        return [
            ['Date', 'Type', 'Quantity', 'Note', 'Batch', 'Generic Name', 'Brand', 'Unit'],
            array_map(static fn (array $r): array => [
                $r['created_at'], $r['type'], $r['quantity'], $r['note'], $r['batch_number'], $r['generic_name'], $r['brand_name'] ?? '', $r['unit'],
            ], $rows),
        ];
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $where
     */
    private function countWhere(string $table, array $where): int
    {
        $builder = $this->db->table($table);
        foreach ($where as $col => $val) {
            $builder->where($col, $val);
        }
        return (int) $builder->countAllResults();
    }

    /**
     * @param array<string, mixed> $where
     */
    private function sumWhere(string $table, string $column, array $where): int
    {
        $builder = $this->db->table($table)->selectSum($column, 'total');
        foreach ($where as $col => $val) {
            $builder->where($col, $val);
        }
        $row = $builder->get()->getRowArray();
        return (int) ($row['total'] ?? 0);
    }

    /**
     * Generate + persist a deterministic narrative for a module report
     * (Phase P2c). Accepts the already-computed report array so no query
     * is repeated. Returns the narrative text + generation metadata.
     *
     * @param array{start: string, end: string} $range
     * @param array<string, mixed>               $report
     * @return array<string, mixed>
     */
    public function summarize(string $module, array $range, array $report): array
    {
        $data = $this->normalize($module, $report);

        $summarizer = new \App\Services\Analytics\ReportSummarizer();
        $narrative  = $summarizer->generate($module, $range['start'], $range['end'], $data);

        $userId = \App\Auth\CurrentUser::id();
        $now    = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db->table('report_summaries')->insert([
            'module'                => $module,
            'period_start'          => $range['start'],
            'period_end'            => $range['end'],
            'input_data'            => json_encode($data, JSON_THROW_ON_ERROR),
            'generated_summary'     => $narrative,
            'generation_method'     => \App\Services\Analytics\ReportSummarizer::METHOD,
            'model_used'            => \App\Services\Analytics\ReportSummarizer::MODEL,
            'generated_by_user_id'  => $userId,
            'created_at'            => $now,
        ]);
        $id = (int) $this->db->insertID();

        \Config\Services::auditOutbox()->enqueue(
            'reports.summarized',
            'report_summaries',
            $id,
            $userId ?? 0,
            ['resource_code' => $module . ':' . $range['start'] . '..' . $range['end']],
        );

        return [
            'narrative'         => $narrative,
            'generation_method' => \App\Services\Analytics\ReportSummarizer::METHOD,
            'model_used'        => \App\Services\Analytics\ReportSummarizer::MODEL,
        ];
    }

    /**
     * Flatten a module report array into the summarizer's figure set.
     *
     * @param array<string, mixed> $report
     * @return array<string, int|string>
     */
    private function normalize(string $module, array $report): array
    {
        if ($module === 'clinic') {
            $referrals = 0;
            foreach ($report['referral_flows'] ?? [] as $r) {
                $referrals += (int) ($r['cnt'] ?? 0);
            }
            $top = $report['top_complaints'][0]['chief_complaint'] ?? '';
            return [
                'total'         => (int) ($report['total_encounters'] ?? 0),
                'referrals'     => $referrals,
                'top_complaint' => (string) $top,
            ];
        }
        if ($module === 'counselling') {
            return [
                'total'    => (int) ($report['total_appointments'] ?? 0),
                'no_shows' => (int) ($report['no_show_count'] ?? 0),
                'sessions' => (int) ($report['sessions_opened'] ?? 0),
            ];
        }
        // inventory
        return [
            'total_medicines' => (int) ($report['total_medicines'] ?? 0),
            'low_stock'       => count($report['low_stock'] ?? []),
            'expiring'        => count($report['expiring'] ?? []),
            'dispensed'       => (int) ($report['total_dispensed'] ?? 0),
        ];
    }

    /**
     * Cast numeric aggregate columns (cnt/qty/ids/stock) to int so the
     * JSON contract is stable across MySQL driver string returns.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function intify(array $rows): array
    {
        return array_map(static function (array $r): array {
            foreach (['cnt', 'qty', 'id', 'total_stock', 'reorder_threshold', 'quantity_remaining'] as $col) {
                if (array_key_exists($col, $r) && $r[$col] !== null) {
                    $r[$col] = (int) $r[$col];
                }
            }
            return $r;
        }, $rows);
    }
}
