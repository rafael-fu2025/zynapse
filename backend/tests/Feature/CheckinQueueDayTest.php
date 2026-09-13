<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Shared\ManilaDay;

/**
 * The queue's day partition must follow the Manila business calendar
 * on BOTH sides: kiosk walk-ins write `queue_date` and the staff
 * "today" queue reads it. Before the 2026-08 fix the writer used the
 * UTC calendar date, so a scan landing between 16:00–24:00 UTC
 * (midnight–08:00 Manila) was filed under the previous business day
 * and vanished from the staff queue and the public lobby board until
 * the next Manila midnight.
 *
 * These tests drive the real POST /clinic/checkins route — one with a
 * fabricated `scanned_at` inside that exact window, one with the real
 * clock — and assert the partition through the database and staff
 * visibility through GET /clinic/queue.
 */
final class CheckinQueueDayTest extends FeatureTestCase
{
    /** @var array{token:string, userId:int, email:string} */
    private array $admin = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(['clinic_admin']);
    }

    /** @return array<string, mixed> */
    private function postJson(string $route, array $body, int $expect = 200): array
    {
        $res = $this->authed($this->admin['token'], 'post', $route, $body);
        $res->assertStatus($expect);
        return $this->envelope($res);
    }

    /**
     * Create a throwaway student and return the school number the
     * kiosk scan will resolve (manual → student registry, students
     * before employees).
     */
    private function makeStudent(): string
    {
        $number = '2026' . random_int(100000, 999999);
        $this->postJson('api/v1/clinic/students', [
            'student_number' => $number,
            'first_name'     => 'Queue',
            'last_name'      => 'Partition',
            'course'         => 'BSIT',
            'year_level'     => 1,
        ], 201);
        return $number;
    }

    /**
     * @return array<string, mixed>|null the queue row for a check-in
     *                                     of the given school number
     */
    private function queueRowFor(string $studentNumber): ?array
    {
        return $this->db->table('clinic_checkins c')
            ->select('q.*')
            ->join('clinic_encounters e', 'e.id = c.encounter_id')
            ->join('clinic_queue_entries q', 'q.encounter_id = e.id')
            ->where('c.patient_school_id', $studentNumber)
            ->orderBy('c.id', 'DESC')
            ->get()->getRowArray();
    }

    public function testEveningUtcScanFilesNextManilaBusinessDay(): void
    {
        $number = $this->makeStudent();

        // 18:00 UTC == 02:00 of the NEXT Manila business day.
        $scanUtc = '2026-08-30 18:00:00';
        $expectedQueueDate = (new \DateTimeImmutable($scanUtc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Asia/Manila'))
            ->format('Y-m-d');
        $this->assertSame('2026-08-31', $expectedQueueDate, 'pin the 16:00–24:00 UTC window');

        $body = $this->postJson('api/v1/clinic/checkins', [
            'identifier'  => $number,
            'method'      => 'manual',
            'destination' => 'clinic',
            'purpose'     => 'Consultation',
            'station_id'  => 'Kiosk-Feature',
            'scanned_at'  => $scanUtc,
        ], 201);
        $this->assertSame('clinic_queued', $body['data']['outcome'] ?? null);

        $row = $this->queueRowFor($number);
        $this->assertNotNull($row, 'walk-in check-in must enqueue');
        $this->assertSame(
            $expectedQueueDate,
            $row['queue_date'],
            'queue_date must be the Manila business day of the scan, not the UTC date',
        );
        $this->assertSame('2026-08-31', $row['queue_date']);
    }

    public function testCurrentScanIsVisibleInStaffTodayQueue(): void
    {
        $number = $this->makeStudent();

        $body = $this->postJson('api/v1/clinic/checkins', [
            'identifier'  => $number,
            'method'      => 'manual',
            'destination' => 'clinic',
            'purpose'     => 'Consultation',
            'station_id'  => 'Kiosk-Feature',
        ], 201);
        $this->assertSame('clinic_queued', $body['data']['outcome'] ?? null);

        $row = $this->queueRowFor($number);
        $this->assertNotNull($row, 'walk-in check-in must enqueue');
        $this->assertSame(ManilaDay::today(), $row['queue_date']);

        $res = $this->authed($this->admin['token'], 'get', 'api/v1/clinic/queue');
        $res->assertStatus(200);
        $rows = $this->envelope($res)['data'] ?? [];
        $this->assertIsArray($rows);

        $found = false;
        foreach ($rows as $r) {
            if ((int) ($r['encounter_id'] ?? 0) === (int) $row['encounter_id']) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'a scan from now must be visible in the staff today queue');
    }
}
