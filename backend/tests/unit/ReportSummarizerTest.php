<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\ReportSummarizer;
use PHPUnit\Framework\TestCase;

final class ReportSummarizerTest extends TestCase
{
    private ReportSummarizer $rs;

    protected function setUp(): void
    {
        $this->rs = new ReportSummarizer();
    }

    public function testClinicWithData(): void
    {
        $n = $this->rs->generate('clinic', '2026-07-01', '2026-07-31', ['total' => 10, 'referrals' => 2, 'top_complaint' => 'Fever']);
        $this->assertStringContainsString('10 encounters', $n);
        $this->assertStringContainsString('Fever', $n);
        $this->assertStringContainsString('2 case(s) were referred', $n);
    }

    public function testClinicEmpty(): void
    {
        $n = $this->rs->generate('clinic', '2026-07-01', '2026-07-31', ['total' => 0]);
        $this->assertStringContainsString('No clinical encounters', $n);
    }

    public function testCounsellingNoShowRate(): void
    {
        $n = $this->rs->generate('counselling', '2026-07-01', '2026-07-31', ['total' => 4, 'no_shows' => 1, 'sessions' => 3]);
        $this->assertStringContainsString('4 appointment(s)', $n);
        $this->assertStringContainsString('25%', $n);
    }

    public function testCounsellingEmpty(): void
    {
        $n = $this->rs->generate('counselling', '2026-07-01', '2026-07-31', ['total' => 0]);
        $this->assertStringContainsString('No counselling appointments', $n);
    }

    public function testCounsellingNarrativeHasNoCrisisOrScreening(): void
    {
        $n = $this->rs->generate('counselling', '2026-07-01', '2026-07-31', ['total' => 4, 'no_shows' => 1, 'sessions' => 3]);
        $this->assertStringNotContainsStringIgnoringCase('crisis', $n);
        $this->assertStringNotContainsStringIgnoringCase('screening', $n);
        $this->assertStringNotContainsStringIgnoringCase('suicide', $n);
    }

    public function testInventoryWithAlerts(): void
    {
        $n = $this->rs->generate('inventory', '2026-07-01', '2026-07-31', ['total_medicines' => 48, 'low_stock' => 3, 'expiring' => 2, 'dispensed' => 42]);
        $this->assertStringContainsString('42 unit(s) dispensed', $n);
        $this->assertStringContainsString('3 medicine(s) are at or below', $n);
        $this->assertStringContainsString('FEFO', $n);
    }

    public function testInventoryHealthy(): void
    {
        $n = $this->rs->generate('inventory', '2026-07-01', '2026-07-31', ['total_medicines' => 48, 'low_stock' => 0, 'expiring' => 0, 'dispensed' => 10]);
        $this->assertStringContainsString('Stock levels are healthy', $n);
    }

    public function testUnknownModuleFallback(): void
    {
        $n = $this->rs->generate('finance', '2026-07-01', '2026-07-31', []);
        $this->assertStringContainsString('module finance', $n);
    }
}
