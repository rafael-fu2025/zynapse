<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Facilities\Services\Bmg\MonitorService;
use PHPUnit\Framework\TestCase;

/**
 * The monitoring dashboard's pure math: online/offline judgement and
 * missed-slot detection. Manila-day aware — the slot list is built in
 * Asia/Manila and compared against UTC run timestamps.
 */
final class BmgMonitorMathTest extends TestCase
{
    public function testIsOnlineUsesTheThreshold(): void
    {
        $now = new \DateTimeImmutable('2026-10-07 02:00:00', new \DateTimeZone('UTC'));

        $this->assertTrue(MonitorService::isOnline('2026-10-07 01:50:00', 20, $now), '10 min < 20 min threshold.');
        $this->assertTrue(MonitorService::isOnline('2026-10-07 01:40:00', 20, $now), 'Exactly at the threshold is online.');
        $this->assertFalse(MonitorService::isOnline('2026-10-07 01:39:00', 20, $now));
        $this->assertFalse(MonitorService::isOnline(null, 20, $now), 'Never-checked-in is offline, not unknown.');
    }

    public function testAgeMinutes(): void
    {
        $now = new \DateTimeImmutable('2026-10-07 02:00:30', new \DateTimeZone('UTC'));
        $this->assertSame(45, MonitorService::ageMinutes('2026-10-07 01:15:00', $now));
    }

    public function testMissedSlotsFlagsSlotsWithNoRunNearby(): void
    {
        // 2026-10-07 03:00 UTC = 11:00 Manila. Slots at 09:00 and 10:00
        // Manila on the read day; grace 30 min. Runs exist for both of
        // yesterday's slots and today's 09:00 — only today's 10:00 is
        // unmatched.
        $now = new \DateTimeImmutable('2026-10-07 03:00:00', new \DateTimeZone('UTC'));
        $runStarts = [
            // Yesterday (Manila 2026-10-06): 09:00 → 01:00 UTC, 10:00 → 02:00 UTC.
            '2026-10-06 01:00:00',
            '2026-10-06 02:00:00',
            // Today (Manila 2026-10-07): 09:02 → 01:02 UTC.
            '2026-10-07 01:02:00',
        ];

        $missed = MonitorService::missedSlots(['09:00', '10:00'], $runStarts, 30, $now);
        $this->assertSame(['2026-10-07 10:00'], $missed);
    }

    public function testMissedSlotsSkipsSlotsStillInsideGrace(): void
    {
        // 2026-10-07 01:15 UTC = 09:15 Manila; the 09:00 slot's grace
        // window (30 min) has not elapsed, so TODAY's slot is not yet
        // declared missed. Yesterday's unmatched slot IS reported — a
        // miss stays actionable while it is within the look-back.
        $now = new \DateTimeImmutable('2026-10-07 01:15:00', new \DateTimeZone('UTC'));

        $missed = MonitorService::missedSlots(['09:00'], [], 30, $now);
        $this->assertNotContains('2026-10-07 09:00', $missed, 'A slot inside its grace window is not missed yet.');
        $this->assertContains('2026-10-06 09:00', $missed);
    }

    public function testMissedSlotsReturnsNothingWithoutAnEnabledSchedule(): void
    {
        $now = new \DateTimeImmutable('2026-10-07 03:00:00', new \DateTimeZone('UTC'));
        $this->assertSame([], MonitorService::missedSlots([], ['2026-10-07 01:00:00'], 30, $now));
    }

    public function testSlotsBeforeTheScheduleWasPublishedAreNotMissed(): void
    {
        // The reported bug. The schedule (23:00 / 23:05 / 23:10 Manila) was
        // published 2026-10-08 22:57 Manila = 14:57 UTC and checked at
        // 23:15 Manila. Yesterday's three slots fell before the schedule
        // existed — nothing could have run them.
        $now       = new \DateTimeImmutable('2026-10-08 15:15:00', new \DateTimeZone('UTC'));
        $owedFrom  = MonitorService::slotsOwedFrom('2026-10-08 14:57:00');
        $times     = ['23:00', '23:05', '23:10'];

        $before = MonitorService::missedSlots($times, [], 30, $now);
        $this->assertContains('2026-10-07 23:00', $before, 'Without the guard, last night is wrongly blamed.');

        $after = MonitorService::missedSlots($times, [], 30, $now, $owedFrom);
        $this->assertNotContains('2026-10-07 23:00', $after);
        $this->assertNotContains('2026-10-07 23:05', $after);
        $this->assertNotContains('2026-10-07 23:10', $after);
    }

    public function testASlotInsideTheSyncAllowanceIsNotMissedButALaterOneIs(): void
    {
        // Published 22:57 Manila; the board may take up to 10 min to pull it,
        // so the 23:00 and 23:05 slots were not yet its to run.
        // Today's 23:10 slot is owed (23:07 or later) and, unmatched past
        // its grace window, IS missed. Check at 23:45 Manila = 15:45 UTC.
        $now      = new \DateTimeImmutable('2026-10-08 15:45:00', new \DateTimeZone('UTC'));
        $owedFrom = MonitorService::slotsOwedFrom('2026-10-08 14:57:00');

        $missed = MonitorService::missedSlots(['23:00', '23:05', '23:10'], [], 30, $now, $owedFrom);
        $this->assertSame(['2026-10-08 23:10'], $missed);
    }

    public function testASlotThatGenuinelyWasMissedAfterPublishStillReports(): void
    {
        // Schedule published yesterday morning; board silent ever since.
        $now      = new \DateTimeImmutable('2026-10-08 03:00:00', new \DateTimeZone('UTC')); // 11:00 Manila
        $owedFrom = MonitorService::slotsOwedFrom('2026-10-06 22:00:00');

        $missed = MonitorService::missedSlots(['09:00'], [], 30, $now, $owedFrom);
        $this->assertContains('2026-10-07 09:00', $missed);
        $this->assertContains('2026-10-08 09:00', $missed);
    }

    public function testSlotsOwedFromHandlesAMissingPublishTime(): void
    {
        $this->assertNull(MonitorService::slotsOwedFrom(null));
        $this->assertSame(
            '2026-10-08 15:07:00',
            MonitorService::slotsOwedFrom('2026-10-08 14:57:00')->format('Y-m-d H:i:s'),
        );
    }

    public function testMissedSlotsLooksBackIntoYesterdayManila(): void
    {
        // 2026-10-07 00:30 UTC = 08:30 Manila on the 7th; yesterday's
        // (6th) 17:00 slot is long past and unmatched.
        $now = new \DateTimeImmutable('2026-10-07 00:30:00', new \DateTimeZone('UTC'));

        $missed = MonitorService::missedSlots(['17:00'], [], 30, $now);
        $this->assertContains('2026-10-06 17:00', $missed, 'A shortly-after-midnight read still sees yesterday evening.');
    }
}
