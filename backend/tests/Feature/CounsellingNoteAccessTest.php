<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * The counsellor-vs-counsellor authorization boundary for session notes
 * (counselling audit 2026-09-03, F2).
 *
 * Before the fix, CounsellingPolicy::canOnRecord() returned true for ANY
 * holder of `counselling.records.write` — which the `counsellor` group
 * grants to every member — so the `counsellor_user_id` ownership branch
 * was unreachable and any counsellor could decrypt any other counsellor's
 * session notes. The remediated rule:
 *
 *   - readNotes / writeNotes / close are OWN-SESSION for counsellors;
 *   - `counselling.records.read_any` (guidance_supervisor + superadmin)
 *     is
 *     the deliberate, audited oversight path;
 *   - POST sessions/{id}/reassign transfers ownership so coverage is a
 *     recorded operational act instead of a standing exception.
 *
 * These tests drive the real routes end-to-end so the boundary is proven
 * through filters, policy, service and the audit chain — the suite that
 * existed before covered none of it.
 */
final class CounsellingNoteAccessTest extends FeatureTestCase
{
    private const KEY = 'f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3';

    /** @var array{token:string, userId:int, email:string} */
    private array $counsellorA = [];

    /** @var array{token:string, userId:int, email:string} */
    private array $counsellorB = [];

    /** @var array{token:string, userId:int, email:string} */
    private array $supervisor = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The note write/read round-trip encrypts server-side; the
        // feature bootstrap does not provide COUNSELLING_KEY (local .env
        // is not committed, CI has none). Pin a per-suite key like the
        // unit suite does (EncryptionServiceTest).
        putenv('COUNSELLING_KEY=' . self::KEY);
        putenv('COUNSELLING_KEY_VERSION=1');

