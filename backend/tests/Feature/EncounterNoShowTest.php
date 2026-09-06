<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Covers the staff "Mark no-show" surface end to end and pins the
 * kiosk→clinic-appointment matching window.
 *
 * 1. `POST /clinic/encounters/{id}/no-show` — the route shipped in the
 *    2026-09 audit fix (controller + cascade service existed since
 *    August, but no route was registered, so the SPA button always
 *    404'd). Asserts the full cascade: encounter → closed/no_show,
 *    queue entry → done/no_show, and that a second call 409s.
 *
 * 2. `CheckinService::scan` appointment matching — `clinic_appointments.scheduled_at`
 *    is a UTC column while the kiosk reasons in Manila days. Before the
 *    fix the query compared the Manila date string directly against the
 *    UTC column, so a pre-08:00 Manila appointment was missed entirely
 *    (double walk-in) and an evening scan matched *tomorrow morning's*
 *    appointment. Both directions are pinned here through the real
 *    POST /clinic/checkins route.
 */
final class EncounterNoShowTest extends FeatureTestCase
{
    /** @var array{token:string, userId:int, email:string} */
    private array $admin = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(['admin']);
    }

    /** @return array<string, mixed> */
    private function postJson(string $route, array $body, int $expect = 200): array
    {
        $res = $this->authed($this->admin['token'], 'post', $route, $body);
        $res->assertStatus($expect);
        return $this->envelope($res);
    }

    private function makeStudent(): string
    {
        $number = '2026' . random_int(100000, 999999);
        $this->postJson('api/v1/clinic/students', [
            'student_number' => $number,
            'first_name'     => 'NoShow',
            'last_name'      => 'Cascade',
            'course'         => 'BSIT',
            'year_level'     => 1,
        ], 201);
        return $number;
    }

    private function makeAppointment(string $studentNumber, string $scheduledAtUtc): int
    {
        $body = $this->postJson('api/v1/clinic/appointments', [
            'patient_school_id' => $studentNumber,
            'provider_user_id'  => $this->admin['userId'],
            'scheduled_at'      => $scheduledAtUtc,
            'reason'            => 'No-show window regression',
        ], 201);
        return (int) $body['data']['id'];
    }

    private function checkIn(string $studentNumber, string $scannedAtUtc): array
    {
        $body = $this->postJson('api/v1/clinic/checkins', [
            'identifier'  => $studentNumber,
            'method'      => 'manual',
            'destination' => 'clinic',
            'purpose'     => 'Consultation',
            'station_id'  => 'Kiosk-NoShow',
            'scanned_at'  => $scannedAtUtc,
        ], 201);
        return $body['data'];
    }

    private function encounterIdFor(string $studentNumber): ?int
    {
        $row = $this->db->table('clinic_checkins c')
            ->select('e.id AS encounter_id')
            ->join('clinic_encounters e', 'e.id = c.encounter_id')
            ->where('c.patient_school_id', $studentNumber)
            ->orderBy('c.id', 'DESC')
            ->get()->getRowArray();
        return $row !== null ? (int) $row['encounter_id'] : null;
    }

    public function testNoShowCascadesWalkInEncounterAndQueue(): void
    {
        $number = $this->makeStudent();
        $checkin = $this->checkIn($number, '2026-09-06 02:00:00');
        $this->assertSame('clinic_queued', $checkin['outcome']);

        $encounterId = $this->encounterIdFor($number);
        $this->assertNotNull($encounterId, 'walk-in must open an encounter');

        $body = $this->postJson("api/v1/clinic/encounters/{$encounterId}/no-show", []);
        $this->assertSame('closed', $body['data']['status']);
        $this->assertSame('no_show', $body['data']['outcome']);

        $enc = $this->db->table('clinic_encounters')->where('id', $encounterId)->get()->getRowArray();
        $this->assertSame('closed', $enc['status']);
        $this->assertSame('no_show', $enc['outcome']);

        $queue = $this->db->table('clinic_queue_entries')
            ->where('encounter_id', $encounterId)->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertNotNull($queue);
        $this->assertSame('done', $queue['status']);
        $this->assertSame('no_show', $queue['outcome']);
    }

    public function testNoShowTwiceReturnsConflict(): void
    {
        $number = $this->makeStudent();
        $this->checkIn($number, '2026-09-06 02:10:00');
        $encounterId = $this->encounterIdFor($number);
        $this->assertNotNull($encounterId);

        $this->postJson("api/v1/clinic/encounters/{$encounterId}/no-show", []);

        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/encounters/{$encounterId}/no-show", []);
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.clinic.encounter_not_open', $res);
    }

    public function testNoShowUnknownEncounterReturns404(): void
    {
        $res = $this->authed($this->admin['token'], 'post', 'api/v1/clinic/encounters/999999/no-show', []);
        $res->assertStatus(404);
        $this->assertErrorCode('resource.not_found', $res);
    }

    /**
     * Manila 2026-09-07 07:00 (UTC 09-06 23:00) appointment; patient
     * scans at Manila 07:30 (UTC 23:30). The scan's Manila day is
     * 09-07, whose UTC bounds are [09-06 16:00, 09-07 16:00) — the
     * appointment sits inside and must be honoured, not double-queued.
     * (Old code compared against [09-07 00:00, 09-07 23:59:59] UTC and
     * missed it.)
     */
    public function testPre08ManilaAppointmentIsMatchedOnEarlyScan(): void
    {
        $number = $this->makeStudent();
        $apptId = $this->makeAppointment($number, '2026-09-06 23:00:00');

        $checkin = $this->checkIn($number, '2026-09-06 23:30:00');
        $this->assertSame(
            'clinic_appointment_confirmed',
            $checkin['outcome'],
            'pre-08:00 Manila scan must find the same-Manila-day appointment',
        );

        $appt = $this->db->table('clinic_appointments')->where('id', $apptId)->get()->getRowArray();
        $this->assertSame('checked_in', $appt['status']);
    }

    /**
     * Manila 09-06 02:30 scan (UTC 09-05 18:30). The appointment is
     * Manila 09-07 07:00 (UTC 09-06 23:00) — the NEXT Manila morning.
     * The old UTC-string bounds [09-06 00:00, 09-06 23:59:59] contained
     * it and the evening scan checked it in a day early; the corrected
     * Manila-day bounds end at UTC 09-06 16:00, so this scan must fall
     * through to a plain walk-in and leave the appointment untouched.
     */
    public function testEveningManilaScanDoesNotHijackNextMorningAppointment(): void
    {
        $number = $this->makeStudent();
        $apptId = $this->makeAppointment($number, '2026-09-06 23:00:00');

        $checkin = $this->checkIn($number, '2026-09-05 18:30:00');
        $this->assertNotSame(
            'clinic_appointment_confirmed',
            $checkin['outcome'],
            'a next-morning appointment must not be checked in by an evening scan',
        );
        $this->assertSame('clinic_queued', $checkin['outcome']);

        $appt = $this->db->table('clinic_appointments')->where('id', $apptId)->get()->getRowArray();
        $this->assertSame('scheduled', $appt['status']);
    }
}
