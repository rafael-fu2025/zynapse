<?php

declare(strict_types=1);

namespace Modules\Counselling\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\ManilaDay;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Analytics\SchedulingAnalytics;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Counselling\Policies\CounsellingPolicy;

/**
 * ScheduleService — counsellor availability + appointments (Phase 15,
 * recycled from synapse_ag AvailabilityController/AppointmentController
 * + ConflictDetector).
 *
 * Rules ported from the legacy module:
 *   - Bookings must fall inside an ACTIVE availability window of the
 *     counsellor for that weekday.
 *   - One appointment per slot: a counsellor may hold a single
 *     scheduled/confirmed appointment over any given time (the
 *     ConflictDetector predicate, rebuilt with BOUND parameters — the
 *     legacy version interpolated). A time covered by three counsellors
 *     therefore admits three bookings, one each — capacity is a count of
 *     cover, not a per-counsellor number (2026-09-24).
 *   - Three-strike no-show: `no_show` increments the patient registry
 *     counter; `completed` resets it. At 3 consecutive strikes `book()`
 *     refuses the booking unless the caller holds
 *     `counselling.schedule.team_manage` (supervisor override).
 *     Registry is touched via a separate UPDATE by student_number
 *     (never a cross-module JOIN).
 */
final class ScheduleService extends BaseService
{
    /** @var array<string, array<int, string>> action => allowed current statuses */
    private const TRANSITIONS = [
        'confirm'  => ['scheduled'],
        'complete' => ['scheduled', 'confirmed'],
        'cancel'   => ['scheduled', 'confirmed'],
        'no_show'  => ['scheduled', 'confirmed'],
    ];

    /** @var array<string, string> action => resulting status */
    private const RESULT = [
        'confirm'  => 'confirmed',
        'complete' => 'completed',
        'cancel'   => 'cancelled',
        'no_show'  => 'no_show',
    ];

    /**
     * Three-strike threshold: booking is refused once the patient's
     * consecutive_no_shows reaches this value. `complete` resets the
     * counter, so a patient who attends is immediately bookable again.
     * Override: `counselling.schedule.team_manage` (supervisor) may
     * still book — the refusal is then the supervisor's documented
     * decision, and it lands in the audit chain like every booking.
     */
    private const NO_SHOW_STRIKE_LIMIT = 3;

    public function __construct(
        private readonly CounsellingPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    // --------------------------------------------------- availability

    /** @return list<array{id:int,name:string}> */
    public function counsellors(): array
    {
        $this->policy->check('scheduleRead');
        $rows = $this->db->table('users u')->select('u.id,u.first_name,u.last_name,u.username')
            ->where('u.tenant_id', CurrentTenant::id())
            ->join('auth_groups_users gu','gu.user_id=u.id')->join('auth_groups g','g.id=gu.group_id')
            ->where('g.name','counsellor')->where('u.archived_at',null)->orderBy('u.last_name','ASC')->get()->getResultArray();
        return array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'name'=>trim($r['first_name'].' '.$r['last_name'])?:$r['username']],$rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAvailability(?int $counsellorUserId): array
    {
        $this->policy->check('scheduleRead');

        $counsellorUserId = $this->scheduleScope($counsellorUserId);
        $builder = $this->db->table('counselling_availability')
            ->where('counselling_availability.tenant_id', CurrentTenant::id())
            ->select('id, counsellor_user_id, day_of_week, start_time, end_time, is_active')
            ->where('is_active', 1)
            ->orderBy('counsellor_user_id', 'ASC')
            ->orderBy('day_of_week', 'ASC')
            ->orderBy('start_time', 'ASC');
        if ($counsellorUserId !== null) {
            $builder->where('counsellor_user_id', $counsellorUserId);
        }

        return array_map(fn (array $r): array => $this->availabilityRow($r), $builder->get()->getResultArray());
    }

    /**
     * Shape one availability row for the API. Shared by list and update so
     * the two shapes cannot drift apart.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function availabilityRow(array $r): array
    {
        return [
            'id'                 => (int) $r['id'],
            'counsellor_user_id' => (int) $r['counsellor_user_id'],
            'day_of_week'        => (int) $r['day_of_week'],
            'start_time'         => (string) $r['start_time'],
            'end_time'           => (string) $r['end_time'],
        ];
    }

    /**
     * Add one or more availability windows in a single transaction.
     *
     * The desk sets its week in one sitting, so the payload carries a **set**
     * of weekdays: `days_of_week: [1,3,5]` inserts three windows sharing one
     * time range. All-or-nothing — a failure on any day rolls the whole set
     * back, so the desk can never end up with half a week added and no clear
     * signal which half.
     *
     * `day_of_week` (single) is still accepted for older callers and for the
     * one-day case; `days_of_week` wins when both are present.
     *
     * @param array<string, mixed> $input validated payload
     * @return array{id:int, ids:list<int>} `id` is the first row, kept for
     *         backward compatibility with single-day callers
     */
    public function addSlot(array $input): array
    {
        $userId = \App\Auth\CurrentUser::assert();
        $counsellorId = (int) ($input['counsellor_user_id'] ?? $userId);
        $this->assertScheduleMutation($counsellorId);

        $days = $this->normaliseDaysOfWeek($input);

        return $this->txn(function () use ($input, $userId, $counsellorId, $days): array {
            $start        = (string) $input['start_time'];
            $end          = (string) $input['end_time'];
            if ($start >= $end) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'start_time must precede end_time.', 'field' => 'start_time'],
                ]);
            }

