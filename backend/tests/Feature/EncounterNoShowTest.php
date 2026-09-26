<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Covers the staff "Mark no-show" surface end to end.
 *
 * `POST /clinic/encounters/{id}/no-show` — the route shipped in the
 * 2026-09 audit fix (controller + cascade service existed since August,
 * but no route was registered, so the SPA button always 404'd). Asserts
 * the full cascade: encounter → closed/no_show, queue entry →
 * done/no_show, and that a second call 409s. Fixtures check an
 * appointment in through the real transition endpoint (staff action),
 * which opens the encounter and queues it on today's Manila date.
 */
final class EncounterNoShowTest extends FeatureTestCase
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

    /** Staff check-in transition — opens the encounter and queues it today. */
    private function checkInAppointment(int $appointmentId): void
    {
        $this->postJson("api/v1/clinic/appointments/{$appointmentId}/transition", [
            'status' => 'checked_in',
        ]);
    }

    private function encounterIdFor(string $studentNumber): ?int
    {
        $row = $this->db->table('clinic_queue_entries q')
            ->select('q.encounter_id')
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            ->where('e.patient_school_id', $studentNumber)
            ->orderBy('q.id', 'DESC')
            ->get()->getRowArray();
        return $row !== null ? (int) $row['encounter_id'] : null;
    }

    public function testNoShowCascadesEncounterAndQueue(): void
    {
        $number = $this->makeStudent();
        $apptId = $this->makeAppointment($number, '2026-09-06 02:00:00');
        $this->checkInAppointment($apptId);

        $encounterId = $this->encounterIdFor($number);
        $this->assertNotNull($encounterId, 'appointment check-in must open an encounter');

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
        $apptId = $this->makeAppointment($number, '2026-09-06 02:10:00');
        $this->checkInAppointment($apptId);
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
}
