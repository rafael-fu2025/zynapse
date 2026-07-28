<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\BmgAnalytics;
use PHPUnit\Framework\TestCase;

final class BmgAnalyticsTest extends TestCase
{
    private BmgAnalytics $a;

    protected function setUp(): void
    {
        $this->a = new BmgAnalytics();
    }

    public function testYieldPercent(): void
    {
        $this->assertSame(40.0, $this->a->computeYield(100, 40));
    }

    public function testYieldZeroInput(): void
    {
        $this->assertSame(0.0, $this->a->computeYield(0, 40));
    }

    public function testYieldCappedAt100(): void
    {
        $this->assertSame(100.0, $this->a->computeYield(10, 50));
    }

    public function testMassReduction(): void
    {
        $this->assertSame(60.0, $this->a->massReduction(40.0));
    }

    public function testClassifyYield(): void
    {
        $this->assertSame('excellent', $this->a->classifyYield(55));
        $this->assertSame('good', $this->a->classifyYield(40));
        $this->assertSame('fair', $this->a->classifyYield(25));
        $this->assertSame('poor', $this->a->classifyYield(10));
    }

    public function testClassifyDuration(): void
    {
        $this->assertSame('fast', $this->a->classifyDuration(10));
        $this->assertSame('normal', $this->a->classifyDuration(20));
        $this->assertSame('slow', $this->a->classifyDuration(45));
        $this->assertSame('stalled', $this->a->classifyDuration(90));
    }

    public function testExpectedCompletionDate(): void
    {
        $this->assertSame('2026-02-15', $this->a->expectedCompletionDate('2026-01-01', 45));
    }

    public function testExpectedCompletionFallbackWhenZero(): void
    {
        // 0 → fallback 45 days.
        $this->assertSame('2026-02-15', $this->a->expectedCompletionDate('2026-01-01', 0));
    }

    public function testDaysUntilExpectedFuture(): void
    {
        $this->assertSame(10, $this->a->daysUntilExpected('2026-01-11', '2026-01-01'));
    }

    public function testDaysUntilExpectedOverdue(): void
    {
        $this->assertSame(-5, $this->a->daysUntilExpected('2026-01-01', '2026-01-06'));
    }

    public function testDaysUntilExpectedNull(): void
    {
        $this->assertNull($this->a->daysUntilExpected(null));
    }

    public function testProgressPercent(): void
    {
        // start 01-01, expected 01-11 (10-day span), today 01-06 → 50%.
        $this->assertSame(50, $this->a->progressPercent('2026-01-01', '2026-01-11', '2026-01-06'));
    }

    public function testProgressClampedTo100(): void
    {
        $this->assertSame(100, $this->a->progressPercent('2026-01-01', '2026-01-11', '2026-03-01'));
    }
}
