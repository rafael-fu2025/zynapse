<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use DateTimeImmutable;

/**
 * BmgAnalytics — deterministic composting analytics (Phase P4, recycled
 * from legacy synapse_ag BmgYieldCalculator + BmgDurationCalculator).
 *
 * Pure/stateless: yield %, mass reduction, yield & duration
 * classification, expected completion date, days-until, and progress %.
 * The service supplies weights/dates; this class has no side effects and
 * is fully unit-testable.
 */
final class BmgAnalytics
{
    public function computeYield(float $inputKg, float $outputKg): float
    {
        if ($inputKg <= 0) {
            return 0.0;
        }
        return min(round(($outputKg / $inputKg) * 100, 2), 100.0);
    }

    public function massReduction(float $yieldPct): float
    {
        return round(100.0 - $yieldPct, 2);
    }

    public function classifyYield(float $yieldPct): string
    {
        return match (true) {
            $yieldPct >= 50 => 'excellent',
            $yieldPct >= 35 => 'good',
            $yieldPct >= 20 => 'fair',
            default         => 'poor',
        };
    }

    public function classifyDuration(int $days): string
    {
        return match (true) {
            $days <= 14 => 'fast',
            $days <= 30 => 'normal',
            $days <= 60 => 'slow',
            default     => 'stalled',
        };
    }

    /** Start date + reference duration (fallback 45 days) → Y-m-d. */
    public function expectedCompletionDate(string $startDate, int $days): string
    {
        if ($days <= 0) {
            $days = 45;
        }
        return (new DateTimeImmutable($startDate))->modify("+{$days} days")->format('Y-m-d');
    }

    /** Positive = days until ready, negative = overdue, null if no date. */
    public function daysUntilExpected(?string $expectedDate, ?string $today = null): ?int
    {
        if ($expectedDate === null || $expectedDate === '') {
            return null;
        }
        $now = new DateTimeImmutable($today ?? 'now');
        $exp = new DateTimeImmutable($expectedDate);
        $diff = (int) $now->diff($exp)->days;
        return $exp < $now ? -$diff : $diff;
    }

    /** Elapsed / total span, clamped 0–100. */
    public function progressPercent(?string $startDate, ?string $expectedDate, ?string $today = null): int
    {
        if ($startDate === null || $startDate === '' || $expectedDate === null || $expectedDate === '') {
            return 0;
        }
        $start = new DateTimeImmutable($startDate);
        $exp   = new DateTimeImmutable($expectedDate);
        $now   = new DateTimeImmutable($today ?? 'now');
        $total = (int) $start->diff($exp)->days;
        if ($total <= 0) {
            return 100;
        }
        $elapsed = (int) $start->diff($now)->days;
        return max(0, min(100, (int) round(($elapsed / $total) * 100)));
    }
}
