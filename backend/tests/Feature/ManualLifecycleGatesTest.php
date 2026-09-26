<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Manual lifecycle gates from the 2026-09-25 staff meeting.
 *
 * The meeting set three rules that the state machines now enforce:
 *
 *   1. An encounter completes only once its clinical record exists —
 *      the assessment/diagnosis note (missing vitals stay a warning).
 *   2. A clinic appointment completes only through its encounter
 *      (assessment first, then close); the book row cannot be flipped
 *      directly past an open visit.
 *   3. A Guidance session/appointment completes only once the session
 *      carries notes, and clinic attendance is staff-actioned (the
 *      T-15 auto check-in and the no-show aging sweep are gone — see
 *      AppointmentQueueParityContractTest).
 *
 * It also pins the chronological book: the clinic appointment list is
 * ordered by SCHEDULED time with the approval split (`provider=`
 * filter) that drives the Needs-action view, and announcement
 * `severity` round-trips for the red urgent treatment.
 */
final class ManualLifecycleGatesTest extends FeatureTestCase
{
    /**
     * Pin the note-encryption key: CounsellingNoteAccessTest unsets
     * COUNSELLING_KEY in its tearDown, and the guidance gates here write
     * notes (alphabetically later, same process).
     */
    private const COUNSELLING_KEY = '9a8b7c6d5e4f3a2b1c0d9e8f7a6b5c4d3e2f1a0b9c8d7e6f5a4b3c2d1e0f9a8b';

    /** @var array{token:string, userId:int, email:string} */
    private array $admin = [];

    /** @var array{token:string, userId:int, email:string} */
    private array $supervisor = [];

