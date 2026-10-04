<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Shared\ManilaDay;
use Modules\Clinic\Services\QueueService;

/**
 * Covers the October 2026 skip / recall-window workflow end to end:
 *
 *   skip (called → skipped, 60-minute deadline stamped)
 *   → Skipped Patients feed
 *   → return (skipped → waiting) / recall (skipped → called)
 *   → expiry sweep (skipped → done/no_show + encounter closed/no_show)
 *
 * Fixtures check an appointment in through the real transition endpoint
 * (staff action), which opens the encounter and queues it on today's
 * Manila date — the same path `EncounterNoShowTest` uses.
 *
 * Time is controlled by writing `skip_deadline_at` directly (the column
 * the sweep reads) rather than sleeping: the service's own
 * `SKIP_WINDOW_MINUTES` is asserted separately, and expiry tests need to
 * land on either side of the deadline deterministically.
 */
final class QueueSkipWorkflowTest extends FeatureTestCase
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
            'first_name'     => 'Skip',
            'last_name'      => 'Window',
            'course'         => 'BSIT',
            'year_level'     => 1,
        ], 201);
        return $number;
    }

    /**
     * Open + queue a visit for a fresh student and return the queue row
     * id, its encounter id, and the student number.
     *
     * @return array{queueId:int, encounterId:int, student:string}
     */
    private function queueEntryFor(string $scheduledAtUtc): array
    {
        $number = $this->makeStudent();

        $appt = $this->postJson('api/v1/clinic/appointments', [
            'patient_school_id' => $number,
            'provider_user_id'  => $this->admin['userId'],
            'scheduled_at'      => $scheduledAtUtc,
            'reason'            => 'Skip window regression',
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
     * Put the entry into `called` as fixture setup.
     *
     * NOT via `POST /queue/call-next`: that picks the lowest-position
     * WAITING entry across the whole Manila day, and this suite shares
     * one database across tests (see FeatureTestCase — migrations run
     * once, nothing truncates), so a leftover row from an earlier test
     * would be called instead. `callNext` itself is covered by the
     * existing queue tests and `QueueFifoContractTest`; this file is
     * about the skip window.
     */
    private function markCalled(int $queueId): void
    {
        $this->db->table('clinic_queue_entries')->where('id', $queueId)->update([
            'status'    => 'called',
            'called_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed> */
    private function queueRow(int $queueId): array
    {
        $row = $this->db->table('clinic_queue_entries')->where('id', $queueId)->get()->getRowArray();
        $this->assertNotNull($row, "queue entry #{$queueId} must exist");
        return $row;
    }

    /**
     * Rewrite the deadline relative to now, so expiry tests are
     * deterministic without sleeping. `$minutes` is added to the
     * current UTC time.
     */
    private function setDeadline(int $queueId, int $minutes): void
    {
        $this->db->table('clinic_queue_entries')->where('id', $queueId)->update([
            'skip_deadline_at' => gmdate('Y-m-d H:i:s', time() + ($minutes * 60)),
        ]);
    }

    /**
     * Terminalize every OTHER active queue row for today's Manila date.
     *
     * The single "now serving" rule is inherently GLOBAL — `recall`
     * refuses while any entry is called/in_session — and this suite
     * shares one database across tests and runs (nothing truncates).
     * A leftover `called` row from an interrupted earlier run would
     * otherwise fail the happy path before the fixture even acts, so
     * the test that exercises the guard must first establish a free
     * slot. Leftovers become `done` without an outcome, which is how
     * the system itself closes stale rows.
     */
    private function clearActiveSlots(?int $exceptId = null): void
    {
        $builder = $this->db->table('clinic_queue_entries')
            ->where('queue_date', ManilaDay::today())
            ->whereIn('status', ['called', 'in_session']);
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }
        $builder->update([
            'status'      => 'done',
            'finished_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testSkipStampsA60MinuteWindowAndLeavesTheEncounterOpen(): void
    {
        $entry = $this->queueEntryFor('2026-09-06 02:00:00');
        $this->markCalled($entry['queueId']);

        $body = $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'skip']);

        // The row comes back as skipped, with a live deadline.
        $this->assertSame('skipped', $body['data']['status']);
        $this->assertNotNull($body['data']['skipped_at']);
        $this->assertNotNull($body['data']['skip_deadline_at']);

        $row = $this->queueRow($entry['queueId']);
        $this->assertSame('skipped', $row['status']);

        // The deadline is exactly SKIP_WINDOW_MINUTES after skipped_at —
        // the window is stored, not inferred, so a reload cannot reset it.
        $expected = gmdate(
            'Y-m-d H:i:s',
            strtotime((string) $row['skipped_at']) + (QueueService::SKIP_WINDOW_MINUTES * 60),
        );
        $this->assertSame($expected, (string) $row['skip_deadline_at']);

        // The encounter is untouched: skipping is not a no-show.
        $enc = $this->db->table('clinic_encounters')->where('id', $entry['encounterId'])->get()->getRowArray();
        $this->assertSame('open', $enc['status']);
        $this->assertNull($enc['outcome']);
    }

    public function testSkippedFeedListsActiveSkipsWithDerivedStatus(): void
    {
        $entry = $this->queueEntryFor('2026-09-06 02:10:00');
        $this->markCalled($entry['queueId']);
        $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'skip']);

        $feed = $this->getJson('api/v1/clinic/queue/skipped');

        $this->assertArrayHasKey('server_now', $feed['data']);
        $rows = $feed['data']['data'];
        $this->assertIsArray($rows);

        $match = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === $entry['queueId']) {
                $match = $r;
                break;
            }
        }
        $this->assertNotNull($match, 'the skipped entry must appear in the Skipped Patients feed');
        $this->assertSame('skipped', $match['status']);
        $this->assertSame($entry['student'], $match['patient_school_id']);
        $this->assertSame($entry['encounterId'], $match['encounter_id']);
        $this->assertNotNull($match['skip_deadline_at']);
    }

    public function testReturnToQueueStopsTheCountdownAndRestoresWaiting(): void
    {
        $entry = $this->queueEntryFor('2026-09-06 02:20:00');
        $this->markCalled($entry['queueId']);
        $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'skip']);

        $body = $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'return']);

        $this->assertSame('waiting', $body['data']['status']);
        $this->assertNotNull($body['data']['returned_at']);

        $row = $this->queueRow($entry['queueId']);
        $this->assertSame('waiting', $row['status']);
        $this->assertNotNull($row['returned_at']);

        // The encounter stays open — the patient is back in line, not gone.
        $enc = $this->db->table('clinic_encounters')->where('id', $entry['encounterId'])->get()->getRowArray();
        $this->assertSame('open', $enc['status']);

        // The module now reports `returned`, and the sweep must not
        // touch a row that is no longer `skipped`.
        $feed = $this->getJson('api/v1/clinic/queue/skipped');
        foreach ($feed['data']['data'] as $r) {
            if ((int) $r['id'] === $entry['queueId']) {
                $this->assertSame('returned', $r['status']);
            }
        }
    }

    public function testRecallCallsThePatientAgainAndBlocksWhileAnotherSlotIsActive(): void
    {
        $entry = $this->queueEntryFor('2026-09-06 02:30:00');
        $this->markCalled($entry['queueId']);
        $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'skip']);

        // A free "now serving" slot is a precondition of the happy path,
        // not something the fixture can assume on a shared DB.
        $this->clearActiveSlots($entry['queueId']);

        // Recall with the slot free → called.
        $body = $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'recall']);
        $this->assertSame('called', $body['data']['status']);
        $this->assertNotNull($body['data']['returned_at']);

        // Now a SECOND patient is skipped while the first still holds the
        // "now serving" slot: recalling them must be refused by the
        // single-serving rule (same error as callNext).
        $second = $this->queueEntryFor('2026-09-06 02:40:00');
        $this->markCalled($second['queueId']);
        $this->postJson("api/v1/clinic/queue/{$second['queueId']}/transition", ['action' => 'skip']);

        $res = $this->authed(
            $this->admin['token'],
            'post',
            "api/v1/clinic/queue/{$second['queueId']}/transition",
            ['action' => 'recall'],
        );
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.queue.already_active', $res);
    }

    public function testSweepMarksAnExpiredSkipNoShowAndCascadesTheEncounter(): void
    {
        $entry = $this->queueEntryFor('2026-09-06 02:50:00');
        $this->markCalled($entry['queueId']);
        $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'skip']);

        // Deadline moved one minute into the past — the sweep must pick
        // it up on the next read.
        $this->setDeadline($entry['queueId'], -1);

        $feed = $this->getJson('api/v1/clinic/queue/skipped');

        $row = $this->queueRow($entry['queueId']);
        $this->assertSame('done', $row['status']);
        $this->assertSame('no_show', $row['outcome']);
        $this->assertNotNull($row['finished_at']);

        $enc = $this->db->table('clinic_encounters')->where('id', $entry['encounterId'])->get()->getRowArray();
        $this->assertSame('closed', $enc['status']);
        $this->assertSame('no_show', $enc['outcome']);

        // Authorized clinic staff are notified in-app: one outbox row per
        // clinic.queue.manage holder, not per viewer.
        $rows = $this->db->table('notification_outbox')
            ->select('recipient_user_id, context_json')
            ->where('template_code', 'queue.skip_expired')
            ->get()->getResultArray();
        $forEntry = array_values(array_filter(
            $rows,
            static fn (array $r): bool => str_contains((string) $r['context_json'], 'queue#' . $entry['queueId']),
        ));
        $this->assertNotEmpty($forEntry, 'the auto no-show must notify clinic staff');
        $this->assertContains(
            $this->admin['userId'],
            array_map(static fn (array $r): int => (int) $r['recipient_user_id'], $forEntry),
        );

        // The module reports the resolution instead of a live countdown.
        foreach ($feed['data']['data'] as $r) {
            if ((int) $r['id'] === $entry['queueId']) {
                $this->assertSame('no_show', $r['status']);
            }
        }
    }

    public function testReturnAfterTheDeadlineIsRejectedEvenBeforeTheSweepRuns(): void
    {
        $entry = $this->queueEntryFor('2026-09-06 03:00:00');
        $this->markCalled($entry['queueId']);
        $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'skip']);

        // Expired on paper, but no sweep has run yet — the transition
        // endpoint itself must validate the deadline.
        $this->setDeadline($entry['queueId'], -1);

        $res = $this->authed(
            $this->admin['token'],
            'post',
            "api/v1/clinic/queue/{$entry['queueId']}/transition",
            ['action' => 'return'],
        );
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.queue.skip_window_expired', $res);

        // Nothing was written by the rejected attempt.
        $row = $this->queueRow($entry['queueId']);
        $this->assertSame('skipped', $row['status']);
        $this->assertNull($row['returned_at']);
    }

    public function testExpiryResolvesOnlyOnceEvenAcrossRepeatedSweeps(): void
    {
        $entry = $this->queueEntryFor('2026-09-06 03:10:00');
        $this->markCalled($entry['queueId']);
        $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'skip']);
        $this->setDeadline($entry['queueId'], -1);

        // Three consecutive reads / sweeps — the transition must happen
        // exactly once and the audit trail must not accumulate.
        $this->getJson('api/v1/clinic/queue');
        $this->getJson('api/v1/clinic/queue/skipped');
        $this->getJson('api/v1/clinic/queue');

        $row = $this->queueRow($entry['queueId']);
        $this->assertSame('done', $row['status']);
        $this->assertSame('no_show', $row['outcome']);

        // The finished_at stamp is stable — a second sweep must not
        // rewrite it (which would move the recorded resolution time).
        $firstFinished = (string) $row['finished_at'];
        $this->getJson('api/v1/clinic/queue/skipped');
        $this->assertSame($firstFinished, (string) $this->queueRow($entry['queueId'])['finished_at']);

        $events = $this->db->table('audit_outbox')
            ->where('entity_type', 'clinic_queue_entries')
            ->where('entity_id', $entry['queueId'])
            ->where('action_code', 'clinic.queue_skip_expired')
            ->countAllResults();
        $this->assertSame(1, $events, 'expiry must be audited exactly once');

        // ... and the staff fan-out must not repeat either. The fan-out
        // legitimately produces one row per clinic.queue.manage holder
        // (thousands on this shared test DB), so the assertion is that a
        // second sweep adds NOTHING — not an absolute count.
        $noticeCount = static function () use ($entry): int {
            $n = 0;
            foreach (db_connect()->table('notification_outbox')
                ->select('context_json')
                ->where('template_code', 'queue.skip_expired')
                ->get()->getResultArray() as $r) {
                if (str_contains((string) $r['context_json'], 'queue#' . $entry['queueId'])) {
                    $n++;
                }
            }
            return $n;
        };

        $before = $noticeCount();
        $this->assertGreaterThan(0, $before, 'the auto no-show must notify clinic staff');
        $this->getJson('api/v1/clinic/queue/skipped');
        $this->assertSame($before, $noticeCount(), 'a second sweep must not add duplicate notifications');
    }

    public function testManualNoShowAcceptsASkippedQueueEntry(): void
    {
        $entry = $this->queueEntryFor('2026-09-06 03:20:00');
        $this->markCalled($entry['queueId']);
        $this->postJson("api/v1/clinic/queue/{$entry['queueId']}/transition", ['action' => 'skip']);

        // Staff resolve the visit by hand before the window lapses.
        $body = $this->postJson("api/v1/clinic/encounters/{$entry['encounterId']}/no-show", []);

        $this->assertSame('closed', $body['data']['status']);
        $this->assertSame('no_show', $body['data']['outcome']);

        $row = $this->queueRow($entry['queueId']);
        $this->assertSame('done', $row['status']);
        $this->assertSame('no_show', $row['outcome']);
    }

    public function testUnknownTransitionActionIsRejected(): void
    {
        $entry = $this->queueEntryFor('2026-09-06 03:30:00');

        $res = $this->authed(
            $this->admin['token'],
            'post',
            "api/v1/clinic/queue/{$entry['queueId']}/transition",
            ['action' => 'teleport'],
        );
        $res->assertStatus(422);
    }
}
