<?php

declare(strict_types=1);

namespace Modules\Counselling\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Analytics\SchedulingAnalytics;
use App\Services\Audit\AuditOutboxService;
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
 *   - Overlap capacity: concurrent scheduled/confirmed appointments in
 *     the window may not exceed `max_slots` (ConflictDetector predicate,
 *     rebuilt with BOUND parameters — the legacy version interpolated).
 *   - Three-strike no-show: `no_show` increments the patient registry
 *     counter; `completed` resets it. Registry is touched via a
 *     separate UPDATE by student_number (never a cross-module JOIN).
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

    public function __construct(
        private readonly CounsellingPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    // --------------------------------------------------- availability

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAvailability(?int $counsellorUserId): array
    {
        $this->policy->check('scheduleRead');

        $builder = $this->db->table('counselling_availability')
            ->select('id, counsellor_user_id, day_of_week, start_time, end_time, max_slots, is_active')
            ->where('is_active', 1)
            ->orderBy('counsellor_user_id', 'ASC')
            ->orderBy('day_of_week', 'ASC')
            ->orderBy('start_time', 'ASC');
        if ($counsellorUserId !== null) {
            $builder->where('counsellor_user_id', $counsellorUserId);
        }

        return array_map(static fn (array $r): array => [
            'id'                 => (int) $r['id'],
            'counsellor_user_id' => (int) $r['counsellor_user_id'],
            'day_of_week'        => (int) $r['day_of_week'],
            'start_time'         => (string) $r['start_time'],
            'end_time'           => (string) $r['end_time'],
            'max_slots'          => (int) $r['max_slots'],
        ], $builder->get()->getResultArray());
    }

    /**
     * @param array<string, mixed> $input validated payload
     * @return array<string, mixed>
     */
    public function addSlot(array $input): array
    {
        $this->policy->check('scheduleManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): array {
            $counsellorId = (int) ($input['counsellor_user_id'] ?? $userId);
            $start        = (string) $input['start_time'];
            $end          = (string) $input['end_time'];
            if ($start >= $end) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'start_time must precede end_time.', 'field' => 'start_time'],
                ]);
            }

            $now = $this->utcNow();
            $this->db->table('counselling_availability')->insert([
                'counsellor_user_id' => $counsellorId,
                'day_of_week'        => (int) $input['day_of_week'],
                'start_time'         => $start,
                'end_time'           => $end,
                'max_slots'          => (int) ($input['max_slots'] ?? 1),
                'is_active'          => 1,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('counselling.availability_added', 'counselling_availability', $id, $userId, []);

            return ['id' => $id];
        });
    }

    /** Soft removal — the slot stops accepting bookings. */
    public function removeSlot(int $id): void
    {
        $this->policy->check('scheduleManage');
        $userId = \App\Auth\CurrentUser::assert();

        $this->txn(function () use ($id, $userId): void {
            $row = $this->selectForUpdate('counselling_availability', ['id' => $id, 'is_active' => 1]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Availability slot #{$id} not found."],
                ]);
            }
            $this->db->table('counselling_availability')->where('id', $id)->update([
                'is_active'  => 0,
                'updated_at' => $this->utcNow(),
            ]);
            $this->audit->enqueue('counselling.availability_removed', 'counselling_availability', $id, $userId, []);
        });
    }

    // --------------------------------------------------- appointments

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function listAppointments(?string $cursor, int $limit, ?string $status): array
    {
        $this->policy->check('scheduleRead');

        $builder = $this->db->table('counselling_appointments')
            ->select('*')
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');
        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows  = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(fn (array $r): array => $this->appointmentRow($r), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * Book inside an availability window with capacity enforcement.
     *
     * @param array<string, mixed> $input validated payload
     * @return array<string, mixed>
     */
    public function book(array $input): array
    {
        $this->policy->check('scheduleManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($input, $userId): array {
            $counsellorId = (int) ($input['counsellor_user_id'] ?? $userId);
            $date         = (string) $input['appointment_date'];
            $start        = (string) $input['start_time'];
            $end          = (string) $input['end_time'];
            if ($start >= $end) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'start_time must precede end_time.', 'field' => 'start_time'],
                ]);
            }

            $dow = (int) (new DateTimeImmutable($date))->format('w');

            // 1. Must fit an active availability window (locked so a
            //    concurrent removeSlot cannot race the booking).
            $window = $this->db->query(
                'SELECT `id`, `max_slots` FROM `counselling_availability`'
                . ' WHERE `counsellor_user_id` = ? AND `day_of_week` = ? AND `is_active` = 1'
                . ' AND `start_time` <= ? AND `end_time` >= ? LIMIT 1 FOR UPDATE',
                [$counsellorId, $dow, $start, $end],
            )->getRowArray();
            if ($window === null) {
                throw new ApiException('statemachine.schedule.outside_availability', 409, [
                    ['code' => 'statemachine.schedule.outside_availability', 'message' => 'No active availability window covers this time.'],
                ]);
            }

            // 2. Overlap capacity (ConflictDetector predicate, bound).
            $overlaps = $this->db->query(
                'SELECT COUNT(*) AS n FROM `counselling_appointments`'
                . ' WHERE `counsellor_user_id` = ? AND `appointment_date` = ?'
                . ' AND `status` IN (?, ?)'
                . ' AND NOT (? <= `start_time` OR ? >= `end_time`) FOR UPDATE',
                [$counsellorId, $date, 'scheduled', 'confirmed', $end, $start],
            )->getRowArray();
            if ((int) ($overlaps['n'] ?? 0) >= (int) $window['max_slots']) {
                throw new ApiException('statemachine.schedule.slot_full', 409, [
                    ['code' => 'statemachine.schedule.slot_full', 'message' => 'This time overlaps a fully booked slot.'],
                ]);
            }

            $now = $this->utcNow();
            [, $patient] = (new \Modules\Clinic\Services\PatientLookupService())->findByIdentifier((string) $input['patient_school_id']);
            $this->db->table('counselling_appointments')->insert([
                'patient_user_id'    => $patient !== null ? (int) $patient['id'] : null,
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

            $row = $this->db->table('counselling_appointments')->where('id', $id)->get()->getRowArray();
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
        $this->policy->check('scheduleManage');
        $userId = \App\Auth\CurrentUser::assert();

        if (! isset(self::TRANSITIONS[$action])) {
            throw ApiException::validationFailure([
                ['code' => 'validation.field', 'message' => "Unknown action '{$action}'.", 'field' => 'action'],
            ]);
        }

        return $this->txn(function () use ($id, $action, $cancellationReason, $userId): array {
            $row = $this->selectForUpdate('counselling_appointments', ['id' => $id]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Appointment #{$id} not found."],
                ]);
            }

            $current = (string) $row['status'];
            if (! in_array($current, self::TRANSITIONS[$action], true)) {
                throw StateMachineException::invalidTransition($current, self::RESULT[$action], 'schedule');
            }

            $now    = $this->utcNow();
            $update = ['status' => self::RESULT[$action], 'updated_at' => $now];
            if ($action === 'cancel' && $cancellationReason !== null && $cancellationReason !== '') {
                $update['cancellation_reason'] = $cancellationReason;
            }
            $this->db->table('counselling_appointments')->where('id', $id)->update($update);

            // Three-strike no-show counter (consolidated `users`
            // column; UPDATE by patient_user_id, never a JOIN).
            $patientUserId = isset($row['patient_user_id']) && $row['patient_user_id'] !== null
                ? (int) $row['patient_user_id']
                : null;
            if ($action === 'no_show' && $patientUserId !== null) {
                $this->db->query(
                    'UPDATE `users` SET `consecutive_no_shows` = `consecutive_no_shows` + 1 WHERE `id` = ?',
                    [$patientUserId],
                );
            }
            if ($action === 'complete' && $patientUserId !== null) {
                $this->db->query(
                    'UPDATE `users` SET `consecutive_no_shows` = 0 WHERE `id` = ?',
                    [$patientUserId],
                );
            }

            $this->audit->enqueue(
                'counselling.appointment_' . self::RESULT[$action],
                'counselling_appointments',
                $id,
                $userId,
                ['outcome' => self::RESULT[$action]],
            );

            $fresh = $this->db->table('counselling_appointments')->where('id', $id)->get()->getRowArray();
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
        $this->policy->check('scheduleManage');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($counsellorUserId, $userId): array {
            // DAYOFWEEK() is 1=Sun..7=Sat; -1 normalises to the 0=Sun..6=Sat
            // convention used everywhere else in this module.
            $sql = 'SELECT `counsellor_user_id`, (DAYOFWEEK(`appointment_date`) - 1) AS dow,'
                 . ' `start_time` AS time_slot, COUNT(*) AS total,'
                 . ' SUM(CASE WHEN `status` = ? THEN 1 ELSE 0 END) AS no_shows'
                 . ' FROM `counselling_appointments`';
            $params = ['no_show'];
            if ($counsellorUserId !== null) {
                $sql     .= ' WHERE `counsellor_user_id` = ?';
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
                    ->where('counsellor_user_id', $cid)
                    ->where('day_of_week', $dow)
                    ->where('time_slot', $slot)
                    ->get()->getRowArray();

                if ($existing !== null) {
                    $this->db->table('counselling_scheduling_analytics')
                        ->where('id', (int) $existing['id'])->update($data);
                } else {
                    $data['created_at'] = $now;
                    $this->db->table('counselling_scheduling_analytics')->insert($data);
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

        $builder = $this->db->table('counselling_scheduling_analytics')
            ->select('id, counsellor_user_id, day_of_week, time_slot, total_appointments, total_no_shows, no_show_rate, avg_utilization, recommended_overbooking, last_calculated_at')
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
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function appointmentRow(array $r): array
    {
        return [
            'id'                  => (int)    $r['id'],
            'patient_user_id'     => isset($r['patient_user_id']) && $r['patient_user_id'] !== null ? (int) $r['patient_user_id'] : null,
            'patient_school_id'   => (string) $r['patient_school_id'],
            'counsellor_user_id'  => (int)    $r['counsellor_user_id'],
            'appointment_date'    => (string) $r['appointment_date'],
            'start_time'          => (string) $r['start_time'],
            'end_time'            => (string) $r['end_time'],
            'type'                => (string) $r['type'],
            'status'              => (string) $r['status'],
            'reason'              => $r['reason'] !== null ? (string) $r['reason'] : null,
            'cancellation_reason' => $r['cancellation_reason'] !== null ? (string) $r['cancellation_reason'] : null,
            'created_at'          => (string) $r['created_at'],
        ];
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