    /** @var array{token:string, userId:int, email:string} */
    private array $guidanceAdmin = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('COUNSELLING_KEY=' . self::COUNSELLING_KEY);
        putenv('COUNSELLING_KEY_VERSION=1');
        $this->admin = $this->login(['clinic_admin']);
        $this->supervisor = $this->login(['guidance_supervisor']);
        $this->guidanceAdmin = $this->login(['guidance_admin']);
    }

    protected function tearDown(): void
    {
        putenv('COUNSELLING_KEY');
        putenv('COUNSELLING_KEY_VERSION');
        parent::tearDown();
    }

    // ---------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function postJson(string $token, string $route, array $body, int $expect): array
    {
        $res = $this->authed($token, 'post', $route, $body);
        $res->assertStatus($expect);

        return $this->envelope($res);
    }

    /** A student with a registry number, backed by a real user row. */
    private function makeStudent(): array
    {
        $student = $this->createUser(['student'], 'student+' . bin2hex(random_bytes(4)) . '@feature.test');
        $studentNum = 'SN-' . bin2hex(random_bytes(4));
        db_connect()->table('users')->where('id', $student['id'])->update(['student_number' => $studentNum]);

        return ['id' => $student['id'], 'schoolId' => $studentNum];
    }

    // --------------------------------------------------- clinic gates

    public function testEncounterCloseRequiresAnAssessment(): void
    {
        $student = $this->makeStudent();

        $body = $this->postJson($this->admin['token'], 'api/v1/clinic/encounters', [
            'patient_school_id' => $student['schoolId'],
            'chief_complaint'   => 'Gate test',
        ], 201);
        $encounterId = (int) $body['data']['id'];

        // The gate: no diagnosis recorded yet.
        $early = $this->authed($this->admin['token'], 'post', "api/v1/clinic/encounters/{$encounterId}/close", []);
        $early->assertStatus(422);
        $this->assertErrorCode('validation.assessment_required', $early);

        // Record the assessment, then close.
        $this->postJson($this->admin['token'], "api/v1/clinic/encounters/{$encounterId}/assessment", [
            'diagnosis' => 'Gate-test diagnosis',
        ], 200);
        $this->postJson($this->admin['token'], "api/v1/clinic/encounters/{$encounterId}/close", [], 200);
    }

    public function testAppointmentCompletesThroughTheEncounterNotTheBook(): void
    {
        $student = $this->makeStudent();
        $slot = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

        $body = $this->postJson($this->admin['token'], 'api/v1/clinic/appointments', [
            'patient_school_id' => $student['schoolId'],
            'provider_user_id'  => $this->admin['userId'],
            'scheduled_at'      => $slot,
        ], 201);
        $appointmentId = (int) $body['data']['id'];

        $this->postJson($this->admin['token'], "api/v1/clinic/appointments/{$appointmentId}/transition", [
            'status' => 'checked_in',
        ], 200);

        // Completing the book row directly is refused: the visit is open
        // and its record does not exist yet.
        $early = $this->authed($this->admin['token'], 'post', "api/v1/clinic/appointments/{$appointmentId}/transition", [
            'status' => 'completed',
        ]);
        $early->assertStatus(422);
        $this->assertErrorCode('validation.assessment_required', $early);

        // Record, close — the encounter cascade completes the appointment.
        $show = $this->authed($this->admin['token'], 'get', "api/v1/clinic/appointments/{$appointmentId}");
        $encounterId = (int) ($this->envelope($show)['data']['encounter_id'] ?? 0);
        $this->assertGreaterThan(0, $encounterId);

        $this->postJson($this->admin['token'], "api/v1/clinic/encounters/{$encounterId}/assessment", [
            'diagnosis' => 'Gate-test diagnosis',
        ], 200);
        $this->postJson($this->admin['token'], "api/v1/clinic/encounters/{$encounterId}/close", [], 200);

        $show = $this->authed($this->admin['token'], 'get', "api/v1/clinic/appointments/{$appointmentId}");
        $this->assertSame('completed', (string) $this->envelope($show)['data']['status']);
    }

    // ---------------------------------------------- chronological book

    public function testClinicListIsChronologicalAndSplitsApprovalState(): void
    {
        $student = $this->makeStudent();
        $db = db_connect();
        $now = date('Y-m-d H:i:s');

        $rows = [
            ['scheduled_at' => gmdate('Y-m-d H:i:s', strtotime('+1 day')),  'provider' => null],
            ['scheduled_at' => gmdate('Y-m-d H:i:s', strtotime('+2 days')), 'provider' => $this->admin['userId']],
            ['scheduled_at' => gmdate('Y-m-d H:i:s', strtotime('-1 day')),  'provider' => $this->admin['userId'], 'status' => 'completed'],
        ];
        $ids = [];
        foreach ($rows as $row) {
            $db->table('clinic_appointments')->insert([
                'tenant_id'         => 1,
                'patient_school_id' => $student['schoolId'],
                'patient_user_id'   => $student['id'],
                'provider_user_id'  => $row['provider'],
                'scheduled_at'      => $row['scheduled_at'],
                'status'            => $row['status'] ?? 'scheduled',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $ids[] = (int) $db->insertID();
        }
        [$unassignedId, , $pastId] = $ids;

        $get = static function (FeatureTestCase $tc, string $token, string $query) {
            $res = $tc->authed($token, 'get', 'api/v1/clinic/appointments' . $query);
            $res->assertStatus(200);

            return array_map(static fn (array $r): int => (int) $r['id'], $tc->envelope($res)['data']);
        };

        // Upcoming + approved: the confirmed schedule, earliest first.
        $upcoming = $get($this, $this->admin['token'], '?scope=upcoming&provider=assigned');
        $this->assertContains($ids[1], $upcoming);
        $this->assertNotContains($unassignedId, $upcoming);
        $this->assertNotContains($pastId, $upcoming);

        // Needs action: only the unapproved portal booking.
        $needsAction = $get($this, $this->admin['token'], '?scope=upcoming&provider=unassigned');
        $this->assertContains($unassignedId, $needsAction);
        $this->assertNotContains($ids[1], $needsAction);

        // The whole book reads chronologically, never by booking time —
        // the book may carry other rows (seed data), so pin the relative
        // order of ours instead of the full list. limit=100 (the cap)
        // keeps every row on one page.
        $all = $get($this, $this->admin['token'], '?scope=all&limit=100');
        $posPast = array_search($pastId, $all, true);
        $posUnassigned = array_search($unassignedId, $all, true);
        $posFuture = array_search($ids[1], $all, true);
        foreach ([$posPast, $posUnassigned, $posFuture] as $pos) {
            $this->assertNotFalse($pos, 'All three test rows must appear in the book.');
        }
        $this->assertLessThan($posUnassigned, $posPast);
        $this->assertLessThan($posFuture, $posUnassigned);

        // Past slice: most recent first.
        $past = $get($this, $this->admin['token'], '?scope=past');
        $this->assertContains($pastId, $past);
        $this->assertNotContains($ids[1], $past);
    }

    // ------------------------------------------------ guidance gates

    public function testGuidanceQueueCompleteRequiresSessionNotes(): void
    {
        $student = $this->makeStudent();
        $db = db_connect();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $now = gmdate('Y-m-d H:i:s');

        $maxPos = (int) ($db->table('counselling_queue_entries')->where('queue_date', $today)->selectMax('position')->get()->getRowArray()['position'] ?? 0);
        $db->table('counselling_queue_entries')->insert([
            'tenant_id'         => 1,
            'patient_user_id'   => $student['id'],
            'patient_school_id' => $student['schoolId'],
            'purpose'           => 'Notes gate test',
            'status'            => 'waiting',
            'queue_date'        => $today,
            'position'          => $maxPos + 1,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $queueId = (int) $db->insertID();

        $this->postJson($this->supervisor['token'], "api/v1/counselling/queue/{$queueId}/transition", [
            'action' => 'start',
        ], 200);
        $row = $this->authed($this->supervisor['token'], 'get', 'api/v1/counselling/queue');
        $entry = null;
        foreach ($this->envelope($row)['data'] as $candidate) {
            if ((int) $candidate['id'] === $queueId) {
                $entry = $candidate;
            }
        }
        $this->assertNotNull($entry, 'Started entry must be on the board.');
        $sessionId = (int) $entry['counselling_session_id'];
        $this->assertGreaterThan(0, $sessionId);

        // The gate: completing a noteless session is refused.
        $early = $this->authed($this->supervisor['token'], 'post', "api/v1/counselling/queue/{$queueId}/transition", [
            'action' => 'complete',
        ]);
        $early->assertStatus(422);
        $this->assertErrorCode('validation.notes_required', $early);

        // Write the note, then complete.
        $this->postJson($this->supervisor['token'], "api/v1/counselling/sessions/{$sessionId}/notes", [
            'plaintext' => 'Gate-test session note.',
        ], 201);
        $this->postJson($this->supervisor['token'], "api/v1/counselling/queue/{$queueId}/transition", [
            'action' => 'complete',
        ], 200);
    }

    public function testGuidanceAppointmentCompleteRequiresNotesThroughTheQueueLink(): void
    {
        $student = $this->makeStudent();
        $db = db_connect();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $now = gmdate('Y-m-d H:i:s');

        $db->table('counselling_appointments')->insert([
            'tenant_id'           => 1,
            'patient_user_id'     => $student['id'],
            'patient_school_id'   => $student['schoolId'],
            'counsellor_user_id'  => $this->supervisor['userId'],
            'appointment_date'    => $today,
            'start_time'          => '09:00:00',
            'end_time'            => '10:00:00',
            'type'                => 'initial',
            'status'              => 'confirmed',
            'created_by_user_id'  => $this->supervisor['userId'],
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        $appointmentId = (int) $db->insertID();

        // No session exists yet: completing the booking is refused.
        $early = $this->authed($this->supervisor['token'], 'post', "api/v1/counselling/appointments/{$appointmentId}/transition", [
            'action' => 'complete',
        ]);
        $early->assertStatus(422);
        $this->assertErrorCode('validation.notes_required', $early);

        // Link a session through the queue entry (the appointment→session
        // hop), write the note, then complete.
        $this->postJson($this->supervisor['token'], 'api/v1/counselling/sessions', [
            'patient_school_id' => $student['schoolId'],
        ], 201);
        $sessionId = null;
        $list = $this->authed($this->supervisor['token'], 'get', 'api/v1/counselling/sessions?appointment_id=' . $appointmentId);
        $this->assertSame([], $this->envelope($list)['data'], 'A bare session is not yet reachable through the appointment.');

        $sessions = $this->authed($this->supervisor['token'], 'get', 'api/v1/counselling/sessions');
        foreach ($this->envelope($sessions)['data'] as $candidate) {
            if ((string) $candidate['patient_school_id'] === $student['schoolId']) {
                $sessionId = (int) $candidate['id'];
            }
        }
        $this->assertNotNull($sessionId);
        $this->postJson($this->supervisor['token'], "api/v1/counselling/sessions/{$sessionId}/notes", [
            'plaintext' => 'Gate-test appointment note.',
        ], 201);

        $maxPos = (int) ($db->table('counselling_queue_entries')->where('queue_date', $today)->selectMax('position')->get()->getRowArray()['position'] ?? 0);
        $db->table('counselling_queue_entries')->insert([
            'tenant_id'                   => 1,
            'patient_user_id'             => $student['id'],
            'patient_school_id'           => $student['schoolId'],
            'counselling_appointment_id'  => $appointmentId,
            'counselling_session_id'      => $sessionId,
            'assigned_counsellor_user_id' => $this->supervisor['userId'],
            'purpose'                     => 'Notes gate test',
            'status'                      => 'done',
            'queue_date'                  => $today,
            'position'                    => $maxPos + 1,
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ]);

        $done = $this->authed($this->supervisor['token'], 'post', "api/v1/counselling/appointments/{$appointmentId}/transition", [
            'action' => 'complete',
        ]);
        $done->assertStatus(200);
        $this->assertSame('completed', (string) $this->envelope($done)['data']['status']);
    }

    // --------------------------------------------------- severity

    public function testAnnouncementSeverityRoundTripAndValidation(): void
    {
        $created = $this->postJson($this->guidanceAdmin['token'], 'api/v1/counselling/announcements', [
            'title'    => 'Urgent notice ' . bin2hex(random_bytes(3)),
            'body'     => 'Red-outline treatment expected.',
            'audience' => 'all',
            'severity' => 'urgent',
        ], 201);
        $this->assertSame('urgent', (string) $created['data']['severity']);

        $bogus = $this->authed($this->guidanceAdmin['token'], 'post', 'api/v1/counselling/announcements', [
            'title'    => 'Bad severity',
            'body'     => 'Must be refused.',
            'audience' => 'all',
            'severity' => 'loud',
        ]);
        $bogus->assertStatus(422);

        $res = $this->authed($this->guidanceAdmin['token'], 'get', 'api/v1/counselling/announcements');
        $res->assertStatus(200);
        $rows = array_column($this->envelope($res)['data'], 'severity', 'id');
        $this->assertContains('urgent', $rows);
    }
}
