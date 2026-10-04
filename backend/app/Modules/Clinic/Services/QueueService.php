<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\ManilaDay;
use App\Modules\Shared\StateMachineException;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use App\Services\Notify\NotificationOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\Policies\ClinicPolicy;
use Throwable;

/**
 * QueueService — clinic walk-in queue (Phase 14, recycled from synapse_ag
 * ConsultationController queue actions).
 *
 * NOTE: Counselling has its own independent QueueService at
 * {@see \Modules\Counselling\Services\QueueService}. The two are
 * deliberately separate (different tables, transitions, and access
 * control) — they share the class name by convention only.
 *
 * Rules ported from the legacy module:
 *   - Positions are stable 1-based per day, assigned under a row lock
 *     so concurrent check-ins cannot collide.
 *   - Only ONE entry may be `called` at a time (the "now serving"
 *     slot); it must be started or skipped before the next call.
 *   - The public feed discloses ONLY position + display name; the
 *     school id is never exposed unauthenticated (masked fallback).
 */
final class QueueService extends BaseService
{
    /**
     * Recall grace window (October 2026 panel revision): a skipped
     * patient keeps their place for this long before the sweep marks
     * them no-show. Mirrored by the SPA countdown; the migration
     * (`QueueSkipWindow::SKIP_WINDOW_MINUTES`) and
     * `QueueSkipWindowContractTest` assert the three stay in lockstep.
     */
    public const SKIP_WINDOW_MINUTES = 60;

    private readonly EncounterCompletionService $completion;
    /** @var array<string, array<int, string>> action => allowed current statuses */
    private const TRANSITIONS = [
        'start'    => ['called'],
        'skip'     => ['called'],
        'complete' => ['in_session'],
        // Recall-window actions: only a skipped entry can be brought
        // back, and only while its deadline is still open (enforced
        // separately — the window is a time condition, not a status).
        'return'   => ['skipped'],
        'recall'   => ['skipped'],
    ];

    /** @var array<string, string> action => resulting status */
    private const RESULT = [
        'start'    => 'in_session',
        'skip'     => 'skipped',
        'complete' => 'done',
        'return'   => 'waiting',
        'recall'   => 'called',
    ];

