<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiException;
use Modules\Reports\Services\ReportRange;
use PHPUnit\Framework\TestCase;

final class ReportRangeTest extends TestCase
{
    private ReportRange $ranges;

    protected function setUp(): void
    {
        $this->ranges = new ReportRange();
    }

    public function testExplicitRangeUsesManilaCalendarBoundariesInUtc(): void
    {
        $range = $this->ranges->resolve('2026-08-01', '2026-08-01');

        $this->assertSame([
            'start_utc' => '2026-07-31 16:00:00',
            'end_utc_exclusive' => '2026-08-01 16:00:00',
        ], $this->ranges->timestampBounds($range));
    }

    public function testDefaultRangeContainsExactlyThirtyInclusiveCalendarDays(): void
    {
        $range = $this->ranges->resolve(null, null);
        $start = new \DateTimeImmutable($range['start']);
        $end = new \DateTimeImmutable($range['end']);

        $this->assertSame(29, (int) $start->diff($end)->format('%a'));
    }

    public function testLeapDayIsAcceptedAsARealCalendarDate(): void
    {
        $this->assertSame(
            ['start' => '2028-02-29', 'end' => '2028-02-29'],
            $this->ranges->resolve('2028-02-29', '2028-02-29'),
        );
    }

    public function testExactlyMaximumRangeIsAccepted(): void
    {
        $this->assertSame(
            ['start' => '2025-01-01', 'end' => '2026-01-01'],
            $this->ranges->resolve('2025-01-01', '2026-01-01'),
        );
    }

    public function testPreviousRangeHasSameInclusiveDuration(): void
    {
        $this->assertSame(
            ['start' => '2026-07-03', 'end' => '2026-07-31'],
            $this->ranges->previous(['start' => '2026-08-01', 'end' => '2026-08-29']),
        );
    }

    /** @dataProvider invalidRanges */
    public function testInvalidRangesAreRejected(?string $start, ?string $end): void
    {
        $this->expectException(ApiException::class);
        $this->ranges->resolve($start, $end);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function invalidRanges(): array
    {
        return [
            'impossible date' => ['2026-02-30', '2026-03-01'],
            'non-leap February 29' => ['2027-02-29', '2027-03-01'],
            'reversed' => ['2026-08-02', '2026-08-01'],
            'missing end' => ['2026-08-01', null],
            'missing start' => [null, '2026-08-01'],
            'over maximum' => ['2025-01-01', '2026-08-01'],
        ];
    }
}