            $now = $this->utcNow();
            $ids = [];
            foreach ($days as $day) {
                // Per-day, because a window is identified by its weekday —
                // the same hours on another day are a different window.
                $this->assertNoDuplicateWindow($counsellorId, $day, $start, $end);

                $this->db->table('counselling_availability')->insert([
                    'tenant_id'          => CurrentTenant::id(),
                    'counsellor_user_id' => $counsellorId,
                    'day_of_week'        => $day,
                    'start_time'         => $start,
                    'end_time'           => $end,
                    'is_active'          => 1,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
                $id = (int) $this->db->insertID();
                $ids[] = $id;

                $this->audit->enqueue('counselling.availability_added', 'counselling_availability', $id, $userId, []);
            }

            return ['id' => $ids[0], 'ids' => $ids];
        });
    }

    /**
     * Resolve the requested weekdays into a sorted, de-duplicated list.
     *
     * @param array<string, mixed> $input
     * @return list<int> 0=Sun … 6=Sat
     */
    private function normaliseDaysOfWeek(array $input): array
    {
        $raw = $input['days_of_week'] ?? null;

        if (is_array($raw)) {
            $candidates = $raw;
        } elseif (array_key_exists('day_of_week', $input) && $input['day_of_week'] !== '' && $input['day_of_week'] !== null) {
            $candidates = [$input['day_of_week']];
        } else {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Pick at least one weekday.', 'field' => 'days_of_week'],
            ]);
        }

        $days = [];
        foreach ($candidates as $candidate) {
            if (! is_numeric($candidate)) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Weekdays must be numbers 0-6.', 'field' => 'days_of_week'],
                ]);
            }
            $day = (int) $candidate;
            if ($day < 0 || $day > 6) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Weekdays must be numbers 0-6.', 'field' => 'days_of_week'],
                ]);
            }
            $days[$day] = $day;
        }

        if ($days === []) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => 'Pick at least one weekday.', 'field' => 'days_of_week'],
            ]);
        }

        $days = array_values($days);
        sort($days);

        return $days;
    }

    /**
     * Edit an availability window **in place**.
     *
     * Until this existed the desk could only remove a window and add another,
     * which left the original row behind — the duplicate rows that
     * `synapse:counselling-dedupe-availability` cleans up. This method
     * therefore only ever writes to the addressed row: there is no insert and
     * no delete, so an edit cannot produce a second window, and the id the
     * desk holds stays valid.
     *
     * Unspecified fields keep their current value, so a caller may send only
     * what it changed.
     *
     * @param array<string, mixed> $input validated payload
     * @return array<string, mixed>
     */
    public function updateSlot(int $id, array $input): array
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $input, $userId): array {
            $existing = $this->selectForUpdate('counselling_availability', ['tenant_id' => CurrentTenant::id(), 'id' => $id, 'is_active' => 1]);
            if ($existing === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Availability slot #{$id} not found."],
                ]);
            }
            $this->assertScheduleMutation((int) $existing['counsellor_user_id']);

            $counsellorId = isset($input['counsellor_user_id']) && $input['counsellor_user_id'] !== ''
                ? (int) $input['counsellor_user_id']
                : (int) $existing['counsellor_user_id'];
            $day   = isset($input['day_of_week']) && $input['day_of_week'] !== '' ? (int) $input['day_of_week'] : (int) $existing['day_of_week'];
            $start = isset($input['start_time'])  && $input['start_time'] !== ''  ? (string) $input['start_time'] : (string) $existing['start_time'];
            $end   = isset($input['end_time'])    && $input['end_time'] !== ''    ? (string) $input['end_time']   : (string) $existing['end_time'];

            if ($start >= $end) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'start_time must precede end_time.', 'field' => 'start_time'],
                ]);
            }

            // Exclude this row, so an edit that only moves the window — or
            // resends the same times — does not collide with itself.
            $this->assertNoDuplicateWindow($counsellorId, $day, $start, $end, $id);

            $this->db->table('counselling_availability')
                ->where('counselling_availability.tenant_id', CurrentTenant::id())
                ->where('id', $id)
                ->update([
                    'counsellor_user_id' => $counsellorId,
                    'day_of_week'        => $day,
                    'start_time'         => $start,
                    'end_time'           => $end,
                    'updated_at'         => $this->utcNow(),
                ]);

            $this->audit->enqueue('counselling.availability_updated', 'counselling_availability', $id, $userId, []);

            $fresh = $this->db->table('counselling_availability')
                ->where('counselling_availability.tenant_id', CurrentTenant::id())
                ->where('id', $id)
                ->get()->getRowArray();
            if ($fresh === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Availability slot #{$id} not found."],
                ]);
            }

            return $this->availabilityRow($fresh);
        });
    }

    /** Soft removal — the slot stops accepting bookings. */
    public function removeSlot(int $id): void
    {
        $userId = \App\Auth\CurrentUser::assert();

        $this->txn(function () use ($id, $userId): void {
            $row = $this->selectForUpdate('counselling_availability', ['tenant_id' => CurrentTenant::id(), 'id' => $id, 'is_active' => 1]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Availability slot #{$id} not found."],
                ]);
            }
            $this->assertScheduleMutation((int) $row['counsellor_user_id']);
            $this->db->table('counselling_availability')->where('counselling_availability.tenant_id', CurrentTenant::id())->where('id', $id)->update([
                'is_active'  => 0,
                'updated_at' => $this->utcNow(),
            ]);
            $this->audit->enqueue('counselling.availability_removed', 'counselling_availability', $id, $userId, []);
        });
    }

    /**
     * Refuse a second active window with the same counsellor, weekday, and
     * exact time range.
     *
     * Deliberately narrower than the clinic roster's overlap rule. With
     * capacity gone, two *overlapping* windows grant no extra bookable
     * places — a time either has cover or it does not — so an overlap may be
     * a legitimate way to declare it and is left alone. An identical window
     * is never legitimate: it is the same cover written twice, and because
     * the desk had no way to edit a window, doubling it up was the only way
     * to "change" one.
     *
     * @param int|null $excludeId the row being edited, so an update that
     *                            keeps its own slot does not match itself
     */
    private function assertNoDuplicateWindow(int $counsellorId, int $day, string $start, string $end, ?int $excludeId = null): void
    {
        $builder = $this->db->table('counselling_availability')
            ->where('counselling_availability.tenant_id', CurrentTenant::id())
            ->where('counsellor_user_id', $counsellorId)
            ->where('day_of_week', $day)
            ->where('start_time', $start)
            ->where('end_time', $end)
            ->where('is_active', 1);
        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }
        if ($builder->countAllResults() > 0) {
            throw new ApiException('resource.conflict', 409, [
                [
                    'code'    => 'resource.conflict',
                    'message' => 'That counsellor already has this exact window on that day.',
                    'field'   => 'start_time',
                ],
            ]);
        }
    }

    // --------------------------------------------------- appointments

    /** Buckets the Queue board reads, each a disjoint slice of the calendar. */
    public const APPOINTMENT_SCOPES = ['upcoming', 'today', 'archived'];

    /**
     * List appointments, optionally narrowed to one calendar bucket.
     *
     * Scopes are resolved against the **Manila** business day, never a raw
     * UTC date — the module has regressed on this before. The three buckets
     * are disjoint:
     *
     *   - `upcoming` — dated after today, still live (scheduled|confirmed)
     *   - `today`    — dated today, still live
     *   - `archived` — resolved (completed|cancelled|no_show) OR dated before
     *                  today, so a stale un-actioned row still surfaces
     *
     * Every scope returns rows from **both** booking sources: appointments
     * patients booked through the portal and appointments staff booked on
     * their behalf. `source` on each row says which.
     *
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listAppointments(
        ?string $cursor,
        int $limit,
        ?string $status,
        ?string $date = null,
        ?string $scope = null,
        ?string $type = null,
    ): array {
        $this->policy->check('scheduleRead');

        $counsellorId = $this->scheduleScope(null);
        $builder = $this->db->table('counselling_appointments a')
            ->where('a.tenant_id', CurrentTenant::id())
            ->select('a.*, p.first_name AS patient_first_name, p.last_name AS patient_last_name,'
                . ' c.first_name AS counsellor_first_name, c.last_name AS counsellor_last_name')
            ->join('users p', 'p.id = a.patient_user_id', 'left')
            ->join('users c', 'c.id = a.counsellor_user_id', 'left');
        if ($counsellorId !== null) {
            // A counsellor sees their own appointments plus any unassigned
            // bookings that are waiting for approval / claim (same pooled
            // discipline as Guidance queue lanes).
            $builder->groupStart()
                ->where('a.counsellor_user_id', $counsellorId)
                ->orWhere('a.counsellor_user_id', null)
                ->groupEnd();
        }
        if ($status !== null && $status !== '') {
            $builder->where('a.status', $status);
        }
        if ($date !== null && $date !== '') {
            $builder->where('a.appointment_date', $date);
        }
        if ($type !== null && $type !== '') {
            $builder->where('a.type', $type);
        }

        // Archived reads newest-first (what just finished); live buckets read
        // soonest-first (what is coming). The cursor direction must match.
        $descending = $scope === 'archived';
        $today      = ManilaDay::today();
        if ($scope === 'upcoming') {
            $builder->where('a.appointment_date >', $today)->whereIn('a.status', ['scheduled', 'confirmed']);
        } elseif ($scope === 'today') {
            $builder->where('a.appointment_date', $today)->whereIn('a.status', ['scheduled', 'confirmed']);
        } elseif ($scope === 'archived') {
            $builder->groupStart()
                ->whereIn('a.status', ['completed', 'cancelled', 'no_show'])
                ->orWhere('a.appointment_date <', $today)
            ->groupEnd();
        }

        $direction = $descending ? 'DESC' : 'ASC';
        $builder->orderBy('a.appointment_date', $direction)
            ->orderBy('a.start_time', $direction)
            ->orderBy('a.id', $direction);

        $rows = $this->applyAppointmentKeyset($builder, $cursor, $limit, $descending);
        $hasNext = count($rows) > $limit;
        if ($hasNext) {
            $rows = array_slice($rows, 0, $limit);
        }
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            'data'  => array_map(fn (array $r): array => $this->appointmentRow($r), $rows),
            'next'  => $hasNext && $last !== null
                ? KeysetPaginator::encode(
                    (string) $last['appointment_date'] . ' ' . (string) $last['start_time'],
                    (int) $last['id'],
                )
                : null,
            'count' => $limit,
        ];
    }

    /**
     * Keyset pagination over the appointment's own calendar ordering.
     *
     * The shared {@see KeysetPaginator} keys on `(created_at, id)` — the wrong
     * axis for a schedule, where rows are read in date order. Rather than
     * reintroduce OFFSET, the cursor reuses the same opaque base64 encoding
     * but carries `"<appointment_date> <start_time>"` as its tuple stamp, and
     * the comparison is a three-part lexicographic walk over
     * `(appointment_date, start_time, id)`.
     *
     * @return array<int, array<string, mixed>> rows, at most `$limit + 1`
     */
    private function applyAppointmentKeyset(
        \CodeIgniter\Database\BaseBuilder $builder,
        ?string $cursor,
        int $limit,
        bool $descending,
    ): array {
        $builder->limit($limit + 1);

        $decoded = KeysetPaginator::decode($cursor);
        if ($decoded !== null) {
            $stamp = $decoded['created_at'];
            $date  = substr($stamp, 0, 10);
            $time  = substr($stamp, 11);
            $op    = $descending ? '<' : '>';

            $builder->groupStart()
                ->where('a.appointment_date ' . $op, $date)
                ->orGroupStart()->where('a.appointment_date', $date)->where('a.start_time ' . $op, $time)->groupEnd()
                ->orGroupStart()
                    ->where('a.appointment_date', $date)
                    ->where('a.start_time', $time)
                    ->where('a.id ' . $op, $decoded['id'])
                ->groupEnd()
            ->groupEnd();
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Book inside an availability window with capacity enforcement.
     *
     * @param array<string, mixed> $input validated payload
     * @return array<string, mixed>
     */
    public function book(array $input): array
    {
        $userId = \App\Auth\CurrentUser::assert();
        $counsellorId = (int) ($input['counsellor_user_id'] ?? $userId);
        $this->assertScheduleMutation($counsellorId);

        return $this->txn(function () use ($input, $userId, $counsellorId): array {
            $date         = (string) $input['appointment_date'];
            $start        = (string) $input['start_time'];
            $end          = (string) $input['end_time'];
            if ($start >= $end) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'start_time must precede end_time.', 'field' => 'start_time'],
                ]);
            }

            // Past-date booking refusal (audit 2026-09-03, F10).
            if ($date < ManilaDay::today()) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'appointment_date cannot be in the past.', 'field' => 'appointment_date'],
                ]);
            }

            // Unregistered patient booking refusal (audit 2026-09-03, F10).
            [, $patient] = (new \Modules\Clinic\Services\PatientLookupService())->findByIdentifier((string) $input['patient_school_id']);
            if ($patient === null) {
                throw ApiException::validationFailure([
                    ['code' => 'patient.not_found', 'message' => 'Patient school ID was not found in the student or employee registry.', 'field' => 'patient_school_id'],
                ]);
            }

            $dow = (int) (new DateTimeImmutable($date))->format('w');

            // 1. Must fit an active availability window (locked so a
            //    concurrent removeSlot cannot race the booking).
            $window = $this->db->query(
                'SELECT `id` FROM `counselling_availability`'
                . ' WHERE `tenant_id` = ? AND `counsellor_user_id` = ? AND `day_of_week` = ? AND `is_active` = 1'
                . ' AND `start_time` <= ? AND `end_time` >= ? LIMIT 1 FOR UPDATE',
                [CurrentTenant::id(), $counsellorId, $dow, $start, $end],
            )->getRowArray();
            if ($window === null) {
                throw new ApiException('statemachine.schedule.outside_availability', 409, [
                    ['code' => 'statemachine.schedule.outside_availability', 'message' => 'No active availability window covers this time.'],
                ]);
            }

            // 2. One appointment per counsellor per time. This path names a
            //    counsellor, so the count stays scoped to them — the
            //    department-wide count belongs to the portal, which pools
            //    cover across every counsellor.
            $overlaps = $this->db->query(
                'SELECT COUNT(*) AS n FROM `counselling_appointments`'
                . ' WHERE `tenant_id` = ? AND `counsellor_user_id` = ? AND `appointment_date` = ?'
                . ' AND `status` IN (?, ?)'
                . ' AND NOT (? <= `start_time` OR ? >= `end_time`) FOR UPDATE',
                [CurrentTenant::id(), $counsellorId, $date, 'scheduled', 'confirmed', $end, $start],
            )->getRowArray();
            if ((int) ($overlaps['n'] ?? 0) >= 1) {
                throw new ApiException('statemachine.schedule.slot_full', 409, [
                    ['code' => 'statemachine.schedule.slot_full', 'message' => 'This counsellor already holds an appointment over that time.'],
                ]);
            }

            $now = $this->utcNow();

            // Three-strike gate: a patient at the limit is refused unless
            // the caller holds the supervisor override.
            if (! $this->canTeamManage()) {
                $strikes = (int) $this->db->query(
                    'SELECT `consecutive_no_shows` FROM `users` WHERE `tenant_id` = ? AND `id` = ?',
                    [CurrentTenant::id(), (int) $patient['id']],
                )->getRowArray()['consecutive_no_shows'] ?? 0;
                if ($strikes >= self::NO_SHOW_STRIKE_LIMIT) {
                    throw new ApiException('counselling.schedule.no_show_limit', 409, [
                        ['code' => 'counselling.schedule.no_show_limit', 'message' => 'Patient has reached the three-strike no-show limit and cannot book without supervisor override.'],
                    ]);
                }
            }

            $this->db->table('counselling_appointments')->insert([
                'tenant_id'          => CurrentTenant::id(),
                'patient_user_id'    => (int) $patient['id'],
                'patient_school_id'  => (string) $input['patient_school_id'],
                'counsellor_user_id' => $counsellorId,
                'appointment_date'   => $date,
                'start_time'         => $start,
                'end_time'           => $end,
                'type'               => (string) ($input['type'] ?? 'initial'),
                'status'             => 'scheduled',
                'reason'             => isset($input['reason']) && $input['reason'] !== '' ? (string) $input['reason'] : null,
                'created_by_user_id' => $userId,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('counselling.appointment_booked', 'counselling_appointments', $id, $userId, [
                'resource_code' => 'appt#' . (string) $id,
            ]);
            $appointmentAt = (new DateTimeImmutable($date . ' ' . $start, new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            foreach (array_unique(array_filter([$patient !== null ? (int) $patient['id'] : 0, $counsellorId])) as $recipient) {
                \Config\Services::notificationOutbox()->enqueue($recipient, 'appointment.scheduled', ['resource_code'=>'appointment#'.$id,'appointment_at'=>$appointmentAt,'appointment_status'=>'scheduled','destination'=>'counselling']);
            }

            $row = $this->db->table('counselling_appointments')->where('counselling_appointments.tenant_id', CurrentTenant::id())->where('id', $id)->get()->getRowArray();
            return $this->appointmentRow($row);
        });
    }

    /**
     * confirm / complete / cancel / no_show — with the three-strike
     * counter side effects on the patient registry.
     *
     * @return array<string, mixed>
     */
    public function transition(int $id, string $action, ?string $cancellationReason): array
    {
        $userId = \App\Auth\CurrentUser::assert();

        if (! isset(self::TRANSITIONS[$action])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => "Unknown action '{$action}'.", 'field' => 'action'],
            ]);
        }

        return $this->txn(function () use ($id, $action, $cancellationReason, $userId): array {
            $row = $this->selectForUpdate('counselling_appointments', ['tenant_id' => CurrentTenant::id(), 'id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Appointment #{$id} not found."],
                ]);
            }
            $this->assertScheduleMutation($row['counsellor_user_id'] !== null ? (int) $row['counsellor_user_id'] : null);

            $current = (string) $row['status'];
            if (! in_array($current, self::TRANSITIONS[$action], true)) {
                throw StateMachineException::invalidTransition($current, self::RESULT[$action], 'schedule');
            }

            if ($action === 'complete') {
                // Hard gate (2026-09-25 staff meeting): an appointment
                // completes only once the session that served it carries
                // notes. Sessions reach the appointment through the queue
                // entry (same resolution as listSessions), so an
                // appointment never started on the board cannot complete
                // here either — the record of the visit is the point.
                $sessionIds = array_map(
                    static fn (array $r): int => (int) $r['counselling_session_id'],
                    $this->db->table('counselling_queue_entries')
                        ->select('counselling_session_id')
                        ->where('tenant_id', CurrentTenant::id())
                        ->where('counselling_appointment_id', $id)
                        ->where('counselling_session_id IS NOT NULL', null, false)
                        ->get()->getResultArray(),
                );
                $noteCount = $sessionIds === []
                    ? 0
                    : (int) $this->db->table('counselling_notes')
                        ->where('counselling_notes.tenant_id', CurrentTenant::id())
                        ->whereIn('session_id', $sessionIds)
                        ->countAllResults();
                if ($noteCount === 0) {
                    throw new ApiException('validation.notes_required', 422, [
                        ['code' => 'validation.notes_required', 'message' => 'Write the session notes before completing this appointment.', 'field' => 'action'],
                    ]);
                }
            }

            $now    = $this->utcNow();
            $update = ['status' => self::RESULT[$action], 'updated_at' => $now];
            if ($action === 'cancel' && $cancellationReason !== null && $cancellationReason !== '') {
                $update['cancellation_reason'] = $cancellationReason;
            }
            // Approval assigns the approver: a portal booking names no
            // counsellor, so confirming is what puts one on the appointment.
            // A row that already has one keeps it — confirming on someone
            // else's behalf must not take their appointment away from them.
            if ($action === 'confirm' && $row['counsellor_user_id'] === null) {
                $update['counsellor_user_id'] = $userId;
            }
            $this->db->table('counselling_appointments')->where('counselling_appointments.tenant_id', CurrentTenant::id())->where('id', $id)->update($update);
            // The snapshot above predates the assignment, so read the counsellor
            // back out of the update — otherwise the person who just claimed
            // this appointment is the one recipient who is not notified.
            $counsellorId = $update['counsellor_user_id'] ?? $row['counsellor_user_id'];
            if (in_array($action, ['cancel', 'no_show'], true)) {
                $this->db->table('counselling_queue_entries')->where('counselling_queue_entries.tenant_id', CurrentTenant::id())->where('counselling_appointment_id', $id)->whereIn('status', ['waiting','called'])->update(['status'=>'skipped','finished_at'=>$now,'updated_at'=>$now]);
            }

            // Three-strike no-show counter (consolidated `users`
            // column; UPDATE by patient_user_id, never a JOIN).
            $patientUserId = isset($row['patient_user_id']) && $row['patient_user_id'] !== null
                ? (int) $row['patient_user_id']
                : null;
            if ($action === 'no_show' && $patientUserId !== null) {
                $this->db->query(
                    'UPDATE `users` SET `consecutive_no_shows` = `consecutive_no_shows` + 1 WHERE `tenant_id` = ? AND `id` = ?',
                    [CurrentTenant::id(), $patientUserId],
                );
            }
            if ($action === 'complete' && $patientUserId !== null) {
                $this->db->query(
                    'UPDATE `users` SET `consecutive_no_shows` = 0 WHERE `tenant_id` = ? AND `id` = ?',
                    [CurrentTenant::id(), $patientUserId],
                );
            }

            $this->audit->enqueue(
                'counselling.appointment_' . self::RESULT[$action],
                'counselling_appointments',
                $id,
                $userId,
                ['outcome' => self::RESULT[$action]],
            );
            $appointmentAt = (new DateTimeImmutable((string) $row['appointment_date'].' '.(string) $row['start_time'], new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            foreach (array_unique(array_filter([$patientUserId ?? 0, (int) $counsellorId])) as $recipient) {
                \Config\Services::notificationOutbox()->enqueue($recipient, 'appointment.'.self::RESULT[$action], ['resource_code'=>'appointment#'.$id,'appointment_at'=>$appointmentAt,'appointment_status'=>self::RESULT[$action],'destination'=>'counselling']);
            }

            $fresh = $this->db->table('counselling_appointments')->where('counselling_appointments.tenant_id', CurrentTenant::id())->where('id', $id)->get()->getRowArray();
            return $this->appointmentRow($fresh);
        });
    }

    // --------------------------------------------------- scheduling analytics

    /**
     * Recompute per-slot no-show analytics from the appointment history
     * (aggregate only, no cross-module JOIN). Deterministic — the maths
     * live in the pure {@see SchedulingAnalytics} calculator. Upserts one
     * row per (counsellor, weekday, time slot).
     *
     * @return array<string, mixed>
     */
    public function recomputeAnalytics(?int $counsellorUserId): array
    {
        $userId = \App\Auth\CurrentUser::assert();
        $counsellorUserId = $this->scheduleScope($counsellorUserId);
        if (! $this->canTeamManage()) {
            $this->policy->check('scheduleManage');
        }

        return $this->txn(function () use ($counsellorUserId, $userId): array {
            // DAYOFWEEK() is 1=Sun..7=Sat; -1 normalises to the 0=Sun..6=Sat
            // convention used everywhere else in this module.
            $sql = 'SELECT `counsellor_user_id`, (DAYOFWEEK(`appointment_date`) - 1) AS dow,'
                 . ' `start_time` AS time_slot, COUNT(*) AS total,'
                 . ' SUM(CASE WHEN `status` = ? THEN 1 ELSE 0 END) AS no_shows'
                 . ' FROM `counselling_appointments`'
                 . ' WHERE `tenant_id` = ? AND `counsellor_user_id` IS NOT NULL';
            $params = ['no_show', CurrentTenant::id()];
            if ($counsellorUserId !== null) {
                $sql     .= ' AND `counsellor_user_id` = ?';
                $params[] = $counsellorUserId;
            }
            $sql .= ' GROUP BY `counsellor_user_id`, dow, `start_time`';

            $rows = $this->db->query($sql, $params)->getResultArray();
            $calc = new SchedulingAnalytics();
            $now  = $this->utcNow();
            $upserted = 0;

            foreach ($rows as $row) {
                $cid     = (int) $row['counsellor_user_id'];
                $dow     = (int) $row['dow'];
                $slot    = (string) $row['time_slot'];
                $total   = (int) $row['total'];
                $noShows = (int) $row['no_shows'];
                $rate    = $calc->noShowRate($total, $noShows);

                $data = [
                    'tenant_id'               => CurrentTenant::id(),
                    'counsellor_user_id'      => $cid,
                    'day_of_week'             => $dow,
                    'time_slot'               => $slot,
                    'total_appointments'      => $total,
                    'total_no_shows'          => $noShows,
                    'no_show_rate'            => $rate,
                    'avg_utilization'         => $calc->avgUtilization($total),
                    'recommended_overbooking' => $calc->recommendedOverbooking($rate),
                    'last_calculated_at'      => $now,
                    'updated_at'              => $now,
                ];

                $existing = $this->db->table('counselling_scheduling_analytics')
                    ->where('counselling_scheduling_analytics.tenant_id', CurrentTenant::id())
                    ->where('counsellor_user_id', $cid)
                    ->where('day_of_week', $dow)
                    ->where('time_slot', $slot)
                    ->get()->getRowArray();

                if ($existing !== null) {
                    $this->db->table('counselling_scheduling_analytics')
                        ->where('counselling_scheduling_analytics.tenant_id', CurrentTenant::id())
                        ->where('id', (int) $existing['id'])->update($data);
                } else {
                    $data['created_at'] = $now;
                    $this->db->table('counselling_scheduling_analytics')->insert(
                        ['tenant_id' => CurrentTenant::id(), ...$data]
                    );
                }
                $upserted++;
            }

            $this->audit->enqueue('counselling.analytics_recomputed', 'counselling_scheduling_analytics', $counsellorUserId ?? 0, $userId, [
                'resource_code' => 'slots:' . (string) $upserted,
            ]);

            return ['recomputed' => $upserted, 'counsellor_user_id' => $counsellorUserId];
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAnalytics(?int $counsellorUserId): array
    {
        $this->policy->check('scheduleRead');
        $counsellorUserId = $this->scheduleScope($counsellorUserId);

        $builder = $this->db->table('counselling_scheduling_analytics')
            ->select('id, counsellor_user_id, day_of_week, time_slot, total_appointments, total_no_shows, no_show_rate, avg_utilization, recommended_overbooking, last_calculated_at')
            ->where('counselling_scheduling_analytics.tenant_id', CurrentTenant::id())
            ->orderBy('counsellor_user_id', 'ASC')
            ->orderBy('day_of_week', 'ASC')
            ->orderBy('time_slot', 'ASC');
        if ($counsellorUserId !== null) {
            $builder->where('counsellor_user_id', $counsellorUserId);
        }

        return array_map(static fn (array $r): array => [
            'id'                      => (int) $r['id'],
            'counsellor_user_id'      => (int) $r['counsellor_user_id'],
            'day_of_week'             => (int) $r['day_of_week'],
            'time_slot'               => (string) $r['time_slot'],
            'total_appointments'      => (int) $r['total_appointments'],
            'total_no_shows'          => (int) $r['total_no_shows'],
            'no_show_rate'            => (float) $r['no_show_rate'],
            'avg_utilization'         => (float) $r['avg_utilization'],
            'recommended_overbooking' => (int) $r['recommended_overbooking'],
            'last_calculated_at'      => $r['last_calculated_at'] !== null ? (string) $r['last_calculated_at'] : null,
        ], $builder->get()->getResultArray());
    }

    // ------------------------------------------------------------ helpers

    /**
     * Serialise an appointment row.
     *
     * `source` distinguishes the two booking origins the Guidance desk reads
     * side by side: `patient` (self-booked through the portal, so
     * `created_by_user_id` is the patient) versus `counsellor` / `staff`
     * (booked on the patient's behalf). Legacy rows with no creator fall
     * back to `staff` rather than claiming the patient booked them.
     *
     * `patient_display_name` / `counsellor_display_name` are only populated
     * by {@see listAppointments()}, which joins `users`; the write paths
     * re-read the bare row and leave them null.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function appointmentRow(array $r): array
    {
        $patientUserId   = isset($r['patient_user_id']) && $r['patient_user_id'] !== null ? (int) $r['patient_user_id'] : null;
        // Null until approved — a portal booking carries no counsellor.
        $counsellorId    = isset($r['counsellor_user_id']) && $r['counsellor_user_id'] !== null ? (int) $r['counsellor_user_id'] : null;
        $createdByUserId = isset($r['created_by_user_id']) && $r['created_by_user_id'] !== null ? (int) $r['created_by_user_id'] : null;

        $source = 'staff';
        if ($createdByUserId !== null) {
            if ($patientUserId !== null && $createdByUserId === $patientUserId) {
                $source = 'patient';
            } elseif ($counsellorId !== null && $createdByUserId === $counsellorId) {
                $source = 'counsellor';
            }
        }

        return [
            'id'                  => (int)    $r['id'],
            'patient_user_id'     => $patientUserId,
            'patient_school_id'   => (string) $r['patient_school_id'],
            'counsellor_user_id'  => $counsellorId,
            'appointment_date'    => (string) $r['appointment_date'],
            'start_time'          => (string) $r['start_time'],
            'end_time'            => (string) $r['end_time'],
            'type'                => (string) $r['type'],
            'status'              => (string) $r['status'],
            'reason'              => $r['reason'] !== null ? (string) $r['reason'] : null,
            'cancellation_reason' => $r['cancellation_reason'] !== null ? (string) $r['cancellation_reason'] : null,
            'source'              => $source,
            'patient_display_name'   => $this->personName($r, 'patient_first_name', 'patient_last_name'),
            'counsellor_display_name' => $this->personName($r, 'counsellor_first_name', 'counsellor_last_name'),
            'created_at'          => (string) $r['created_at'],
        ];
    }

    /**
     * Join-derived display name, or null when the caller read a bare row.
     * Falls back to the school id only where the caller supplies one.
     *
     * @param array<string, mixed> $r
     */
    private function personName(array $r, string $firstKey, string $lastKey): ?string
    {
        $first = trim((string) ($r[$firstKey] ?? ''));
        $last  = trim((string) ($r[$lastKey] ?? ''));
        $name  = trim($first . ' ' . $last);
        if ($name !== '') {
            return $name;
        }

        return null;
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function canTeamManage(): bool
    {
        return \Config\Services::permissionService()->userHas(
            \App\Auth\CurrentUser::assert(),
            'counselling.schedule.team_manage',
        );
    }

    /** Team managers may choose a counsellor; everyone else is self-scoped. */
    private function scheduleScope(?int $requested): ?int
    {
        if ($this->canTeamManage()) {
            return $requested;
        }
        $this->policy->check('scheduleRead');
        return \App\Auth\CurrentUser::assert();
    }

    /**
     * Ownership guard: a counsellor may only mutate their own appointment
     * unless they hold `counselling.schedule.team_manage`.
     *
     * `$counsellorId` is nullable because a portal booking carries no
     * counsellor until someone approves it. An unassigned appointment has no
     * owner to defend, so any counsellor with `counselling.schedule.manage`
     * may act on it — and confirming it is exactly how they claim it.
     */
    private function assertScheduleMutation(?int $counsellorId): void
    {
        if ($this->canTeamManage()) {
            return;
        }
        $this->policy->check('scheduleManage');
        if ($counsellorId !== null && $counsellorId !== \App\Auth\CurrentUser::assert()) {
            throw ApiException::forbidden('rbac.record.forbidden');
        }
    }
}
