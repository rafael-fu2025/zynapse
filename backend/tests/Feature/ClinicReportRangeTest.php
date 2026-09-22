<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * ClinicReportRangeTest — the clinic dashboard is range-driven end to end.
 *
 * Covers the 2026-09-18 "remove every implicit limit" pass:
 *
 *   - `monthly_visits` returns ONE row per calendar month in range, with
 *     real 0 rows for empty months — the bar chart's x-axis is the selected
 *     range, never just the months that happen to hold data;
 *   - `daily_trend` returns ONE row per Manila day in range (0-filled), so
 *     the trend line spans the whole span;
 *   - both series and the status donut always sum to `total_encounters`,
 *     so no panel can disagree with another about the range total;
 *   - empty ranges keep the donut breakdowns truly empty (no phantom 0
 *     slices) while the month/day series stay complete;
 *   - the no-params default is the academic year to date, not a fixed
 *     30-day slice;
 *   - a single-month range behaves exactly as before (no regression), and
 *     late-evening Manila timestamps stay on their Manila day.
 *
 * The suite does not truncate between cases (see FeatureTestCase), so every
 * assertion is either an identity over the response or scoped to rows this
 * test inserted.
 */
final class ClinicReportRangeTest extends FeatureTestCase
{
    private const TZ = 'Asia/Manila';

