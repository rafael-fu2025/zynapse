<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Facilities\Services\Bmg\ScheduleService;
use PHPUnit\Framework\TestCase;

/**
 * The schedule's pure math: time normalization and the next-run
 * computation. Both are Manila-time aware (the clinic's calendar) and
 * framework-free so they run in the unit suite.
 */
final class BmgScheduleTimesTest extends TestCase
{
    public function testNormalizeTimesSortsDeduplicatesAndValidates(): void
    {
        $this->assertSame(
            ['06:30', '09:00', '17:00'],
            ScheduleService::normalizeTimes(['17:00', '09:00', '06:30']),
        );
        $this->assertSame([], ScheduleService::normalizeTimes([]));
        $this->assertSame(['00:00', '23:59'], ScheduleService::normalizeTimes(['23:59', '00:00']));
    }

    public function testNormalizeTimesRejectsBadShapes(): void
    {
        foreach ([
            ['9:00'],                     // not zero-padded
            ['24:00'],                    // hour out of range
            ['09:60'],                    // minute out of range
            ['0900'],                     // missing colon
            ['09:00', '09:00'],           // duplicate
            ['01:00', '02:00', '03:00', '04:00', '05:00', '06:00', '07:00', '08:00', '09:00'], // > 8
        ] as $bad) {
            try {
                ScheduleService::normalizeTimes($bad);
                $this->fail('Expected validation failure for: ' . json_encode($bad));
            } catch (\App\Exceptions\ApiException $e) {
                $this->assertSame(422, $e->httpStatus);
            }
        }
    }

    public function testNormalizeTimesRejectsNonArray(): void
    {
        $this->expectException(\App\Exceptions\ApiException::class);
        ScheduleService::normalizeTimes('09:00');
    }

    public function testNextRunPicksTheSoonestUpcomingSlotToday(): void
    {
        // 2026-10-07 01:00 UTC = 09:00 Manila.
        $now = new \DateTimeImmutable('2026-10-07 01:00:00', new \DateTimeZone('UTC'));

        $next = ScheduleService::nextRunAtUtc(['09:30', '12:00', '17:00'], true, $now);
        // 09:30 Manila = 01:30 UTC.
        $this->assertSame('2026-10-07 01:30:00', $next);
    }

    public function testNextRunRollsToTomorrowAfterTheLastSlot(): void
    {
        // 2026-10-07 12:00 UTC = 20:00 Manila — all slots passed.
        $now = new \DateTimeImmutable('2026-10-07 12:00:00', new \DateTimeZone('UTC'));

        $next = ScheduleService::nextRunAtUtc(['09:00', '17:00'], true, $now);
        // Tomorrow 09:00 Manila = 2026-10-08 01:00 UTC.
        $this->assertSame('2026-10-08 01:00:00', $next);
    }

    public function testNextRunUsesTheManilaCalendarDayWhenUtcDateDiffers(): void
    {
        // 2026-10-07 17:00 UTC = 01:00 Manila on the 8th. The UTC date
        // is still the 7th while the Manila date is the 8th — the
        // roll-over must name MANILA tomorrow (the 9th), not re-use a
        // UTC-derived day.
        $now = new \DateTimeImmutable('2026-10-07 17:00:00', new \DateTimeZone('UTC'));

        $next = ScheduleService::nextRunAtUtc(['00:30'], true, $now);
        // Today's 00:30 (Manila) already passed; the next is the 9th.
        // 00:30 Manila on the 9th = 2026-10-08 16:30 UTC.
        $this->assertSame('2026-10-08 16:30:00', $next);
    }

    public function testNextRunIsNullWhenDisabledOrEmpty(): void
    {
        $now = new \DateTimeImmutable('2026-10-07 01:00:00', new \DateTimeZone('UTC'));

        $this->assertNull(ScheduleService::nextRunAtUtc(['09:00'], false, $now));
        $this->assertNull(ScheduleService::nextRunAtUtc([], true, $now));
    }
}
