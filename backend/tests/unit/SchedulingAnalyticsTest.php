<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\SchedulingAnalytics;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the deterministic counselling scheduling analytics
 * (Phase P5a). Pure math — no database.
 */
final class SchedulingAnalyticsTest extends TestCase
{
    private SchedulingAnalytics $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new SchedulingAnalytics();
    }

    public function testNoShowRateZeroTotalReturnsZero(): void
    {
        $this->assertSame(0.0, $this->calc->noShowRate(0, 0));
    }

    public function testNoShowRateNoNoShowsReturnsZero(): void
    {
        $this->assertSame(0.0, $this->calc->noShowRate(10, 0));
    }

    public function testNoShowRateFortyPercent(): void
    {
        $this->assertEqualsWithDelta(0.4, $this->calc->noShowRate(10, 4), 1e-9);
    }

    public function testNoShowRateRoundsToFourDecimals(): void
    {
        // 1/3 = 0.33333... → 0.3333
        $this->assertEqualsWithDelta(0.3333, $this->calc->noShowRate(3, 1), 1e-9);
    }

    public function testRecommendedOverbookingHighTier(): void
    {
        $this->assertSame(2, $this->calc->recommendedOverbooking(0.40));
    }

    public function testRecommendedOverbookingHighBoundary(): void
    {
        // Exactly 0.30 still triggers the top tier.
        $this->assertSame(2, $this->calc->recommendedOverbooking(0.30));
    }

    public function testRecommendedOverbookingMidTier(): void
    {
        $this->assertSame(1, $this->calc->recommendedOverbooking(0.15));
    }

    public function testRecommendedOverbookingJustBelowMid(): void
    {
        $this->assertSame(0, $this->calc->recommendedOverbooking(0.1499));
    }

    public function testRecommendedOverbookingZero(): void
    {
        $this->assertSame(0, $this->calc->recommendedOverbooking(0.0));
    }

    public function testAvgUtilizationBusySlot(): void
    {
        $this->assertEqualsWithDelta(0.85, $this->calc->avgUtilization(3), 1e-9);
    }

    public function testAvgUtilizationIdleSlot(): void
    {
        $this->assertSame(0.0, $this->calc->avgUtilization(0));
    }
}