        $this->counsellorA = $this->login(['counsellor']);
        $this->counsellorB = $this->login(['counsellor']);
        $this->supervisor  = $this->login(['guidance_supervisor']);
    }

    protected function tearDown(): void
    {
        putenv('COUNSELLING_KEY');
        putenv('COUNSELLING_KEY_VERSION');
        parent::tearDown();
    }

    /**
     * A session opened by counsellor A with a note written by A, on a
     * throwaway school id (unregistered walk-in: openSession accepts
     * unknown identifiers and stores them as-is).
     *
     * @return array{id:int}
     */
    private function sessionWithNoteByA(): array
    {
        $schoolId = 'C-' . bin2hex(random_bytes(5));

        $res = $this->authed($this->counsellorA['token'], 'post', 'api/v1/counselling/sessions', [
            'patient_school_id' => $schoolId,
        ]);
        $res->assertStatus(201);
        $body = $this->envelope($res);
        $sessionId = (int) $body['data']['id'];

        $res = $this->authed($this->counsellorA['token'], 'post', "api/v1/counselling/sessions/{$sessionId}/notes", [
            'plaintext' => 'Boundary-test note written by counsellor A.',
        ]);
        $res->assertStatus(201);

        return ['id' => $sessionId];
    }

    public function testCounsellorBCannotReadCounsellorANotes(): void
    {
        $session = $this->sessionWithNoteByA();

        $res = $this->authed($this->counsellorB['token'], 'get', "api/v1/counselling/sessions/{$session['id']}/notes");

        $res->assertStatus(403);
        $this->assertErrorCode('rbac.record.forbidden', $res);
    }

    public function testCounsellorBCannotWriteOrCloseCounsellorASession(): void
    {
        $session = $this->sessionWithNoteByA();

        $write = $this->authed($this->counsellorB['token'], 'post', "api/v1/counselling/sessions/{$session['id']}/notes", [
            'plaintext' => 'B should not be able to write here.',
        ]);
        $write->assertStatus(403);

        $close = $this->authed($this->counsellorB['token'], 'post', "api/v1/counselling/sessions/{$session['id']}/close");
        $close->assertStatus(403);
    }

    public function testOwnerStillReadsOwnNotes(): void
    {
        $session = $this->sessionWithNoteByA();

        $res = $this->authed($this->counsellorA['token'], 'get', "api/v1/counselling/sessions/{$session['id']}/notes");

        $res->assertStatus(200);
        $body = $this->envelope($res);
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['data']);
        $this->assertNotEmpty($body['data'], 'The note A wrote must come back to A.');
    }

    public function testSupervisorOversightReadSucceedsAndIsAudited(): void
    {
        $session = $this->sessionWithNoteByA();

        $res = $this->authed($this->supervisor['token'], 'get', "api/v1/counselling/sessions/{$session['id']}/notes");

        $res->assertStatus(200);
        $body = $this->envelope($res);
        $this->assertTrue($body['success']);

        // The decrypt must land in the audit chain — the sensitive-read
        // audit is what makes the oversight path defensible. Events sit
        // in audit_outbox until the spark drain moves them to
        // audit_events, so assert on the outbox.
        $row = db_connect()->table('audit_outbox')->where('action_code', 'counselling.notes_read')->where('entity_type', 'counselling_notes')->where('entity_id', $session['id'])->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $this->assertNotNull($row, 'counselling.notes_read audit event missing for the supervisor read.');
        $this->assertSame((string) $this->supervisor['userId'], (string) $row['actor_user_id']);
    }

    public function testReassignTransfersOwnershipToCounsellorB(): void
    {
        $session = $this->sessionWithNoteByA();

        // Only team-manage/read_any holders may reassign — a plain
        // counsellor must be refused.
        $forbidden = $this->authed($this->counsellorB['token'], 'post', "api/v1/counselling/sessions/{$session['id']}/reassign", [
            'counsellor_user_id' => $this->counsellorB['userId'],
        ]);
        $forbidden->assertStatus(403);

        // The supervisor reassigns to B; the act is audited. Events sit
        // in audit_outbox until the spark drain moves them to the
        // hash-chained audit_events table, so assert on the outbox.
        $res = $this->authed($this->supervisor['token'], 'post', "api/v1/counselling/sessions/{$session['id']}/reassign", [
            'counsellor_user_id' => $this->counsellorB['userId'],
        ]);
        $res->assertStatus(200);
        $body = $this->envelope($res);
        $this->assertSame($this->counsellorB['userId'], (int) $body['data']['counsellor_user_id']);

        $row = db_connect()->table('audit_outbox')->where('action_code', 'counselling.session_reassigned')->where('entity_type', 'counselling_sessions')->where('entity_id', $session['id'])->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $this->assertNotNull($row, 'counselling.session_reassigned audit event missing.');
        $this->assertSame((string) $this->supervisor['userId'], (string) $row['actor_user_id']);

        // B now owns the session: reads succeed where they 403'd before.
        $read = $this->authed($this->counsellorB['token'], 'get', "api/v1/counselling/sessions/{$session['id']}/notes");
        $read->assertStatus(200);

        // ...and A has lost the access they started with.
        $former = $this->authed($this->counsellorA['token'], 'get', "api/v1/counselling/sessions/{$session['id']}/notes");
        $former->assertStatus(403);
    }

    public function testNoteAmendmentIsAppendedAndPointsToParent(): void
    {
        $session = $this->sessionWithNoteByA();

        // Read the initial note to get its id.
        $read = $this->authed($this->counsellorA['token'], 'get', "api/v1/counselling/sessions/{$session['id']}/notes");
        $initialNotes = $this->envelope($read)['data']['notes'];
        $parentNoteId = (int) $initialNotes[0]['id'];

        // Write an amendment pointing at the parent.
        $res = $this->authed($this->counsellorA['token'], 'post', "api/v1/counselling/sessions/{$session['id']}/notes", [
            'plaintext'          => 'Amended: patient confirmed they are actually 2nd year.',
            'supersedes_note_id' => $parentNoteId,
        ]);
        $res->assertStatus(201);
        $body = $this->envelope($res);
        $this->assertSame($parentNoteId, $body['data']['supersedes_note_id']);

        // Both notes exist in the history — corrections happen by
        // amendment, never mutation (F15).
        $after = $this->authed($this->counsellorA['token'], 'get', "api/v1/counselling/sessions/{$session['id']}/notes");
        $all = $this->envelope($after)['data']['notes'];
        $this->assertCount(2, $all);
        $this->assertSame($parentNoteId, $all[0]['supersedes_note_id']);
        $this->assertNull($all[1]['supersedes_note_id']);
    }

    public function testCounsellorCannotArchiveSession(): void
    {
        $session = $this->sessionWithNoteByA();

        // Plain counsellors cannot archive — mistakes on wrong
        // patients must surface to the supervisor (F15).
        $res = $this->authed($this->counsellorA['token'], 'post', "api/v1/counselling/sessions/{$session['id']}/archive");
        $res->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:counselling.records.soft_delete', $res);
    }

    public function testSupervisorCanArchiveAndUnarchiveSession(): void
    {
        $session = $this->sessionWithNoteByA();

        // Archive: 200, session's archived_at is stamped, audited.
        $res = $this->authed($this->supervisor['token'], 'post', "api/v1/counselling/sessions/{$session['id']}/archive");
        $res->assertStatus(200);

        $row = db_connect()->table('counselling_sessions')->where('id', $session['id'])->get()->getRowArray();
        $this->assertNotNull($row['archived_at']);

        $audit = db_connect()->table('audit_outbox')->where('action_code', 'counselling.session_archived')->where('entity_id', $session['id'])->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $this->assertNotNull($audit);

        // Archived session vanishes from the operational list for counsellors.
        $list = $this->authed($this->counsellorA['token'], 'get', 'api/v1/counselling/sessions');
        $ids = array_column($this->envelope($list)['data'], 'id');
        $this->assertNotContains($session['id'], $ids);

        // Unarchive: restores it.
        $restore = $this->authed($this->supervisor['token'], 'post', "api/v1/counselling/sessions/{$session['id']}/unarchive");
        $restore->assertStatus(200);

        $restoredRow = db_connect()->table('counselling_sessions')->where('id', $session['id'])->get()->getRowArray();
        $this->assertNull($restoredRow['archived_at']);
    }

    public function testPurgeCommandRefusesToRunWithoutRetentionPeriod(): void
    {
        putenv('COUNSELLING_RETENTION_DAYS');
        unset($_SERVER['COUNSELLING_RETENTION_DAYS'], $_ENV['COUNSELLING_RETENTION_DAYS']);

        $command = new \App\Commands\CounsellingPurge(service('logger'), service('commands'));
        $code = $command->run([]);
        $this->assertSame(1, $code, 'Purge command must refuse to run when COUNSELLING_RETENTION_DAYS is unset.');
    }

    public function testPastDateBookingIsRefused(): void
    {
        $student = $this->createUser([], 'student+' . bin2hex(random_bytes(4)) . '@feature.test');
        $studentNum = 'SN-' . bin2hex(random_bytes(4));
        db_connect()->table('users')->where('id', $student['id'])->update(['student_number' => $studentNum]);

        // Attempt booking in the past
        $res = $this->authed($this->counsellorA['token'], 'post', 'api/v1/counselling/appointments', [
            'patient_school_id' => $studentNum,
            'appointment_date'  => '2020-01-01',
            'start_time'        => '09:00:00',
            'end_time'          => '10:00:00',
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);
    }

    public function testUnregisteredPatientBookingIsRefused(): void
    {
        $futureDate = (new \DateTimeImmutable('+7 days'))->format('Y-m-d');
        $res = $this->authed($this->counsellorA['token'], 'post', 'api/v1/counselling/appointments', [
            'patient_school_id' => 'NONEXISTENT-SCHOOL-ID',
            'appointment_date'  => $futureDate,
            'start_time'        => '09:00:00',
            'end_time'          => '10:00:00',
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('patient.not_found', $res);
    }

    public function testQueueReassignTransfersAssignedLane(): void
    {
        $student = $this->createUser([], 'student+' . bin2hex(random_bytes(4)) . '@feature.test');
        $studentNum = 'SN-' . bin2hex(random_bytes(4));
        db_connect()->table('users')->where('id', $student['id'])->update(['student_number' => $studentNum]);

        // Manually plant an entry assigned to counsellor A
        $db = db_connect();
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $now = gmdate('Y-m-d H:i:s');
        $maxPos = (int) ($db->table('counselling_queue_entries')->where('queue_date', $today)->selectMax('position')->get()->getRowArray()['position'] ?? 0);
        $db->table('counselling_queue_entries')->insert([
            'tenant_id'                   => 1,
            'patient_user_id'             => $student['id'],
            'patient_school_id'           => $studentNum,
            'assigned_counsellor_user_id' => $this->counsellorA['userId'],
            'purpose'                     => 'Testing stranded lane',
            'status'                      => 'waiting',
            'queue_date'                  => $today,
            'position'                    => $maxPos + 1,
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ]);
        $queueId = (int) $db->insertID();

        // Reassign to counsellor B
        $res = $this->authed($this->supervisor['token'], 'post', "api/v1/counselling/queue/{$queueId}/reassign", [
            'assigned_counsellor_user_id' => $this->counsellorB['userId'],
        ]);
        $res->assertStatus(200);
        $body = $this->envelope($res);
        $this->assertSame($this->counsellorB['userId'], $body['data']['assigned_counsellor_user_id']);

        // Check audit event
        $audit = $db->table('audit_outbox')->where('action_code', 'counselling.queue_reassigned')->where('entity_id', $queueId)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $this->assertNotNull($audit);

        // Supervisor can also transition it directly even when assigned to counsellor B
        $transition = $this->authed($this->supervisor['token'], 'post', "api/v1/counselling/queue/{$queueId}/transition", [
            'action' => 'skip',
        ]);
        $transition->assertStatus(200);
    }
}
