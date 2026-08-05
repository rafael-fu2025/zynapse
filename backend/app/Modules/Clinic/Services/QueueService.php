<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Services\Audit\AuditOutboxService;
use App\Services\Notify\NotificationOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\Policies\ClinicPolicy;
use Throwable;

/**
 * QueueService — walk-in queue (Phase 14, recycled from synapse_ag
 * ConsultationController queue actions).
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
    /** @var array<string, array<int, string>> action => allowed current statuses */
    private const TRANSITIONS = [
        'start'    => ['called'],
        'skip'     => ['called'],
        'complete' => ['in_session'],
    ];

    /** @var array<string, string> action => resulting status */
    private const RESULT = [
        'start'    => 'in_session',
        'skip'     => 'skipped',
        'complete' => 'done',
    ];

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly AppointmentService $appointments,
        private readonly ClinicService $clinic,
        private readonly NotificationOutboxService $notify,
    ) {
        parent::__construct();
    }

    /**
     * Today's queue — staff view (full school ids).
     *
     * Side effects (panel revision, August 2026):
     *   1. Lazy auto-check-in: every `scheduled` appointment whose
     *      `scheduled_at` falls on today's UTC window is opened +
     *      queued. Idempotent against kiosk / staff races.
     *   2. Lazy end-of-day cleanup: stale `open` encounters whose
     *      `started_at` predates today are auto-closed with
     *      `outcome='auto_closed'`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function today(): array
    {
        $this->policy->check('queueRead');

        // Sweep before the fetch so the staff view shows the freshly
        // opened + queued appointments immediately.
        $this->appointments->autoCheckInTodaysPending();
        $this->autoCloseEarlierOpenEncounters();

        return array_map(
            fn (array $r): array => $this->row($r, false),
            $this->todayRows(),
        );
    }

    /**
     * End-of-day sweep — close every `open` encounter whose
     * `started_at` predates today (UTC) using the same cascade as
     * `ClinicService::autoCloseStaleEncounter()`. Best-effort: a
     * stale row that fails (e.g. encounter vanished mid-sweep) is
     * logged + skipped so the staff `today()` read still succeeds.
     */
    private function autoCloseEarlierOpenEncounters(): int
    {
        $dayStart = (new DateTimeImmutable('today', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $ids = $this->db->table('clinic_encounters')
            ->select('id')
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

    /** Call the next waiting patient (single "now serving" slot). */
    public function callNext(): array
    {
        $this->policy->check('queueManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($userId): array {
            $today = $this->utcToday();

            // Lock today's open entries (called + waiting) in one pass.
            $rows = $this->db->query(
                'SELECT `id`, `status` FROM `clinic_queue_entries`'
                . ' WHERE `queue_date` = ? AND `status` IN (?, ?)'
                . ' ORDER BY `position` ASC FOR UPDATE',
                [$today, 'called', 'waiting'],
            )->getResultArray();

            foreach ($rows as $r) {
                if ((string) $r['status'] === 'called') {
                    throw new ApiException('statemachine.queue.already_called', 409, [
                        ['code' => 'statemachine.queue.already_called', 'message' => 'A patient is already called — start or skip them first.'],
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
            $this->db->table('clinic_queue_entries')->where('id', (int) $next['id'])->update([
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
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            ->where('q.queue_date', $this->utcToday())
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
        // being served (mirrors the publicState estimate).
        $ahead = $waiting ? (int) $this->db->table('clinic_queue_entries')
            ->where('queue_date', $this->utcToday())
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

    /** start / skip / complete on a called or in-session entry. */
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
            $row = $this->selectForUpdate('clinic_queue_entries', ['id' => $id]);
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
            if ($action === 'start') {
                $update['started_at'] = $now;
            }
            if ($action === 'complete') {
                $update['finished_at'] = $now;
            }

            $this->db->table('clinic_queue_entries')->where('id', $id)->update($update);

            $this->audit->enqueue(
                'clinic.queue_' . self::RESULT[$action],
                'clinic_queue_entries',
                $id,
                $userId,
                ['outcome' => self::RESULT[$action]],
            );

            // Panel revision (August 2026): completing an in-session
            // queue entry ALSO closes its linked encounter + completes
            // the linked appointment, so the finished visit shows up in
            // the Closed tab. Mirrors the markNoShow / autoClose cascade
            // (encounter closed → appointment completed) — the queue
            // "Complete" button is the operator's end-of-session action.
            if ($action === 'complete') {
                $this->closeLinkedEncounter((int) $row['encounter_id'], $userId, $now);
            }

            return $this->getRow($id);
        });
    }

    /**
     * Cascade a queue "complete" onto the linked clinical record:
     * close the linked encounter (if still open) and complete the
     * linked appointment (if checked_in). Runs inside the caller's
     * transaction, so it must not call methods that open their own
     * nested transactions (the CI4 depth counter is finicky).
     */
    private function closeLinkedEncounter(int $encounterId, int $userId, string $now): void
    {
        $enc = $this->selectForUpdate('clinic_encounters', ['id' => $encounterId, 'archived_at' => null]);
        if ($enc === null || (string) $enc['status'] !== 'open') {
            // Already closed by a parallel close / no-show / auto-close
            // — nothing left to cascade.
            return;
        }

        $this->db->table('clinic_encounters')
            ->where('id', $encounterId)
            ->update([
                'status'     => 'closed',
                'closed_at'  => $now,
                'updated_at' => $now,
            ]);

        // Complete the linked appointment when it was checked in —
        // scheduling layer follows the encounter (same as
        // ClinicService::closeEncounter / autoCloseStaleEncounter).
        if (isset($enc['appointment_id']) && $enc['appointment_id'] !== null) {
            $appt = $this->selectForUpdate('clinic_appointments', [
                'id'          => (int) $enc['appointment_id'],
                'archived_at' => null,
            ]);
            if ($appt !== null && (string) $appt['status'] === 'checked_in') {
                $this->db->table('clinic_appointments')
                    ->where('id', (int) $appt['id'])
                    ->update(['status' => 'completed', 'updated_at' => $now]);
                $this->audit->enqueue(
                    'clinic.appointment_completed',
                    'clinic_appointments',
                    (int) $appt['id'],
                    $userId,
                    ['previous_status' => 'checked_in', 'next_status' => 'completed', 'reason_code' => 'queue_complete'],
                );
            }
        }

        $this->audit->enqueue(
            'clinic.encounter_closed',
            'clinic_encounters',
            $encounterId,
            $userId,
            ['previous_status' => 'open', 'next_status' => 'closed', 'reason_code' => 'queue_complete'],
        );
    }

    /**
     * PUBLIC waiting-room feed — minimum disclosure: position + display
     * name only (legacy TV/kiosk contract). No policy check by design;
     * the route is unauthenticated.
     *
     * Each waiting entry carries an indicative wait (people ahead ×
     * today's rolling average service time) — kiosk gap #3.
     *
     * The PUBLIC lobby feed exposes the queue number, full name in
     * `Last, First` format, and the school id — enough for the patient
     * to recognise themselves when their number is called, without
     * disclosing address, contact, or clinical detail.
     *
     * @return array{now_serving: ?array{position: int, display_name: string, patient_school_id: string}, waiting: array<int, array{position: int, display_name: string, patient_school_id: string, est_wait_minutes: int}>, updated_at: string}
     */
    public function publicState(): array
    {
        $nowServing = null;
        $waiting    = [];

        foreach ($this->todayRows() as $r) {
            $status = (string) $r['status'];
            $item   = [
                'position'           => (int) $r['position'],
                'display_name'       => $this->displayName($r, true),
                'patient_school_id'  => (string) $r['patient_school_id'],
            ];
            if ($status === 'called' || $status === 'in_session') {
                // Highest-progress entry wins the "now serving" board.
                $nowServing = $item;
            } elseif ($status === 'waiting') {
                $waiting[] = $item;
            }
        }

        $avg = $this->avgServiceMinutes();
        foreach ($waiting as $i => $item) {
            $ahead = $i + ($nowServing !== null ? 1 : 0);
            $waiting[$i]['est_wait_minutes'] = (int) round($ahead * $avg);
        }

        return [
            'now_serving' => $nowServing,
            'waiting'     => $waiting,
            'updated_at'  => $this->utcNow(),
        ];
    }

    /**
     * Same-transaction in-app notification to a called patient. Guests
     * (no linked user) and the calling staff member themselves skip.
     */
    private function notifyPatientCalled(int $queueEntryId, int $actorUserId): void
    {
        $row = $this->db->table('clinic_queue_entries q')
            ->select('q.position, e.patient_user_id')
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
            ->select('q.id, q.encounter_id, q.position, q.status, q.outcome, q.called_at, q.started_at, q.finished_at, q.created_at, e.status AS encounter_status, e.patient_user_id, e.patient_school_id, e.guest_name, e.chief_complaint, e.outcome AS encounter_outcome, e.station_id, u.first_name, u.last_name')
            ->join('clinic_encounters e', 'e.id = q.encounter_id')
            // Patients are `users` (identity-consolidated) — one join
            // covers both students and employees queueing at the kiosk.
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
            ->where('q.queue_date', $this->utcToday())
            ->orderBy('q.position', 'ASC')
            ->get()->getResultArray();
    }

    private function getRow(int $id): array
    {
        $row = $this->db->table('clinic_queue_entries q')
            ->select('q.id, q.encounter_id, q.position, q.status, q.outcome, q.called_at, q.started_at, q.finished_at, q.created_at, e.status AS encounter_status, e.patient_user_id, e.patient_school_id, e.guest_name, e.chief_complaint, e.outcome AS encounter_outcome, e.station_id, u.first_name, u.last_name')
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
        return $this->row($row, false);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function row(array $r, bool $public): array
    {
        $out = [
            'id'              => (int) $r['id'],
            'encounter_id'    => (int) $r['encounter_id'],
            'position'        => (int) $r['position'],
            'status'          => (string) $r['status'],
            'outcome'         => $r['outcome'] !== null ? (string) $r['outcome'] : null,
            'display_name'    => $this->displayName($r),
            'called_at'       => $r['called_at'] !== null ? (string) $r['called_at'] : null,
            'started_at'      => $r['started_at'] !== null ? (string) $r['started_at'] : null,
            'finished_at'     => $r['finished_at'] !== null ? (string) $r['finished_at'] : null,
        ];
        if (! $public) {
            $out['patient_school_id'] = (string) $r['patient_school_id'];
            $out['chief_complaint']   = (string) $r['chief_complaint'];
            // Kiosk station that opened the visit — null for
            // appointments / desk-created encounters.
            $out['station_id'] = $r['station_id'] !== null ? (string) $r['station_id'] : null;
            // Full registry name (`First Last`) for the Queue-tab id
            // tooltip; null for guests/orphans. Mirrors EncounterDto.
            $out['patient_name'] = $this->patientFullName($r);
            // `encounter_status` lets the staff queue UI gate destructive
            // actions (Close / Mark no-show) on the linked encounter's
            // state without a second round-trip — panel revision, August
            // 2026.
            $out['encounter_status']  = (string) $r['encounter_status'];
            $out['encounter_outcome'] = $r['encounter_outcome'] !== null ? (string) $r['encounter_outcome'] : null;
        }
        return $out;
    }

    /**
     * Patient name from the unified registry.
     *
     * - `$full === false` (legacy staff view / kiosk scan result): first
     *   name only. This matches what `CheckinService::result()` surfaces
     *   on the kiosk modal and keeps the historical call site stable.
     * - `$full === true` (public lobby feed, Phase 14 revision): the
     *   full name in `Last, First` format so patients in the waiting
     *   room can identify themselves. Falls back to the masked school
     *   id prefix if no names are on file (e.g. an archived patient
     *   still queued).
     *
     * @param array<string, mixed> $r
     */
    private function displayName(array $r, bool $full = false): string
    {
        $first = isset($r['first_name']) && $r['first_name'] !== null ? trim((string) $r['first_name']) : '';
        $last  = isset($r['last_name'])  && $r['last_name']  !== null ? trim((string) $r['last_name'])  : '';

        if ($full) {
            if ($first !== '' || $last !== '') {
                // `Last, First` — the conventional directory order used
                // by the Foundation University registrar.
                return trim($last . ($first !== '' ? ', ' . $first : ''));
            }
            // Guest walk-in (no registry record) — show the typed name.
            if (isset($r['guest_name']) && $r['guest_name'] !== null && $r['guest_name'] !== '') {
                return (string) $r['guest_name'];
            }
            $sid = (string) $r['patient_school_id'];
            return mb_substr($sid, 0, 3) . '…';
        }

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
     * the day has no history. Mirrors CheckinService::estimatedWaitMinutes.
     */
    private function avgServiceMinutes(): float
    {
        $row = $this->db->query(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, `started_at`, `finished_at`)) AS avg_min'
            . ' FROM `clinic_queue_entries`'
            . ' WHERE `queue_date` = ? AND `started_at` IS NOT NULL AND `finished_at` IS NOT NULL',
            [$this->utcToday()],
        )->getRowArray();

        return $row !== null && $row['avg_min'] !== null ? max(1.0, (float) $row['avg_min']) : 10.0;
    }

    private function utcToday(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