    /**
     * @var array<string, string> action => audit action code. Explicit
     * rather than derived from RESULT: `clinic.queue_waiting` /
     * `clinic.queue_called` would read like status flips instead of the
     * staff actions they are.
     */
    private const AUDIT = [
        'start'    => 'clinic.queue_in_session',
        'skip'     => 'clinic.queue_skipped',
        'complete' => 'clinic.queue_done',
        'return'   => 'clinic.queue_returned',
        'recall'   => 'clinic.queue_recalled',
    ];

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly AppointmentService $appointments,
        private readonly ClinicService $clinic,
        private readonly NotificationOutboxService $notify,
        ?EncounterCompletionService $completion = null,
    ) {
        parent::__construct();
        $this->completion = $completion ?? new EncounterCompletionService($this->audit);
    }

    /**
     * Today's queue — staff view (full school ids).
     *
     * Side effect (2026-09-25): lazy end-of-day cleanup only — stale
     * `open` encounters whose `started_at` predates today are
     * auto-closed with `outcome='auto_closed'`, WITHOUT touching their
     * appointment's status (staff resolve those). The August 2026
     * lazy auto-check-in sweep was removed: attendance is a staff
     * action, never a timer.
     *
     * Side effect (October 2026): expired skip windows are swept so a
     * patient whose 60 minutes elapsed resolves to no-show even if
     * nobody opens the Skipped Patients module.
     *
     * @return array<int, array<string, mixed>>
     */
    public function today(): array
    {
        $this->policy->check('queueRead');

        $this->autoCloseEarlierOpenEncounters();
        $this->sweepExpiredSkips();

        return array_map(
            fn (array $r): array => $this->row($r),
            $this->todayRows(),
        );
    }

    /**
     * Skipped Patients module feed — today's skip cohort, active first.
     *
     * Every entry that was skipped today appears exactly once, with a
     * derived `status` the SPA renders directly:
     *   - `skipped`  — still inside the recall window; the countdown is
     *                  live and the row offers Return / Call Again /
     *                  Mark No-Show.
     *   - `returned` — staff brought the patient back (queue or recall)
     *                  before the deadline. Read-only history row.
     *   - `no_show`  — the window expired (swept automatically) or
     *                  staff marked the visit no-show. Read-only.
     *
     * Resolved rows stay visible for the rest of the Manila day so the
     * board explains where every skipped patient went; the encounter
     * itself lives on in the Closed tab. The `server_now` stamp lets the
     * SPA correct for client-clock skew when rendering countdowns.
     *
     * @return array{data: array<int, array<string, mixed>>, server_now: string}
     */
    public function skipped(): array
    {
        $this->policy->check('queueRead');

        // Opportunistic: resolve anything whose window already closed
        // before we answer, so the board never shows an expired row as
        // still actionable.
        $this->sweepExpiredSkips();

        $rows = $this->db->table('clinic_queue_entries q')
            ->select('q.id, q.encounter_id, q.position, q.status, q.outcome, q.skipped_at, q.skip_deadline_at, q.returned_at, q.called_at, q.started_at, q.finished_at, e.status AS encounter_status, e.patient_school_id, e.guest_name, e.chief_complaint, e.appointment_id, a.scheduled_at AS appointment_at, a.status AS appointment_status, u.first_name, u.last_name')
            ->where('q.tenant_id', CurrentTenant::id())
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            ->join(
                'users u',
                'u.id = e.patient_user_id'
                . ' OR (e.patient_user_id IS NULL'
                .   ' AND (u.student_number = e.patient_school_id'
                .     ' OR u.employee_number = e.patient_school_id))',
                'left',
            )
            ->join('clinic_appointments a', 'a.id = e.appointment_id', 'left')
            // An archived visit has left the board — its skip episode is
            // history, not an actionable window.
            ->where('e.archived_at', null)
            ->where('q.queue_date', $this->manilaToday())
            ->where('q.skipped_at IS NOT NULL', null, false)
            // Active rows first (soonest deadline at the top), then the
            // resolved ones. Raw expression: MySQL/MariaDB sort ENUMs by
            // declaration order, which puts `skipped` last, not first.
            ->orderBy("CASE WHEN q.`status` = 'skipped' THEN 0 ELSE 1 END", 'ASC', false)
            ->orderBy('q.skip_deadline_at', 'ASC')
            ->get()->getResultArray();

        return [
            'data'       => array_map(fn (array $r): array => $this->skippedRow($r), $rows),
            'server_now' => $this->utcNow(),
        ];
    }

    /**
     * Explicit Counselling → Clinic referral handoff. Idempotent by logical
     * referral id and by an already-active Clinic visit for the patient.
     *
     * @return array<string, mixed>
     */
    public function enqueueFromReferral(int $patientUserId, string $patientSchoolId, int $referralId): array
    {
        $this->policy->check('queueManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($patientUserId, $patientSchoolId, $referralId, $userId): array {
            $existingReferral = $this->db->table('clinic_queue_entries')
                ->select('id')
                ->where('clinic_queue_entries.tenant_id', CurrentTenant::id())
                ->where('referral_id', $referralId)
                ->get()->getRowArray();
            if ($existingReferral !== null) {
                return $this->getRow((int) $existingReferral['id']);
            }

            $active = $this->db->query(
                'SELECT q.`id` FROM `clinic_queue_entries` q'
                . ' INNER JOIN `clinic_encounters` e ON e.`id` = q.`encounter_id`'
                . ' WHERE q.`tenant_id` = ? AND e.`patient_school_id` = ? AND q.`status` IN (?, ?, ?)'
                . ' ORDER BY q.`id` ASC LIMIT 1 FOR UPDATE',
                [CurrentTenant::id(), $patientSchoolId, 'waiting', 'called', 'in_session'],
            )->getRowArray();
            if ($active !== null) {
                $this->db->table('clinic_queue_entries')->where('clinic_queue_entries.tenant_id', CurrentTenant::id())->where('id', (int) $active['id'])->update([
                    'referral_id' => $referralId,
                    'updated_at' => $this->utcNow(),
                ]);
                return $this->getRow((int) $active['id']);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_encounters')->insert([
                'tenant_id' => CurrentTenant::id(),
                'patient_user_id' => $patientUserId,
                'patient_school_id' => $patientSchoolId,
                'chief_complaint' => 'Referral handoff to Clinic (pending triage)',
                'status' => 'open',
                'attending_user_id' => $userId,
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $encounterId = (int) $this->db->insertID();
            $last = $this->db->query(
                'SELECT `position` FROM `clinic_queue_entries` WHERE `tenant_id` = ? AND `queue_date` = ?'
                . ' ORDER BY `position` DESC LIMIT 1 FOR UPDATE',
                [CurrentTenant::id(), $this->manilaToday()],
            )->getRowArray();
            $position = ($last !== null ? (int) $last['position'] : 0) + 1;
            $this->db->table('clinic_queue_entries')->insert([
                'tenant_id' => CurrentTenant::id(),
                'encounter_id' => $encounterId,
                'referral_id' => $referralId,
                'queue_date' => $this->manilaToday(),
                'position' => $position,
                'status' => 'waiting',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $queueId = (int) $this->db->insertID();
            $this->audit->enqueue('clinic.queue_referral_received', 'clinic_queue_entries', $queueId, $userId, [
                'resource_code' => 'referral#' . $referralId,
            ]);
            return $this->getRow($queueId);
        });
    }

    /** Fetch an already-associated handoff without creating or mutating a queue row. */
    public function getForHandoff(int $id): array
    {
        $this->policy->check('queueManage');
        return $this->getRow($id);
    }

    /**
     * End-of-day sweep — close every `open` encounter whose
     * `started_at` predates the Manila business day using the same
     * cascade as `ClinicService::autoCloseStaleEncounter()`.
     * Best-effort: a stale row that fails (e.g. encounter vanished
     * mid-sweep) is logged + skipped so the staff `today()` read
     * still succeeds.
     */
    private function autoCloseEarlierOpenEncounters(): int
    {
        $dayStart = ManilaDay::startOfDayUtcSql();

        $ids = $this->db->table('clinic_encounters')
            ->select('id')
            ->where('clinic_encounters.tenant_id', CurrentTenant::id())
            ->where('status', 'open')
            ->where('archived_at', null)
            ->where('started_at <', $dayStart)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $closed = 0;
        foreach ($ids as $r) {
            try {
                $this->clinic->autoCloseStaleEncounter((int) $r['id']);
                $closed++;
            } catch (Throwable $t) {
                log_message('warning', sprintf(
                    'QueueService::autoCloseEarlierOpenEncounters: id=%d skipped (%s)',
                    (int) $r['id'],
                    $t->getMessage(),
                ));
            }
        }
        return $closed;
    }

    /**
     * Call the next waiting patient (single "now serving" slot).
     *
     * Queue policy: strict FIFO by immutable daily `position`. Clinic and
     * referred patients use the same ordering rule; there is deliberately no
     * priority insertion or staff-controlled bypass. Emergency escalation is a
     * clinical workflow and must not silently reorder this operational queue.
     */
    public function callNext(): array
    {
        $this->policy->check('queueManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($userId): array {
            $today = $this->manilaToday();

            // Lock today's active entries and waiting FIFO in one pass.
            $rows = $this->db->query(
                'SELECT `id`, `status` FROM `clinic_queue_entries`'
                . ' WHERE `tenant_id` = ? AND `queue_date` = ? AND `status` IN (?, ?, ?)'
                . ' ORDER BY `position` ASC FOR UPDATE',
                [CurrentTenant::id(), $today, 'called', 'in_session', 'waiting'],
            )->getResultArray();

            foreach ($rows as $r) {
                if (in_array((string) $r['status'], ['called', 'in_session'], true)) {
                    throw new ApiException('statemachine.queue.already_active', 409, [
                        ['code' => 'statemachine.queue.already_active', 'message' => 'Clinic already has a patient called or in session.'],
                    ]);
                }
            }

            $next = $rows[0] ?? null;
            if ($next === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'No patients waiting.'],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('clinic_queue_entries')->where('clinic_queue_entries.tenant_id', CurrentTenant::id())->where('id', (int) $next['id'])->update([
                'status'            => 'called',
                'called_at'         => $now,
                'called_by_user_id' => $userId,
                'updated_at'        => $now,
            ]);

            $this->audit->enqueue('clinic.queue_called', 'clinic_queue_entries', (int) $next['id'], $userId, []);

            // Notify the called patient in-app (portal "Your queue" card
            // + bell both reflect it) so they know to proceed.
            $this->notifyPatientCalled((int) $next['id'], $userId);

            return $this->getRow((int) $next['id']);
        });
    }

    /**
     * SELF-SCOPED queue status for a patient (student/employee portal).
     * Finds today's queue entry linked to the caller's encounter;
     * returns null when the caller has no active queue entry today.
     *
     * @return array<string, mixed>|null
     */
    public function myStatus(int $patientUserId): ?array
    {
        $row = $this->db->table('clinic_queue_entries q')
            ->select('q.id, q.position, q.status, q.called_at, q.started_at, q.finished_at, q.encounter_id')
            ->where('q.tenant_id', CurrentTenant::id())
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            ->where('q.queue_date', $this->manilaToday())
            ->where('e.patient_user_id', $patientUserId)
            ->where('e.archived_at', null)
            ->whereIn('q.status', ['waiting', 'called', 'in_session'])
            ->orderBy('q.position', 'ASC')
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        $status  = (string) $row['status'];
        $waiting = $status === 'waiting';
        // People ahead = those in an earlier position still waiting or
        // being served.
        $ahead = $waiting ? (int) $this->db->table('clinic_queue_entries')
            ->where('clinic_queue_entries.tenant_id', CurrentTenant::id())
            ->where('queue_date', $this->manilaToday())
            ->whereIn('status', ['waiting', 'called', 'in_session'])
            ->where('position <', (int) $row['position'])
            ->countAllResults() : 0;

        return [
            'queue_entry_id'         => (int) $row['id'],
            'encounter_id'           => (int) $row['encounter_id'],
            'position'               => (int) $row['position'],
            'queue_number'           => sprintf('C-%03d', (int) $row['position']),
            'status'                 => $status,
            'called_at'              => $row['called_at'] !== null ? (string) $row['called_at'] : null,
            'started_at'             => $row['started_at'] !== null ? (string) $row['started_at'] : null,
            'people_ahead'           => $ahead,
            'estimated_wait_minutes' => $waiting ? (int) round($ahead * $this->avgServiceMinutes()) : null,
        ];
    }

    /**
     * start / skip / complete / return / recall on a queue entry.
     *
     * Skip is no longer terminal (October 2026 panel revision): it opens
     * a 60-minute recall window (`skipped_at` + `skip_deadline_at`) and
     * the entry stays in the Skipped Patients module until staff bring
     * the patient back (`return` → waiting, `recall` → called) or the
     * sweep marks the visit no-show.
     *
     * The window is a TIME condition, not a status: `return`/`recall`
     * are rejected once `skip_deadline_at` has passed, even if the sweep
     * has not run yet. Both sides of that race validate the same two
     * columns under a row lock, so a return that arrives at the same
     * moment as the deadline resolves deterministically — whoever holds
     * the lock first wins, and the loser gets a clean 409 rather than a
     * silent overwrite.
     */
    public function transition(int $id, string $action): array
    {
        $this->policy->check('queueManage');
        $userId = \App\Auth\CurrentUser::assert();

        if (! isset(self::TRANSITIONS[$action])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => "Unknown action '{$action}'.", 'field' => 'action'],
            ]);
        }

        return $this->txn(function () use ($id, $action, $userId): array {
            $row = $this->selectForUpdate('clinic_queue_entries', ['tenant_id' => CurrentTenant::id(), 'id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Queue entry #{$id} not found."],
                ]);
            }

            $current = (string) $row['status'];
            if (! in_array($current, self::TRANSITIONS[$action], true)) {
                throw StateMachineException::invalidTransition($current, self::RESULT[$action], 'queue');
            }

            $now    = $this->utcNow();
            $update = ['status' => self::RESULT[$action], 'updated_at' => $now];

            switch ($action) {
                case 'start':
                    $update['started_at'] = $now;
                    break;

                case 'complete':
                    $update['finished_at'] = $now;
                    break;

                case 'skip':
                    // Open the recall window. Clearing `returned_at` keeps
                    // a re-skip (skip → return → skip) from rendering as
                    // already-returned in the module.
                    $update['skipped_at']       = $now;
                    $update['skip_deadline_at'] = $this->addMinutes($now, self::SKIP_WINDOW_MINUTES);
                    $update['returned_at']      = null;
                    break;

                case 'return':
                case 'recall':
                    $this->assertSkipWindowOpen($row, $id);
                    $update['returned_at'] = $now;
                    if ($action === 'recall') {
                        // Calling the patient again is a fresh call — the
                        // "now serving" slot moves to them, so the row
                        // carries a new called_at / called_by. The
                        // single-active-slot invariant from callNext()
                        // must hold here too.
                        $this->assertNoActiveSlot($id);
                        $update['called_at']         = $now;
                        $update['called_by_user_id'] = $userId;
                    }
                    break;
            }

            $this->db->table('clinic_queue_entries')->where('clinic_queue_entries.tenant_id', CurrentTenant::id())->where('id', $id)->update($update);

            $this->audit->enqueue(
                self::AUDIT[$action],
                'clinic_queue_entries',
                $id,
                $userId,
                ['previous_status' => $current, 'next_status' => self::RESULT[$action]],
            );

            // Panel revision (August 2026): completing an in-session
            // queue entry ALSO closes its linked encounter + completes
            // the linked appointment, so the finished visit shows up in
            // the Closed tab. Mirrors the markNoShow / autoClose cascade
            // (encounter closed → appointment completed) — the queue
            // "Complete" button is the operator's end-of-session action.
            if ($action === 'complete') {
                $this->completion->complete((int) $row['encounter_id'], $userId, $now, 'queue_complete');
            }

            // Recall re-uses the call-next notification so the patient's
            // portal card flips back to "you're up" without a new
            // template.
            if ($action === 'recall') {
                $this->notifyPatientCalled($id, $userId);
            }

            return $this->getRow($id);
        });
    }

    /**
     * Guard for `return` / `recall`: the recall window must still be
     * open. Called with the row already locked, so the deadline cannot
     * move between this check and the update.
     *
     * A skipped row with a NULL deadline is treated as expired — that
     * state should not exist (skip always writes one), and failing
     * closed keeps a malformed row from being returned forever.
     *
     * @param array<string, mixed> $row
     */
    private function assertSkipWindowOpen(array $row, int $id): void
    {
        $deadline = $row['skip_deadline_at'] !== null ? (string) $row['skip_deadline_at'] : null;
        if ($deadline === null || $deadline <= $this->utcNow()) {
            throw new ApiException('statemachine.queue.skip_window_expired', 409, [
                ['code' => 'statemachine.queue.skip_window_expired',
                 'message' => "The 60-minute recall window for queue entry #{$id} has expired; the visit must be marked no-show."],
            ]);
        }
    }

    /**
     * Guard for `recall`: the clinic serves ONE patient at a time, so
     * re-calling a skipped patient is refused while another entry is
     * still `called` / `in_session`. Same invariant and error code as
     * `callNext()`, so the SPA shows one message for both paths.
     *
     * The scan takes the same `FOR UPDATE` lock `callNext()` uses, so a
     * concurrent call-next / recall cannot claim the slot between this
     * check and the status update.
     */
    private function assertNoActiveSlot(int $exceptId): void
    {
        $active = $this->db->query(
            'SELECT `id`, `status` FROM `clinic_queue_entries`'
            . ' WHERE `tenant_id` = ? AND `queue_date` = ? AND `status` IN (?, ?) AND `id` <> ?'
            . ' ORDER BY `position` ASC FOR UPDATE',
            [CurrentTenant::id(), $this->manilaToday(), 'called', 'in_session', $exceptId],
        )->getRowArray();

        if ($active !== null) {
            throw new ApiException('statemachine.queue.already_active', 409, [
                ['code' => 'statemachine.queue.already_active', 'message' => 'Clinic already has a patient called or in session.'],
            ]);
        }
    }

    /**
     * Resolve every expired skip window to no-show. Runs opportunistically
     * from `today()` / `skipped()` so the board is always truthful, and
     * from `synapse:queue-skip-sweep` so the transition happens even when
     * no staff member has a page open.
     *
     * Cascade (per entry, each in its own transaction — see
     * `resolveExpiredSkip`): encounter → closed/no_show, linked
     * appointment → no_show when still eligible, queue entry →
     * done/no_show, audit `clinic.queue_skip_expired`, and an in-app
     * notification fanned out to `clinic.queue.manage` holders.
     *
     * Idempotency: the candidate scan only selects rows still in
     * `skipped`, and each candidate is re-validated under a row lock
     * inside the cascade. Two concurrent sweeps (or a sweep racing a
     * staff return) therefore produce at most one no-show: the loser
     * sees a non-`skipped` status and returns without writing.
     *
     * Best-effort: one failing row is logged and skipped so the rest of
     * the batch still resolves.
     *
     * @return int number of entries transitioned to no-show
     */
    public function sweepExpiredSkips(): int
    {
        $candidates = $this->db->table('clinic_queue_entries')
            ->select('id, encounter_id')
            ->where('tenant_id', CurrentTenant::id())
            ->where('status', 'skipped')
            ->where('skip_deadline_at IS NOT NULL', null, false)
            ->where('skip_deadline_at <=', $this->utcNow())
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $resolved = 0;
        foreach ($candidates as $c) {
            try {
                if ($this->resolveExpiredSkip((int) $c['id'], (int) $c['encounter_id'])) {
                    $resolved++;
                }
            } catch (Throwable $t) {
                log_message('warning', sprintf(
                    'QueueService::sweepExpiredSkips: id=%d skipped (%s)',
                    (int) $c['id'],
                    $t->getMessage(),
                ));
            }
        }
        return $resolved;
    }

    /**
     * Resolve ONE expired skip inside a single transaction. Returns
     * false when the entry was already resolved (staff return, manual
     * no-show, or a concurrent sweep) — that is the duplicate-
     * suppression path, not an error.
     *
     * Lock order follows the established clinic cascade
     * (encounter → queue, see `ClinicService::markNoShow`): the
     * encounter lock is taken first, then the guard locks the queue row
     * and re-validates it. The queue row is finalized HERE (not by the
     * cascade) because this service owns queue transitions — one writer
     * per row.
     */
    private function resolveExpiredSkip(int $id, int $encounterId): bool
    {
        return $this->txn(function () use ($id, $encounterId): bool {
            // The guard runs with the encounter lock held, before any
            // write: it locks the queue row and re-validates the skip
            // window. A staff return that landed between the candidate
            // scan and here makes it return false, and nothing is
            // written (not even the encounter cascade).
            $row = null;

            $cascaded = $this->clinic->markNoShowSystem(
                $encounterId,
                'skip_window_expired',
                function () use ($id, &$row): bool {
                    $row = $this->selectForUpdate('clinic_queue_entries', ['tenant_id' => CurrentTenant::id(), 'id' => $id]);
                    if ($row === null || (string) $row['status'] !== 'skipped') {
                        return false;
                    }
                    $deadline = $row['skip_deadline_at'] !== null ? (string) $row['skip_deadline_at'] : null;
                    if ($deadline === null || $deadline > $this->utcNow()) {
                        // Deadline moved (or was cleared) after the scan.
                        return false;
                    }
                    return true;
                },
            );

            if ($cascaded === null) {
                // Guard aborted — already resolved by staff.
                return false;
            }

            $now    = $this->utcNow();
            $update = ['status' => 'done', 'finished_at' => $now, 'updated_at' => $now];
            if ($cascaded === true) {
                $update['outcome'] = 'no_show';
            } elseif ($row !== null && $row['returned_at'] === null) {
                // The encounter was already resolved another way — the
                // visit did not evaporate, so do NOT stamp a no-show
                // outcome on the queue row. `returned_at` records that
                // the skip episode ended with the patient handled.
                $update['returned_at'] = $now;
            }

            $this->db->table('clinic_queue_entries')->where('clinic_queue_entries.tenant_id', CurrentTenant::id())->where('id', $id)->update($update);

            $this->audit->enqueue(
                'clinic.queue_skip_expired',
                'clinic_queue_entries',
                $id,
                null,
                [
                    'previous_status' => 'skipped',
                    'next_status'     => 'done',
                    'reason_code'     => $cascaded === true ? 'skip_window_expired' : 'encounter_already_resolved',
                ],
            );

            if ($cascaded === true && $row !== null) {
                // In-app notice to authorized clinic staff (October 2026
                // requirement: automatic no-show must reach staff even
                // when nobody had the module open).
                $this->notify->enqueueToPermissions(
                    ['clinic.queue.manage'],
                    'queue.skip_expired',
                    [
                        'resource_code' => 'queue#' . $id,
                        'queue_number'  => sprintf('C-%03d', (int) $row['position']),
                        'next_status'   => 'no_show',
                    ],
                );
            }

            return true;
        });
    }

    /**
     * Same-transaction in-app notification to a called patient. Guests
     * (no linked user) and the calling staff member themselves skip.
     */
    private function notifyPatientCalled(int $queueEntryId, int $actorUserId): void
    {
        $row = $this->db->table('clinic_queue_entries q')
            ->select('q.position, e.patient_user_id')
            ->where('q.tenant_id', CurrentTenant::id())
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            ->where('q.id', $queueEntryId)
            ->get()->getRowArray();

        $patientId = (int) ($row['patient_user_id'] ?? 0);
        if ($patientId <= 0 || $patientId === $actorUserId) {
            return;
        }

        $this->notify->enqueue(
            $patientId,
            'queue.called',
            [
                'resource_code' => 'queue#' . $queueEntryId,
                'position'      => (int) ($row['position'] ?? 0),
            ],
        );
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return array<int, array<string, mixed>>
     */
    private function todayRows(): array
    {
        return $this->db->table('clinic_queue_entries q')
            ->select('q.id, q.encounter_id, q.position, q.status, q.outcome, q.called_at, q.started_at, q.finished_at, q.skipped_at, q.skip_deadline_at, q.returned_at, q.created_at, e.status AS encounter_status, e.patient_user_id, e.patient_school_id, e.guest_name, e.chief_complaint, e.outcome AS encounter_outcome, e.station_id, u.first_name, u.last_name')
            ->where('q.tenant_id', CurrentTenant::id())
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            // Patients are `users` (identity-consolidated) — one join
            // covers both students and employees.
            // When the encounter only carries a school id (legacy/demo
            // rows, patient_user_id NULL) fall back to matching the
            // registry by student/employee number so the name still
            // resolves for the Queue-tab tooltip.
            ->join(
                'users u',
                'u.id = e.patient_user_id'
                . ' OR (e.patient_user_id IS NULL'
                .   ' AND (u.student_number = e.patient_school_id'
                .     ' OR u.employee_number = e.patient_school_id))',
                'left',
            )
            // Archived visits leave the operational board (October 2026):
            // archiving a Done encounter must remove its row from the
            // Queue tab, not just from the Closed list.
            ->where('e.archived_at', null)
            ->where('q.queue_date', $this->manilaToday())
            ->orderBy('q.position', 'ASC')
            ->get()->getResultArray();
    }

    private function getRow(int $id): array
    {
        $row = $this->db->table('clinic_queue_entries q')
            ->select('q.id, q.encounter_id, q.position, q.status, q.outcome, q.called_at, q.started_at, q.finished_at, q.skipped_at, q.skip_deadline_at, q.returned_at, q.created_at, e.status AS encounter_status, e.patient_user_id, e.patient_school_id, e.guest_name, e.chief_complaint, e.outcome AS encounter_outcome, e.station_id, u.first_name, u.last_name')
            ->where('q.tenant_id', CurrentTenant::id())
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            ->join(
                'users u',
                'u.id = e.patient_user_id'
                . ' OR (e.patient_user_id IS NULL'
                .   ' AND (u.student_number = e.patient_school_id'
                .     ' OR u.employee_number = e.patient_school_id))',
                'left',
            )
            ->where('q.id', $id)
            ->get()->getRowArray();
        return $this->row($row);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function row(array $r): array
    {
            // Station that opened the visit (legacy kiosk check-ins) —
            // null for appointments / desk-created encounters.
        $out = [
            'id'                => (int) $r['id'],
            'destination'       => 'clinic',
            'queue_number'      => sprintf('C-%03d', (int) $r['position']),
            'encounter_id'      => (int) $r['encounter_id'],
            'position'          => (int) $r['position'],
            'status'            => (string) $r['status'],
            'outcome'           => $r['outcome'] !== null ? (string) $r['outcome'] : null,
            'display_name'      => $this->displayName($r),
            'called_at'         => $r['called_at'] !== null ? (string) $r['called_at'] : null,
            'started_at'        => $r['started_at'] !== null ? (string) $r['started_at'] : null,
            'finished_at'       => $r['finished_at'] !== null ? (string) $r['finished_at'] : null,
            // Recall-window fields (October 2026): the Queue tab shows a
            // "skipped · 42m left" hint and gates Return on the deadline.
            'skipped_at'        => isset($r['skipped_at']) && $r['skipped_at'] !== null ? (string) $r['skipped_at'] : null,
            'skip_deadline_at'  => isset($r['skip_deadline_at']) && $r['skip_deadline_at'] !== null ? (string) $r['skip_deadline_at'] : null,
            'returned_at'       => isset($r['returned_at']) && $r['returned_at'] !== null ? (string) $r['returned_at'] : null,
            'patient_school_id' => (string) $r['patient_school_id'],
            'chief_complaint'   => (string) $r['chief_complaint'],
            'station_id'        => $r['station_id'] !== null ? (string) $r['station_id'] : null,
            // Full registry name (`First Last`) for the Queue-tab id
            // tooltip; null for guests/orphans. Mirrors EncounterDto.
            'patient_name'      => $this->patientFullName($r),
            // `encounter_status` lets the staff queue UI gate destructive
            // actions (Close / Mark no-show) on the linked encounter's
            // state without a second round-trip — panel revision, August
            // 2026.
            'encounter_status'  => (string) $r['encounter_status'],
            'encounter_outcome' => $r['encounter_outcome'] !== null ? (string) $r['encounter_outcome'] : null,
        ];
        return $out;
    }

    /**
     * Skipped Patients module row (October 2026).
     *
     * Derives the module's display status from the queue row's raw
     * state so the SPA never has to re-implement the precedence:
     *   - raw `skipped`  → `skipped`  (window open; countdown live)
     *   - `returned_at`  → `returned` (staff brought them back)
     *   - `outcome=no_show` → `no_show` (expired or manually marked)
     *
     * A row can be both returned AND no-show in raw terms only if staff
     * returned a patient and the visit was later closed another way —
     * `returned_at` wins there because that is the last staff action on
     * THIS skip episode.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function skippedRow(array $r): array
    {
        $raw      = (string) $r['status'];
        $outcome  = $r['outcome'] !== null ? (string) $r['outcome'] : null;
        $returned = $r['returned_at'] !== null;

        if ($raw === 'skipped') {
            $status = 'skipped';
        } elseif ($returned) {
            $status = 'returned';
        } elseif ($outcome === 'no_show') {
            $status = 'no_show';
        } else {
            // Resolved some other way (auto_closed sweep, completion) —
            // surface the raw queue status rather than inventing one.
            $status = $raw;
        }

        return [
            'id'                 => (int) $r['id'],
            'queue_number'       => sprintf('C-%03d', (int) $r['position']),
            'encounter_id'       => (int) $r['encounter_id'],
            'position'           => (int) $r['position'],
            'status'             => $status,
            'queue_status'       => $raw,
            'display_name'       => $this->displayName($r),
            'patient_school_id'  => (string) $r['patient_school_id'],
            'patient_name'       => $this->patientFullName($r),
            'chief_complaint'    => (string) $r['chief_complaint'],
            'appointment_id'     => $r['appointment_id'] !== null ? (int) $r['appointment_id'] : null,
            'appointment_at'     => $r['appointment_at'] !== null ? (string) $r['appointment_at'] : null,
            'appointment_status' => $r['appointment_status'] !== null ? (string) $r['appointment_status'] : null,
            'skipped_at'         => $r['skipped_at'] !== null ? (string) $r['skipped_at'] : null,
            'skip_deadline_at'   => $r['skip_deadline_at'] !== null ? (string) $r['skip_deadline_at'] : null,
            'returned_at'        => $r['returned_at'] !== null ? (string) $r['returned_at'] : null,
            'encounter_status'   => (string) $r['encounter_status'],
            'outcome'            => $outcome,
        ];
    }

    /**
     * Staff-view display name: first name only, falling back to the
     * typed guest name or a masked school-id prefix (guest walk-in /
     * orphaned rows).
     *
     * @param array<string, mixed> $r
     */
    private function displayName(array $r): string
    {
        $first = isset($r['first_name']) && $r['first_name'] !== null ? trim((string) $r['first_name']) : '';

        if ($first !== '') {
            return $first;
        }
        if (isset($r['guest_name']) && $r['guest_name'] !== null && $r['guest_name'] !== '') {
            return (string) $r['guest_name'];
        }
        $sid = (string) $r['patient_school_id'];
        return mb_substr($sid, 0, 3) . '…';
    }

    /**
     * Full registry name (`First Last`) for the Queue-tab id tooltip;
     * null when the join found no user (guest walk-in / orphaned row).
     * Mirrors `EncounterDto::patientName()`.
     *
     * @param array<string, mixed> $r
     */
    private function patientFullName(array $r): ?string
    {
        $first = isset($r['first_name']) && $r['first_name'] !== null ? trim((string) $r['first_name']) : '';
        $last  = isset($r['last_name'])  && $r['last_name']  !== null ? trim((string) $r['last_name'])  : '';
        if ($first === '' && $last === '') {
            return null;
        }
        return trim($first . ' ' . $last);
    }

    /**
     * Today's rolling average service time in minutes (started_at →
     * finished_at over completed sessions); 10-minute default while
     * the day has no history.
     */
    private function avgServiceMinutes(): float
    {
        $row = $this->db->query(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, `started_at`, `finished_at`)) AS avg_min'
            . ' FROM `clinic_queue_entries`'
            . ' WHERE `tenant_id` = ? AND `queue_date` = ? AND `started_at` IS NOT NULL AND `finished_at` IS NOT NULL',
            [CurrentTenant::id(), $this->manilaToday()],
        )->getRowArray();

        return $row !== null && $row['avg_min'] !== null ? max(1.0, (float) $row['avg_min']) : 10.0;
    }

    /**
     * The queue's business day (Asia/Manila) — what staff mean by
     * "today". Writers (AppointmentService, referral handoff, bulk
     * import) partition `queue_date` on the same calendar via the
     * shared ManilaDay helper; keep both sides aligned.
     */
    private function manilaToday(): string
    {
        return ManilaDay::today();
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * `$utcSql` + `$minutes`, returned in the same naive-UTC format the
     * queue columns use. DateTimeImmutable (not strtotime) so a DST or
     * month-end edge can never surprise the deadline arithmetic.
     */
    private function addMinutes(string $utcSql, int $minutes): string
    {
        return (new DateTimeImmutable($utcSql, new DateTimeZone('UTC')))
            ->modify("+{$minutes} minutes")
            ->format('Y-m-d H:i:s');
    }
}
