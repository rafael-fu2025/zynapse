<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Covers the October 2026 "Archive" action on finished clinic
 * encounters.
 *
 * Archive is list hygiene, not a clinical transition: the record and its
 * children stay intact, and `archived_at` is the read filter that moves
 * the row from Encounters to the Archived Encounters view. This suite
 * pins the three things that can regress:
 *
 *   1. the gate — only a finished (`closed` / `referred`) encounter may
 *      be archived, never an in-flight `open` one;
 *   2. the move — an archived row leaves BOTH the active list and the
 *      queue board, and shows up in the `status=archived` slice;
 *   3. idempotency — double-archiving (and restoring a live row) is a
 *      no-op rather than an error or a duplicate audit entry.
 *
 * Fixtures check an appointment in through the real transition endpoint
 * (staff action), which opens the encounter and queues it on today's
 * Manila date — the same path `EncounterNoShowTest` uses.
 */
final class EncounterArchiveTest extends FeatureTestCase
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

    /** @return array<string, mixed> */
    private function getJson(string $route, int $expect = 200): array
    {
        $res = $this->authed($this->admin['token'], 'get', $route);
        $res->assertStatus($expect);
        return $this->envelope($res);
    }

    private function makeStudent(): string
    {
        $number = '2026' . random_int(100000, 999999);
        $this->postJson('api/v1/clinic/students', [
            'student_number' => $number,
            'first_name'     => 'Archive',
            'last_name'      => 'Hygiene',
            'course'         => 'BSIT',
            'year_level'     => 1,
        ], 201);
        return $number;
    }

    /**
     * Open a queued encounter for a fresh student.
     *
     * @return array{queueId:int, encounterId:int, student:string}
     */
    private function openEncounter(): array
    {
        $number = $this->makeStudent();

        $appt = $this->postJson('api/v1/clinic/appointments', [
            'patient_school_id' => $number,
            'provider_user_id'  => $this->admin['userId'],
            'scheduled_at'      => '2026-09-06 04:00:00',
            'reason'            => 'Archive regression',
        ], 201);
        $apptId = (int) $appt['data']['id'];

        $this->postJson("api/v1/clinic/appointments/{$apptId}/transition", ['status' => 'checked_in']);

        $row = $this->db->table('clinic_queue_entries q')
            ->select('q.id, q.encounter_id')
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            ->where('e.patient_school_id', $number)
            ->orderBy('q.id', 'DESC')
            ->get()->getRowArray();
        $this->assertNotNull($row, 'check-in must open + queue an encounter');

        return ['queueId' => (int) $row['id'], 'encounterId' => (int) $row['encounter_id'], 'student' => $number];
    }

    /**
     * Finish the encounter the way a completed visit actually ends:
     * through the queue, so the encounter closes AND the appointment
     * completes. `diagnosis` is the hard completion gate (2026-09-25).
     */
    private function completeEncounter(array $entry): void
    {
        $this->db->table('clinic_encounters')->where('id', $entry['encounterId'])->update([
            'diagnosis' => 'Resolved — archive regression fixture.',
        ]);
        $this->db->table('clinic_queue_entries')->where('id', $entry['queueId'])->update([
            'status' => 'in_session', 'started_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'complete']);
    }

    /** @return array<string, mixed> */
    private function encounterRow(int $encounterId): array
    {
        $row = $this->db->table('clinic_encounters')->where('id', $encounterId)->get()->getRowArray();
        $this->assertNotNull($row, "encounter #{$encounterId} must exist");
        return $row;
    }

    public function testArchiveMovesAFinishedEncounterOutOfTheActiveList(): void
    {
        $entry = $this->openEncounter();
        $this->completeEncounter($entry);

        $body = $this->postJson("api/v1/clinic/encounters/{$entry['encounterId']}/archive", []);
        $this->assertNotNull($body['data']['archived_at'], 'the response must carry the archive stamp');

        $row = $this->encounterRow($entry['encounterId']);
        $this->assertNotNull($row['archived_at']);
        // The visit itself is untouched — archiving is not a status change.
        $this->assertSame('closed', $row['status']);

        // Gone from the default (active) list …
        $active = $this->getJson('api/v1/clinic/encounters?status=closed');
        foreach ($active['data'] as $e) {
            $this->assertNotSame($entry['encounterId'], (int) $e['id'], 'an archived row must leave the active list');
        }

        // … and present in the archived slice, stamped.
        $archived = $this->getJson('api/v1/clinic/encounters?status=archived');
        $ids = array_map(static fn (array $e): int => (int) $e['id'], $archived['data']);
        $this->assertContains($entry['encounterId'], $ids);
        foreach ($archived['data'] as $e) {
            if ((int) $e['id'] === $entry['encounterId']) {
                $this->assertNotNull($e['archived_at']);
                $this->assertSame($entry['student'], $e['patient_school_id']);
            }
        }
    }

    public function testArchiveRemovesTheRowFromTheQueueBoard(): void
    {
        $entry = $this->openEncounter();
        $this->completeEncounter($entry);

        // The Done row is on today's queue before archiving …
        $before = $this->getJson('api/v1/clinic/queue');
        $idsBefore = array_map(static fn (array $q): int => (int) $q['id'], $before['data']);
        $this->assertContains($entry['queueId'], $idsBefore);

        $this->postJson("api/v1/clinic/encounters/{$entry['encounterId']}/archive", []);

        // … and off it afterwards, so the operator's board matches the list.
        $after = $this->getJson('api/v1/clinic/queue');
        $idsAfter = array_map(static fn (array $q): int => (int) $q['id'], $after['data']);
        $this->assertNotContains($entry['queueId'], $idsAfter);
    }

    public function testOpenEncounterCannotBeArchived(): void
    {
        $entry = $this->openEncounter();

        $res = $this->authed(
            $this->admin['token'],
            'post',
            "api/v1/clinic/encounters/{$entry['encounterId']}/archive",
            [],
        );
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.clinic.encounter_not_finished', $res);

        $this->assertNull($this->encounterRow($entry['encounterId'])['archived_at']);
    }

    public function testArchiveIsIdempotent(): void
    {
        $entry = $this->openEncounter();
        $this->completeEncounter($entry);

        $this->postJson("api/v1/clinic/encounters/{$entry['encounterId']}/archive", []);
        $stamp = (string) $this->encounterRow($entry['encounterId'])['archived_at'];

        // A second archive (stale tab, double-click) succeeds and does NOT
        // move the recorded archive time.
        $body = $this->postJson("api/v1/clinic/encounters/{$entry['encounterId']}/archive", []);
        $this->assertSame($stamp, (string) $body['data']['archived_at']);
        $this->assertSame($stamp, (string) $this->encounterRow($entry['encounterId'])['archived_at']);

        $events = $this->db->table('audit_outbox')
            ->where('entity_type', 'clinic_encounters')
            ->where('entity_id', $entry['encounterId'])
            ->where('action_code', 'clinic.encounter_archived')
            ->countAllResults();
        $this->assertSame(1, $events, 'archiving twice must not write a second audit entry');
    }

    public function testRestoreReturnsTheEncounterToTheActiveList(): void
    {
        $entry = $this->openEncounter();
        $this->completeEncounter($entry);
        $this->postJson("api/v1/clinic/encounters/{$entry['encounterId']}/archive", []);

        $body = $this->postJson("api/v1/clinic/encounters/{$entry['encounterId']}/restore", []);
        $this->assertNull($body['data']['archived_at']);

        $this->assertNull($this->encounterRow($entry['encounterId'])['archived_at']);

        $active = $this->getJson('api/v1/clinic/encounters?status=closed');
        $ids = array_map(static fn (array $e): int => (int) $e['id'], $active['data']);
        $this->assertContains($entry['encounterId'], $ids);

        // Restoring a live encounter is a harmless no-op, not an error.
        $again = $this->postJson("api/v1/clinic/encounters/{$entry['encounterId']}/restore", []);
        $this->assertNull($again['data']['archived_at']);
    }

    public function testArchiveUnknownEncounterReturns404(): void
    {
        $res = $this->authed($this->admin['token'], 'post', 'api/v1/clinic/encounters/999999/archive', []);
        $res->assertStatus(404);
        $this->assertErrorCode('resource.not_found', $res);
    }

    public function testArchivedEncounterKeepsItsClinicalRecord(): void
    {
        $entry = $this->openEncounter();
        $this->completeEncounter($entry);

        $vitalsBefore = (int) $this->db->table('clinic_vitals')->where('encounter_id', $entry['encounterId'])->countAllResults();

        $this->postJson("api/v1/clinic/encounters/{$entry['encounterId']}/archive", []);

        // Archiving must never delete or orphan the clinical children.
        $this->assertSame(
            $vitalsBefore,
            (int) $this->db->table('clinic_vitals')->where('encounter_id', $entry['encounterId'])->countAllResults(),
        );
        $row = $this->encounterRow($entry['encounterId']);
        $this->assertNotNull($row['diagnosis']);
        $this->assertNotNull($row['closed_at']);
    }
}
