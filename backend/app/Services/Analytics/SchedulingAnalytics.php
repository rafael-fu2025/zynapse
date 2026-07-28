<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * SchedulingAnalytics — deterministic counselling slot analytics (Phase
 * P5a, recycled from legacy synapse_ag SchedulingOptimizer).
 *
 * Pure/stateless: no-show rate, overbooking recommendation, and a coarse
 * utilization proxy. The service supplies per-slot appointment tallies;
 * this class has no side effects and is fully unit-testable.
 */
final class SchedulingAnalytics
{
    /** No-shows / total, rounded to 4 dp; 0.0 when there is no history. */
    public function noShowRate(int $total, int $noShows): float
    {
        if ($total <= 0) {
            return 0.0;
        }
        return round($noShows / $total, 4);
    }

    /**
     * High no-show slots earn extra overbooking headroom.
     *   rate >= 0.30 → 2 · rate >= 0.15 → 1 · otherwise 0.
     */
    public function recommendedOverbooking(float $noShowRate): int
    {
        return match (true) {
            $noShowRate >= 0.30 => 2,
            $noShowRate >= 0.15 => 1,
            default             => 0,
        };
    }

    /** Coarse utilization proxy: booked slots run ~85% full, idle slots 0. */
    public function avgUtilization(int $total): float
    {
        return $total > 0 ? 0.85 : 0.0;
    }
}
