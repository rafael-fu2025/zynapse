<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Services\Audit\AuditOutboxService;
use Config\Services;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\Policies\ClinicPolicy;

/**
 * CheckinService — kiosk self-service check-in (Phase 17, recycled
 * from legacy synapse_ag Iot\CheckinController).
 *
 * Dispatch rules (kiosk gap analysis, July 2026):
 *   1. Resolve the patient by scan method (qr → qr_code,
 *      rfid → rfid_tag, manual → student/employee number) — students
 *      first, then the employee registry. Unknown → 404.
 *   2. Duplicate guard: a non-duplicate check-in for the same patient
 *      within ±5 minutes of `scanned_at` short-circuits to a
 *      `duplicate` outcome (the legacy checkDuplicateSync window).
 *   3. A counselling appointment TODAY (scheduled/confirmed) wins:
 *      `scheduled` is confirmed in place; `confirmed` reports
 *      "already checked in". counselling_appointments is touched as
 *      shared reference data (UPDATE by logical key, never a JOIN).
 *   4. A CLINIC appointment today in `scheduled` is checked in via the
 *      existing AppointmentService transition — the same path the
 *      Appointments screen uses — which auto-opens the linked
 *      encounter and queues it (no parallel encounter-creation path).
 *   5. Otherwise a walk-in encounter is opened (pending triage) and
 *      enqueued into today's queue under the same row-locked
 *      MAX(position) discipline as QueueService::enqueue.
 *
 * Queue outcomes carry `estimated_wait_minutes`, derived from today's
 * rolling average service time (started_at → finished_at).
 *
 * The legacy offline buffer lives CLIENT-side now: the kiosk SPA
 * queues scans in localStorage and replays them with the original
 * `scanned_at`, so the duplicate window still holds on sync.
 */
final class CheckinService extends BaseService
{
    private const DUPLICATE_WINDOW_SECONDS = 300;

