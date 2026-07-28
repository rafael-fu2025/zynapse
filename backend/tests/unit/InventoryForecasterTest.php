<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\InventoryForecaster;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InventoryForecasterTest extends TestCase
{
    private InventoryForecaster $fc;

    protected function setUp(): void
    {
        $this->fc = new InventoryForecaster();
    }

    public function testMovingAverageDailyRate(): void
    {
        // 60 dispensed over 30 days = 2.0/day (January → no seasonality).
        $r = $this->fc->forecast(100, 10, 60, 'analgesic', new DateTimeImmutable('2026-01-15'));
        $this->assertSame(2.0, $r['predicted_daily_usage']);
        $this->assertSame(1.0, $r['seasonality_factor']);
    }

    public function testZeroHistoryFallsBackToBaseline(): void
    {
        $r = $this->fc->forecast(100, 10, 0, 'vitamins', new DateTimeImmutable('2026-01-15'));
        $this->assertSame(0.25, $r['predicted_daily_usage']);
    }

    public function testSeasonalBumpAppliesInRainySeasonForSeasonalCategory(): void
    {
        // July + analgesic → 1.25x. 30 dispensed = 1.0/day → 1.25/day.
        $r = $this->fc->forecast(100, 10, 30, 'analgesic', new DateTimeImmutable('2026-07-15'));
        $this->assertSame(1.25, $r['seasonality_factor']);
        $this->assertSame(1.25, $r['predicted_daily_usage']);
    }

    public function testNoSeasonalBumpForNonSeasonalCategory(): void
    {
        $r = $this->fc->forecast(100, 10, 30, 'vitamins', new DateTimeImmutable('2026-07-15'));
        $this->assertSame(1.0, $r['seasonality_factor']);
    }

    public function testStockoutDateProjection(): void
    {
        // 100 units at 2.0/day → 50 days out from base date.
        $r = $this->fc->forecast(100, 10, 60, 'other', new DateTimeImmutable('2026-01-01'));
        $this->assertSame('2026-02-20', $r['predicted_stockout_date']); // 2026-01-01 + 50 days
    }

    public function testReorderDateIsTodayWhenAtOrBelowThreshold(): void
    {
        $r = $this->fc->forecast(10, 10, 60, 'other', new DateTimeImmutable('2026-01-01'));
        $this->assertSame('2026-01-01', $r['predicted_reorder_date']);
    }

    public function testConfidenceIntervalBounds(): void
    {
        $r = $this->fc->forecast(100, 10, 60, 'other', new DateTimeImmutable('2026-01-01'));
        $this->assertSame(1.6, $r['confidence_interval_lower']); // 2.0 * 0.8
        $this->assertSame(2.4, $r['confidence_interval_upper']); // 2.0 * 1.2
    }
}