    /** @var array{token:string, userId:int, email:string} */
    private array $admin = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(['clinic_admin']);
    }

    // ---------------------------------------------------------- helpers

    /** Convert a Manila wall-clock moment to the UTC `datetime` the table stores. */
    private function utcFromManila(string $manilaDay, string $time): string
    {
        return (new \DateTimeImmutable($manilaDay . ' ' . $time, new \DateTimeZone(self::TZ)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function insertEncounter(
        string $manilaDay,
        string $time,
        string $status,
        string $complaint,
        ?string $guestName = null,
    ): int {
        $createdAt = $this->utcFromManila($manilaDay, $time);
        $this->db->table('clinic_encounters')->insert([
            'tenant_id'         => 1,
            'guest_name'        => $guestName,
            'chief_complaint'   => $complaint,
            'status'            => $status,
            'attending_user_id' => $this->admin['userId'],
            'started_at'        => $createdAt,
            'created_at'        => $createdAt,
            'updated_at'        => $createdAt,
        ]);

        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed> */
    private function clinic(string $start, string $end): array
    {
        $res = $this->authed(
            $this->admin['token'],
            'get',
            "api/v1/reports/clinic?start={$start}&end={$end}",
        );
        $res->assertStatus(200);

        return $this->envelope($res)['data'];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, string $key, string $value): array
    {
        foreach ($rows as $row) {
            if ((string) $row[$key] === $value) {
                return $row;
            }
        }

        $this->fail(sprintf('No row with %s = "%s" in [%s].', $key, $value, implode(', ', array_column($rows, $key))));
    }

    // ------------------------------------------------------- acceptance

    public function testAugToDecRangeRendersOneBarPerMonthAndOnePointPerDay(): void
    {
        // Acceptance test 2: one encounter inside Sep and one inside Nov —
        // plus an Aug row so the first month is non-empty too.
        $this->insertEncounter('2026-08-12', '10:00:00', 'open', 'fever for three days', 'Range Test Guest');
        $this->insertEncounter('2026-09-21', '11:15:00', 'closed', 'annual check-up clearance');
        $this->insertEncounter('2026-11-09', '14:30:00', 'referred', 'nausea and vomiting');

        $data = $this->clinic('2026-08-01', '2026-12-31');

        // Acceptance test 1a: exactly 5 bars, one per month of the range,
        // in calendar order — including the empty months (real 0 rows).
        $months = array_column($data['monthly_visits'], 'month');
        $this->assertSame(['2026-08', '2026-09', '2026-10', '2026-11', '2026-12'], $months);

        // Acceptance test 1b: the daily series spans every day of the span
        // (31 + 30 + 31 + 30 + 31 = 153), zero-filled, no gaps.
        $days = array_column($data['daily_trend'], 'day');
        $this->assertCount(153, $days);
        $this->assertSame('2026-08-01', $days[0]);
        $this->assertSame('2026-12-31', $days[array_key_last($days)]);

        // The inserted encounters are counted where they belong.
        $this->assertGreaterThanOrEqual(1, (int) $this->rowFor($data['monthly_visits'], 'month', '2026-08')['cnt']);
        $this->assertGreaterThanOrEqual(1, (int) $this->rowFor($data['monthly_visits'], 'month', '2026-11')['cnt']);
        $this->assertGreaterThanOrEqual(1, (int) $this->rowFor($data['daily_trend'], 'day', '2026-09-21')['cnt']);
        $this->assertGreaterThanOrEqual(1, (int) $this->rowFor($data['daily_trend'], 'day', '2026-11-09')['cnt']);

        // Acceptance test 1c: every panel agrees on the range total.
        $monthSum = array_sum(array_column($data['monthly_visits'], 'cnt'));
        $daySum = array_sum(array_column($data['daily_trend'], 'cnt'));
        $statusSum = array_sum(array_column($data['status_breakdown'], 'cnt'));
        $this->assertSame($data['total_encounters'], $monthSum, 'Monthly bars must sum to the range total.');
        $this->assertSame($data['total_encounters'], $daySum, 'Daily points must sum to the range total.');
        $this->assertSame($data['total_encounters'], $statusSum, 'Status donut must sum to the range total.');

        // Donuts stay data-driven: no zero-count phantom entries.
        foreach ($data['status_breakdown'] as $row) {
            $this->assertGreaterThan(0, (int) $row['cnt']);
        }
        foreach ($data['patient_type_breakdown'] as $row) {
            $this->assertGreaterThan(0, (int) $row['cnt']);
        }

        // A guest encounter (no linked user, guest_name set) is its own
        // Patient Type slice — `GROUP BY kind` used to resolve to the joined
        // users.kind column and fold guests into a neighbouring bucket.
        $patientTypes = [];
        foreach ($data['patient_type_breakdown'] as $row) {
            $patientTypes[$row['kind']] = (int) $row['cnt'];
        }
        $this->assertArrayHasKey('guest', $patientTypes);
        $this->assertGreaterThanOrEqual(1, $patientTypes['guest']);
    }

    public function testSingleMonthRangeKeepsOneBarAndEveryDay(): void
    {
        // Acceptance test 3: Sep → Sep behaves exactly as before.
        $data = $this->clinic('2026-09-01', '2026-09-30');

        $this->assertSame(['2026-09'], array_column($data['monthly_visits'], 'month'));

        $days = array_column($data['daily_trend'], 'day');
        $this->assertCount(30, $days);
        $this->assertSame('2026-09-01', $days[0]);
        $this->assertSame('2026-09-30', $days[29]);
    }

    public function testShortMonthAndLeapFebruaryDropNoDays(): void
    {
        // 31-day month vs 28/29-day February — no day dropped, no off-by-one
        // on the range end.
        $feb = $this->clinic('2028-02-01', '2028-02-29');
        $this->assertSame(['2028-02'], array_column($feb['monthly_visits'], 'month'));
        $this->assertCount(29, array_column($feb['daily_trend'], 'day'));

        $apr = $this->clinic('2027-04-01', '2027-04-30');
        $this->assertCount(30, array_column($apr['daily_trend'], 'day'));
    }

    public function testEmptyRangeKeepsSeriesCompleteButDonutsEmpty(): void
    {
        // A window no other case writes to: the panels must show explicit
        // zeros — complete month/day series, empty breakdowns (no phantom
        // slices), all totals consistent at 0.
        $data = $this->clinic('2031-03-01', '2031-05-31');

        $this->assertSame(0, $data['total_encounters']);
        $this->assertSame(['2031-03', '2031-04', '2031-05'], array_column($data['monthly_visits'], 'month'));
        $this->assertSame([0, 0, 0], array_column($data['monthly_visits'], 'cnt'));

        $days = array_column($data['daily_trend'], 'day');
        $this->assertCount(31 + 30 + 31, $days);
        $this->assertSame(0, array_sum(array_column($data['daily_trend'], 'cnt')));

        $this->assertSame([], $data['status_breakdown']);
        $this->assertSame([], $data['complaint_categories']);
        $this->assertSame([], $data['patient_type_breakdown']);
    }

    public function testLateEveningManilaEncounterStaysOnItsManilaDay(): void
    {
        // 23:30 Manila = 15:30 UTC the same calendar day, but 22:30 the
        // previous UTC day for later times: the bucket must be Manila.
        $before = $this->clinic('2026-08-01', '2026-12-31');
        $aug31Before = (int) $this->rowFor($before['daily_trend'], 'day', '2026-08-31')['cnt'];
        $sep1Before = (int) $this->rowFor($before['daily_trend'], 'day', '2026-09-01')['cnt'];

        $this->insertEncounter('2026-08-31', '23:30:00', 'closed', 'late evening walk-in');

        $after = $this->clinic('2026-08-01', '2026-12-31');
        $this->assertSame(
            $aug31Before + 1,
            (int) $this->rowFor($after['daily_trend'], 'day', '2026-08-31')['cnt'],
            'A 23:30 Manila encounter belongs to Aug 31.',
        );
        $this->assertSame(
            $sep1Before,
            (int) $this->rowFor($after['daily_trend'], 'day', '2026-09-01')['cnt'],
            'A 23:30 Manila encounter must not roll into Sep 1.',
        );
    }

    public function testNoParamsDefaultIsAcademicYearToDate(): void
    {
        $res = $this->authed($this->admin['token'], 'get', 'api/v1/reports/summary');
        $res->assertStatus(200);
        $range = $this->envelope($res)['data']['range'];

        $today = new \DateTimeImmutable('today', new \DateTimeZone(self::TZ));
        $year = (int) $today->format('n') >= 8 ? (int) $today->format('Y') : (int) $today->format('Y') - 1;

        $this->assertSame(sprintf('%04d-08-01', $year), $range['start']);
        $this->assertSame($today->format('Y-m-d'), $range['end']);
    }
}