    /** Fallback service minutes while today has no completed sessions. */
    private const DEFAULT_SERVICE_MINUTES = 10;

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
    ) {
        parent::__construct();
    }

    /**
     * @param array<string, mixed> $input validated payload
     * @return array<string, mixed>
     */
    public function scan(array $input): array
    {
        $this->policy->check('checkinRecord');
        $userId = \App\Auth\CurrentUser::assert();

        $method    = (string) ($input['method'] ?? 'manual');
        $stationId = isset($input['station_id']) && $input['station_id'] !== '' ? (string) $input['station_id'] : 'Kiosk-01';
        $purpose   = isset($input['purpose']) && $input['purpose'] !== '' ? mb_substr((string) $input['purpose'], 0, 120) : null;
        $guestName = isset($input['guest_name']) ? trim((string) $input['guest_name']) : '';
        $scannedAt = isset($input['scanned_at']) && $input['scanned_at'] !== ''
            ? (string) $input['scanned_at']
            : $this->utcNow();

        return $this->txn(function () use ($input, $method, $stationId, $purpose, $guestName, $scannedAt, $userId): array {
            // 0. Guest walk-in: a person with NO account / patient record
            //    checks in directly by name. patient_user_id + school id
            //    stay NULL; the name is recorded in guest_name.
            //
            //    Guest duplicate guard (mirrors the registered guard
            //    below): the same walk-in name within the ±5-minute
            //    window is treated as a duplicate. A kiosk can double-
            //    fire (double-tap / Enter + button), and without this a
            //    repeated tap silently opened a SECOND queue entry.
            //    Names are normalized (case + whitespace) before
            //    comparison so "juan dela cruz" == "Juan Dela Cruz".
            if ($guestName !== '') {
                $from = $this->shift($scannedAt, -self::DUPLICATE_WINDOW_SECONDS);
                $to   = $this->shift($scannedAt, +self::DUPLICATE_WINDOW_SECONDS);
                $dup  = $this->db->query(
                    'SELECT `id` FROM `clinic_checkins`'
                    . ' WHERE `guest_name` IS NOT NULL AND `outcome` != ?'
                    . ' AND LOWER(REPLACE(`guest_name`, \' \', \'\')) = LOWER(REPLACE(?, \' \', \'\'))'
                    . ' AND `scanned_at` BETWEEN ? AND ? LIMIT 1 FOR UPDATE',
                    ['duplicate', $guestName, $from, $to],
                )->getRowArray();
                if ($dup !== null) {
                    $checkinId = $this->insertCheckin(null, null, $method, $stationId, 'duplicate', null, null, $userId, $scannedAt, $purpose, $guestName);
                    return $this->result($checkinId, 'duplicate', null, 'guest', 'Already checked in within the last 5 minutes.', null, null, $guestName);
                }
                return $this->guestWalkIn($guestName, $method, $stationId, $purpose, $scannedAt, $userId);
            }

            // 1. Resolve the patient by scan method against the
            //    consolidated `users` table (identity-consolidated).
            $identifier = (string) $input['identifier'];
            [$kind, $patient] = (new PatientLookupService())->findForCheckin($method, $identifier);
            if ($patient === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'Unknown or unregistered ID.'],
                ]);
            }
            $patientUserId = (int) $patient['id'];
            $schoolId = (string) ($kind === 'student' ? $patient['student_number'] : $patient['employee_number']);

            // 2. Legacy ±5-minute duplicate window (locked so two kiosks
            //    replaying the same buffered scan cannot both pass).
            $from = $this->shift($scannedAt, -self::DUPLICATE_WINDOW_SECONDS);
            $to   = $this->shift($scannedAt, +self::DUPLICATE_WINDOW_SECONDS);
            $dup  = $this->db->query(
                'SELECT `id` FROM `clinic_checkins`'
                . ' WHERE `patient_school_id` = ? AND `outcome` != ?'
                . ' AND `scanned_at` BETWEEN ? AND ? LIMIT 1 FOR UPDATE',
                [$schoolId, 'duplicate', $from, $to],
            )->getRowArray();
            if ($dup !== null) {
                $checkinId = $this->insertCheckin($patientUserId, $schoolId, $method, $stationId, 'duplicate', null, null, $userId, $scannedAt, $purpose);
                return $this->result($checkinId, 'duplicate', $patient, $kind, 'Already checked in within the last 5 minutes.', null, null);
            }

            // 3. Counselling appointment today wins (shared reference
            //    data — UPDATE by logical key, never a JOIN).
            $scanDate = substr($scannedAt, 0, 10);
            $appt = $this->db->query(
                'SELECT `id`, `status`, `start_time`, `end_time` FROM `counselling_appointments`'
                . ' WHERE `patient_school_id` = ? AND `appointment_date` = ?'
                . ' AND `status` IN (?, ?) ORDER BY `start_time` ASC LIMIT 1 FOR UPDATE',
                [$schoolId, $scanDate, 'scheduled', 'confirmed'],
            )->getRowArray();

            if ($appt !== null) {
                $window = substr((string) $appt['start_time'], 0, 5) . '–' . substr((string) $appt['end_time'], 0, 5);
                if ((string) $appt['status'] === 'scheduled') {
                    $this->db->table('counselling_appointments')
                        ->where('id', (int) $appt['id'])
                        ->update(['status' => 'confirmed', 'updated_at' => $this->utcNow()]);
                    $checkinId = $this->insertCheckin($patientUserId, $schoolId, $method, $stationId, 'counselling_confirmed', (int) $appt['id'], null, $userId, $scannedAt, $purpose);
                    return $this->result($checkinId, 'counselling_confirmed', $patient, $kind, "Counselling booking {$window} confirmed.", (int) $appt['id'], null);
                }
                $checkinId = $this->insertCheckin($patientUserId, $schoolId, $method, $stationId, 'counselling_already', (int) $appt['id'], null, $userId, $scannedAt, $purpose);
                return $this->result($checkinId, 'counselling_already', $patient, $kind, "Counselling appointment {$window} already checked in.", (int) $appt['id'], null);
            }

            // 4. CLINIC appointment today (kiosk gap #1, panel revision):
            //    the queue page now auto-checks-in any scheduled
            //    appointment whose scheduled_at falls on the current
            //    UTC day, so a kiosk scan may arrive AFTER the linked
            //    encounter is already open. Three branches cover the
            //    full state graph:
            //      - scheduled  → reuse the standard checked_in
            //                     transition (existing path)
            //      - checked_in → encounter already exists; surface
            //                     the existing queue position without
            //                     mutating anything
            //      - completed / cancelled / no_show → appointment is
            //                     closed; report and fall through to
            //                     the walk-in path so the patient
            //                     isn't silently dropped
            $clinicAppt = $this->db->query(
                'SELECT `id`, `status` FROM `clinic_appointments`'
                . ' WHERE `patient_school_id` = ?'
                . ' AND `scheduled_at` >= ? AND `scheduled_at` < ?'
                . ' AND `archived_at` IS NULL'
                . ' ORDER BY `scheduled_at` ASC LIMIT 1 FOR UPDATE',
                [$schoolId, $scanDate . ' 00:00:00', $scanDate . ' 23:59:59'],
            )->getRowArray();

            if ($clinicAppt !== null) {
                $apptId        = (int) $clinicAppt['id'];
                $apptStatus    = (string) $clinicAppt['status'];
                $encounterId   = null;
                $queue         = null;
                $outcome       = 'clinic_appointment_confirmed';
                $message       = '';

                if ($apptStatus === 'scheduled') {
                    (new AppointmentService(new ClinicPolicy(), $this->audit, Services::notificationOutbox()))
                        ->transition($apptId, 'checked_in');

                    $enc = $this->db->table('clinic_encounters')
                        ->select('id')
                        ->where('appointment_id', $apptId)
                        ->get()->getRowArray();
                    $encounterId = $enc !== null ? (int) $enc['id'] : null;

                    if ($encounterId !== null) {
                        $q = $this->db->table('clinic_queue_entries')
                            ->select('position')
                            ->where('encounter_id', $encounterId)
                            ->get()->getRowArray();
                        if ($q !== null) {
                            $queue = [
                                'encounter_id'           => $encounterId,
                                'position'               => (int) $q['position'],
                                'estimated_wait_minutes' => $this->estimatedWaitMinutes((int) $q['position']),
                            ];
                        }
                    }

                    $message = $queue !== null
                        ? "Clinic appointment #{$apptId} checked in — queue position {$queue['position']}."
                        : "Clinic appointment #{$apptId} checked in.";
                } elseif ($apptStatus === 'checked_in') {
                    // The queue page already auto-checked-in this
                    // patient earlier in the day. Find the existing
                    // encounter + queue row and surface it.
                    $outcome = 'clinic_appointment_already';

                    $enc = $this->db->table('clinic_encounters')
                        ->select('id')
                        ->where('appointment_id', $apptId)
                        ->get()->getRowArray();
                    $encounterId = $enc !== null ? (int) $enc['id'] : null;

                    if ($encounterId !== null) {
                        $q = $this->db->table('clinic_queue_entries')
                            ->select('position')
                            ->where('encounter_id', $encounterId)
                            ->get()->getRowArray();
                        if ($q !== null) {
                            $queue = [
                                'encounter_id'           => $encounterId,
                                'position'               => (int) $q['position'],
                                'estimated_wait_minutes' => $this->estimatedWaitMinutes((int) $q['position']),
                            ];
                            $message = "Clinic appointment #{$apptId} is already in the queue at position {$queue['position']}.";
                        } else {
                            $message = "Clinic appointment #{$apptId} is already checked in.";
                        }
                    } else {
                        $message = "Clinic appointment #{$apptId} is already checked in.";
                    }
                } else {
                    // completed / cancelled / no_show — appointment is
                    // closed; the kiosk falls through to the walk-in
                    // path below so the patient isn't silently dropped.
                    $clinicAppt = null;
                }

                if ($clinicAppt !== null) {
                    $checkinId = $this->insertCheckin($patientUserId, $schoolId, $method, $stationId, $outcome, null, $encounterId, $userId, $scannedAt, $purpose);
                    return $this->result(
                        $checkinId,
                        $outcome,
                        $patient,
                        $kind,
                        $message,
                        null,
                        $queue,
                    );
                }
            }

            // 5. Fallback: open a pending-triage walk-in encounter + queue it.
            $now = $this->utcNow();
            $this->db->table('clinic_encounters')->insert([
                'patient_user_id'   => $patientUserId,
                'patient_school_id' => $schoolId,
                'chief_complaint'   => "Kiosk check-in (pending triage) — station {$stationId}",
                'status'            => 'open',
                'attending_user_id' => $userId,
                'started_at'        => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $encounterId = (int) $this->db->insertID();

            $position = $this->enqueue($encounterId, substr($now, 0, 10));

            $wait = $this->estimatedWaitMinutes($position);
            $checkinId = $this->insertCheckin($patientUserId, $schoolId, $method, $stationId, 'clinic_queued', null, $encounterId, $userId, $scannedAt, $purpose);
            return $this->result(
                $checkinId,
                'clinic_queued',
                $patient,
                $kind,
                "Added to the clinic queue at position {$position}.",
                null,
                ['encounter_id' => $encounterId, 'position' => $position, 'estimated_wait_minutes' => $wait],
            );
        });
    }

    /**
     * Today's check-in trail (staff view).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listToday(): array
    {
        $this->policy->check('checkinRead');

        $rows = $this->db->table('clinic_checkins')
            ->where('scanned_at >=', substr($this->utcNow(), 0, 10) . ' 00:00:00')
            ->orderBy('scanned_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $r): array => [
            'id'                         => (int) $r['id'],
            'patient_school_id'          => $r['patient_school_id'] !== null ? (string) $r['patient_school_id'] : null,
            'guest_name'                 => $r['guest_name'] !== null ? (string) $r['guest_name'] : null,
            'method'                     => (string) $r['method'],
            'station_id'                 => $r['station_id'] !== null ? (string) $r['station_id'] : null,
            'outcome'                    => (string) $r['outcome'],
            'purpose'                    => $r['purpose'] !== null ? (string) $r['purpose'] : null,
            'counselling_appointment_id' => $r['counselling_appointment_id'] !== null ? (int) $r['counselling_appointment_id'] : null,
            'encounter_id'               => $r['encounter_id'] !== null ? (int) $r['encounter_id'] : null,
            'scanned_at'                 => (string) $r['scanned_at'],
        ], $rows);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Guest walk-in — no registry record. Creates a guest encounter +
     * queue entry + checkin row, all carrying the typed name.
     */
    private function guestWalkIn(
        string $name,
        string $method,
        string $stationId,
        ?string $purpose,
        string $scannedAt,
        int $userId,
    ): array {
        $now = $this->utcNow();

        $this->db->table('clinic_encounters')->insert([
            'patient_user_id'   => null,
            'patient_school_id' => null,
            'guest_name'        => $name,
            'chief_complaint'   => "Guest walk-in (pending triage) — station {$stationId}",
            'status'            => 'open',
            'attending_user_id' => $userId,
            'started_at'        => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $encounterId = (int) $this->db->insertID();

        $position = $this->enqueue($encounterId, substr($now, 0, 10));
        $wait     = $this->estimatedWaitMinutes($position);
        $checkinId = $this->insertCheckin(null, null, $method, $stationId, 'clinic_queued', null, $encounterId, $userId, $scannedAt, $purpose, $name);

        return $this->result(
            $checkinId,
            'clinic_queued',
            null,
            'guest',
            "Added to the clinic queue at position {$position}.",
            null,
            ['encounter_id' => $encounterId, 'position' => $position, 'estimated_wait_minutes' => $wait],
            $name,
        );
    }

    /**
     * Insert a queue entry for a fresh encounter under the same
     * row-locked MAX(position) discipline as QueueService::enqueue.
     */
    private function enqueue(int $encounterId, string $date): int
    {
        $last = $this->db->query(
            'SELECT `position` FROM `clinic_queue_entries` WHERE `queue_date` = ? ORDER BY `position` DESC LIMIT 1 FOR UPDATE',
            [$date],
        )->getRowArray();
        $position = ($last !== null ? (int) $last['position'] : 0) + 1;
        $now = $this->utcNow();

        $this->db->table('clinic_queue_entries')->insert([
            'encounter_id' => $encounterId,
            'queue_date'   => $date,
            'position'     => $position,
            'status'       => 'waiting',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        return $position;
    }

    private function insertCheckin(
        ?int $patientUserId,
        ?string $schoolId,
        string $method,
        string $stationId,
        string $outcome,
        ?int $appointmentId,
        ?int $encounterId,
        int $userId,
        string $scannedAt,
        ?string $purpose = null,
        ?string $guestName = null,
    ): int {
        $this->db->table('clinic_checkins')->insert([
            'patient_user_id'            => $patientUserId,
            'patient_school_id'          => $schoolId,
            'method'                     => $method,
            'station_id'                 => $stationId,
            'outcome'                    => $outcome,
            'purpose'                    => $purpose,
            'guest_name'                 => $guestName,
            'counselling_appointment_id' => $appointmentId,
            'encounter_id'               => $encounterId,
            'recorded_by_user_id'        => $userId,
            'scanned_at'                 => $scannedAt,
            'created_at'                 => $this->utcNow(),
        ]);
        $id = (int) $this->db->insertID();

        // Audit: outcome only. The purpose/guest name may be free-typed
        // patient text (PII-ish); it stays on the record + staff trail,
        // never in the append-only audit context.
        $this->audit->enqueue('clinic.checkin_recorded', 'clinic_checkins', $id, $userId, [
            'outcome' => $outcome,
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed>|null $patient student/employee row, or
     *        null for a guest walk-in
     * @param array<string, int>|null   $queue
     * @return array<string, mixed>
     */
    private function result(int $checkinId, string $outcome, ?array $patient, string $kind, string $message, ?int $appointmentId, ?array $queue, ?string $guestName = null): array
    {
        // Severe-allergy alert (legacy hasSevereAllergy) — student
        // registry only (patient_allergies keys on student_id). The
        // kiosk screen is readable by bystanders, so the alert is a
        // MASKED flag: allergen names stay off the shared display and
        // live on the staff triage surface instead (kiosk gap #5).
        $hasSevere = false;
        if ($kind === 'student' && $patient !== null) {
            $severe = $this->db->table('patient_allergies')
                ->select('id')
                ->where('user_id', (int) $patient['id'])
                ->where('severity', 'severe')
                ->get()->getRowArray();
            $hasSevere = $severe !== null;
        }

        if ($patient !== null) {
            $number = (string) ($kind === 'student' ? $patient['student_number'] : $patient['employee_number']);
            $name   = trim((string) $patient['first_name'] . ' ' . (string) $patient['last_name']);
            $course = $kind === 'student'
                ? ($patient['course'] !== null ? (string) $patient['course'] : null)
                : (isset($patient['department']) && $patient['department'] !== null ? (string) $patient['department'] : null);
            $yearLevel = $kind === 'student' && $patient['year_level'] !== null ? (int) $patient['year_level'] : null;
        } else {
            $number    = '';
            $name      = $guestName ?? 'Guest';
            $course    = null;
            $yearLevel = null;
        }

        return [
            'id'      => $checkinId,
            'outcome' => $outcome,
            'message' => $message,
            'student' => [
                'student_number' => $number,
                'name'           => $name,
                'course'         => $course,
                'year_level'     => $yearLevel,
                'kind'           => $kind,
            ],
            'allergy_alert' => $hasSevere
                ? 'SEVERE allergy on file — alert clinic staff before treatment.'
                : null,
            'counselling_appointment_id' => $appointmentId,
            'queue' => $queue,
        ];
    }

    /**
     * Indicative wait for a queue position: people ahead × today's
     * rolling average service time (started_at → finished_at over
     * completed sessions), falling back to a fixed default while the
     * day has no history (kiosk gap #3).
     */
    private function estimatedWaitMinutes(int $position): int
    {
        $today = substr($this->utcNow(), 0, 10);

        $avg = $this->db->query(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, `started_at`, `finished_at`)) AS avg_min'
            . ' FROM `clinic_queue_entries`'
            . ' WHERE `queue_date` = ? AND `started_at` IS NOT NULL AND `finished_at` IS NOT NULL',
            [$today],
        )->getRowArray();
        $serviceMinutes = $avg !== null && $avg['avg_min'] !== null
            ? max(1.0, (float) $avg['avg_min'])
            : (float) self::DEFAULT_SERVICE_MINUTES;

        $ahead = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM `clinic_queue_entries`'
            . ' WHERE `queue_date` = ? AND `position` < ? AND `status` IN (?, ?, ?)',
            [$today, $position, 'waiting', 'called', 'in_session'],
        )->getRowArray()['c'] ?? 0);

        return (int) round($ahead * $serviceMinutes);
    }

    private function shift(string $datetime, int $seconds): string
    {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))
            ->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    private function utcNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
