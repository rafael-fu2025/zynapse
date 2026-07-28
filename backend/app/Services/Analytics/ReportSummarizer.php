<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * ReportSummarizer — deterministic template NLG (Phase P2c, recycled
 * from legacy synapse_ag Libraries\ReportSummarizer).
 *
 * Pure heuristic (NOT an LLM): produces a plain-language narrative from
 * pre-aggregated report numbers via string templates. Crisis-alert and
 * screening sentences from the legacy version are intentionally dropped
 * (those features are out of scope for ZCode). Stateless / unit-testable;
 * the service supplies the normalized figures and handles persistence.
 */
final class ReportSummarizer
{
    public const METHOD = 'template_nlg';
    public const MODEL  = 'template_nlg_v2.0';

    /**
     * @param array<string, int|string> $data normalized module figures
     */
    public function generate(string $module, string $start, string $end, array $data): string
    {
        $startStr = date('M d, Y', (int) strtotime($start));
        $endStr   = date('M d, Y', (int) strtotime($end));

        return match ($module) {
            'clinic'      => $this->clinic($startStr, $endStr, $data),
            'counselling' => $this->counselling($startStr, $endStr, $data),
            'inventory'   => $this->inventory($startStr, $endStr, $data),
            default       => "Report generated for module {$module} covering {$startStr} to {$endStr}.",
        };
    }

    /**
     * @param array<string, int|string> $d
     */
    private function clinic(string $s, string $e, array $d): string
    {
        $total     = (int) ($d['total'] ?? 0);
        $referrals = (int) ($d['referrals'] ?? 0);
        $top       = trim((string) ($d['top_complaint'] ?? '')) ?: 'general check-up';

        $n = "During the period from {$s} to {$e}, the clinic processed a total of {$total} encounters. ";
        if ($total > 0) {
            $n .= "The most frequent reason for check-in was \"{$top}\". ";
            $n .= $referrals > 0
                ? "In addition, {$referrals} case(s) were referred between clinic and counselling, showing active cross-module collaboration."
                : 'No referrals were raised during this interval.';
        } else {
            $n .= 'No clinical encounters were recorded during this period.';
        }
        return $n;
    }

    /**
     * @param array<string, int|string> $d
     */
    private function counselling(string $s, string $e, array $d): string
    {
        $total    = (int) ($d['total'] ?? 0);
        $noShows  = (int) ($d['no_shows'] ?? 0);
        $sessions = (int) ($d['sessions'] ?? 0);

        $n = "Counselling services managed {$total} appointment(s) between {$s} and {$e}. ";
        if ($total > 0) {
            $rate = round(($noShows / $total) * 100, 1);
            $n .= "The no-show rate was {$rate}%, a key area for scheduling optimization. ";
            $n .= "{$sessions} counselling session(s) were opened in the same window.";
        } else {
            $n .= 'No counselling appointments were scheduled in this date range.';
        }
        return $n;
    }

    /**
     * @param array<string, int|string> $d
     */
    private function inventory(string $s, string $e, array $d): string
    {
        $meds      = (int) ($d['total_medicines'] ?? 0);
        $lowStock  = (int) ($d['low_stock'] ?? 0);
        $expiring  = (int) ($d['expiring'] ?? 0);
        $dispensed = (int) ($d['dispensed'] ?? 0);

        $n = "The medicine inventory report for {$s} to {$e} shows {$dispensed} unit(s) dispensed across {$meds} catalogued medicine(s). ";
        if ($lowStock > 0 || $expiring > 0) {
            $n .= "{$lowStock} medicine(s) are at or below their reorder threshold. ";
            if ($expiring > 0) {
                $n .= "{$expiring} batch(es) expire within 90 days — prioritise these under FEFO to minimise waste.";
            }
        } else {
            $n .= 'Stock levels are healthy with no low-stock or near-expiry alerts.';
        }
        return $n;
    }
}
