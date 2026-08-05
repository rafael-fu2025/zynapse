<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use App\Services\Notify\NotificationOutboxService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Clinic\DTOs\AppointmentDto;
use Modules\Clinic\Policies\ClinicPolicy;
use Throwable;

/**
 * AppointmentService — clinic scheduling (Phase 9).
 *
 * Lifecycle: scheduled → checked_in → completed
 *            scheduled → cancelled | no_show
 *
 * Panel revision (July 2026): appointments are ONLY the scheduling
 * layer — checking in auto-opens the linked clinic ENCOUNTER (the
 * anchor for vitals, treatments and dispensing) and enqueues it into
 * today's walk-in queue, mirroring the kiosk flow in CheckinService.
 *
 * Every state change runs under `selectForUpdate`; the audit AND
 * notification outbox rows are written in the SAME transaction.
 */
final class AppointmentService extends BaseService
{
    private const TRANSITIONS = [
        'checked_in' => ['scheduled'],
        'completed'  => ['checked_in'],
        'cancelled'  => ['scheduled', 'checked_in'],
        'no_show'    => ['scheduled'],
    ];

    public function __construct(
        private readonly ClinicPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly NotificationOutboxService $notify,
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function list(?string $cursor, int $limit, ?string $status): array
    {
        $this->policy->check('appointmentsRead');

        $builder = $this->db->table('clinic_appointments')
            ->select('id, patient_user_id, patient_school_id, provider_user_id, scheduled_at, status, reason, created_at')
            ->where('archived_at', null)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        KeysetPaginator::apply($builder, $cursor, $limit);

        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);
        $decorated = $this->decorate($final['rows']);

        return [
            'data'  => array_map(static fn (array $r) => AppointmentDto::fromRow($r)->toArray(), $decorated),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    /**
     * Resolve `patient_name` (and `patient_kind`) + `provider_name`
     * for every row in a single batch of three small indexed
     * queries. Patients may be students or employees — we look
     * both up and let the first hit win. Provider users are
     * resolved from `auth_identities.secret` (the login email);
     * a nicer display name would be the `users.username` column.
     *
     * Empty input short-circuits to the original rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decorate(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $patientIds = array_values(array_unique(array_filter(array_map(
            static fn (array $r) => isset($r['patient_user_id']) && $r['patient_user_id'] !== null ? (int) $r['patient_user_id'] : null,
            $rows,
        ))));
        $providerIds = array_values(array_unique(array_map(
            static fn (array $r) => (int) $r['provider_user_id'],
            $rows,
        )));

        // Patients are `users` (identity-consolidated) — resolve names
        // + kind directly, no patients_students / patients_employees join.
        $patientNames = [];
        $patientKinds = [];
        if ($patientIds !== []) {
            $pRows = $this->db->table('users')
                ->select('id, kind, first_name, last_name, middle_name')
                ->whereIn('id', $patientIds)
                ->get()->getResultArray();
            foreach ($pRows as $p) {
                $patientNames[(int) $p['id']] = $this->composeName(
                    (string) $p['first_name'],
                    $p['middle_name'] !== null ? (string) $p['middle_name'] : null,
                    (string) $p['last_name'],
                );
                $patientKinds[(int) $p['id']] = $p['kind'] !== null ? (string) $p['kind'] : null;
            }
        }

        // School-id fallback: legacy/demo appointments carry only a
        // `patient_school_id` (patient_user_id NULL). Match the registry
        // by student/employee number so the patient-name tooltip still
        // resolves (same pattern as the encounter list).
        $missingIds = [];
        foreach ($rows as $r) {
            $pid = isset($r['patient_user_id']) && $r['patient_user_id'] !== null ? (int) $r['patient_user_id'] : 0;
            if ($pid <= 0) {
                $missingIds[] = (string) $r['patient_school_id'];
            }
        }
        if ($missingIds !== []) {
            $fallbackRows = $this->db->table('users')
                ->select('id, kind, first_name, last_name, middle_name, student_number, employee_number')
                ->groupStart()
                    ->whereIn('student_number', $missingIds)
                    ->orWhereIn('employee_number', $missingIds)
                ->groupEnd()
                ->get()->getResultArray();
            foreach ($fallbackRows as $p) {
                $byNumber = (string) ($p['student_number'] ?? '') !== ''
                    ? (string) $p['student_number']
                    : (string) $p['employee_number'];
                $patientNames['sid:' . $byNumber] = $this->composeName(
                    (string) $p['first_name'],
                    $p['middle_name'] !== null ? (string) $p['middle_name'] : null,
                    (string) $p['last_name'],
                );
                $patientKinds['sid:' . $byNumber] = $p['kind'] !== null ? (string) $p['kind'] : null;
            }
        }

        // Provider users — resolve a display name (`First Last` when
        // the registry has one, else fall back to the username).
        $providerNames = [];
        if ($providerIds !== []) {
            $uRows = $this->db->table('users')
                ->select('id, username, first_name, last_name')
                ->whereIn('id', $providerIds)
                ->get()->getResultArray();
            foreach ($uRows as $u) {
                $name = $this->composeName(
                    (string) ($u['first_name'] ?? ''),
                    null,
                    (string) ($u['last_name'] ?? ''),
                );
                $providerNames[(int) $u['id']] = $name !== '' ? $name : (string) ($u['username'] ?? ('#' . $u['id']));
            }
        }

        // Linked encounters — a checked-in appointment auto-opens one
        // (panel revision). Lets the SPA jump straight to the visit.
        $encounterIds = [];
        $apptIds = array_map(static fn (array $r) => (int) $r['id'], $rows);
        if ($apptIds !== []) {
            $encRows = $this->db->table('clinic_encounters')
                ->select('id, appointment_id')
                ->whereIn('appointment_id', $apptIds)
                ->where('archived_at', null)
                ->get()->getResultArray();
            foreach ($encRows as $e) {
                $encounterIds[(int) $e['appointment_id']] = (int) $e['id'];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $pid  = isset($r['patient_user_id']) && $r['patient_user_id'] !== null ? (int) $r['patient_user_id'] : 0;
            $prov = (int) $r['provider_user_id'];
            if ($pid > 0) {
                $r['patient_name'] = $patientNames[$pid] ?? null;
                $r['patient_kind'] = $patientKinds[$pid] ?? null;
            } else {
                // School-id fallback key (`sid:` prefix) so rows with a
                // NULL patient_user_id still resolve a display name.
                $sid = (string) $r['patient_school_id'];
                $r['patient_name'] = $patientNames['sid:' . $sid] ?? null;
                $r['patient_kind'] = $patientKinds['sid:' . $sid] ?? null;
            }
            $r['provider_name'] = $providerNames[$prov] ?? null;
            $r['encounter_id']  = $encounterIds[(int) $r['id']] ?? null;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Format a Filipino-style display name: `Juan D. Cruz` (middle
     * initial, last name full). Falls back to whatever pieces are
     * present so a missing middle name never returns a dangling
     * initial.
     */
    private function composeName(string $first, ?string $middle, string $last): string
    {
        $first = trim($first);
        $last  = trim($last);
        if ($first === '' && $last === '') {
            return '';
        }
        if ($middle === null || trim($middle) === '') {
            return trim($first . ' ' . $last);
        }
        return trim($first . ' ' . mb_substr(trim($middle), 0, 1) . '. ' . $last);
    }

    public function schedule(string $patientSchoolId, int $providerUserId, string $scheduledAtUtc, ?string $reason): AppointmentDto
    {
        $this->policy->check('appointmentsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($patientSchoolId, $providerUserId, $scheduledAtUtc, $reason, $userId): AppointmentDto {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            // Resolve the patient to a user id (identity-consolidated).
            [, $patient] = (new PatientLookupService())->findByIdentifier($patientSchoolId);
            $patientUserId = $patient !== null ? (int) $patient['id'] : null;

            $this->db->table('clinic_appointments')->insert([
                'patient_user_id'   => $patientUserId,
                'patient_school_id' => $patientSchoolId,
                'provider_user_id'  => $providerUserId,
                'scheduled_at'      => $scheduledAtUtc,
                'status'            => 'scheduled',
                'reason'            => $reason,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'clinic.appointment_scheduled',
                'clinic_appointments',
                $id,
                $userId,
                ['next_status' => 'scheduled'],
            );

            // Same-transaction notification to the provider (no PII —
            // resource id + UTC slot only).
            $this->notify->enqueue(
                $providerUserId,
                'appointment.assigned',
                ['resource_code' => 'appointment#' . $id, 'scheduled_at' => $scheduledAtUtc],
            );

            $row = $this->db->table('clinic_appointments')->where('id', $id)->get()->getRowArray();
            return AppointmentDto::fromRow($this->decorate([$row])[0]);
        });
    }

    /**
     * SELF-SERVICE booking (student portal). Unlike `schedule()` this
     * does NOT require `appointmentsWrite` — the calling user books a
     * slot for THEMSELVES. Guards:
     *   - `scheduled_at` must parse and be in the future (<= 90 days);
     *   - the patient cannot already hold a scheduled/confirmed clinic
     *     appointment within ±60 minutes (no self double-booking);
     *   - the provider cannot be double-booked within ±60 minutes.
     * Same-transaction insert + provider notification + audit as the
     * staff path.
     */
    public function bookSelf(int $patientUserId, int $providerUserId, string $scheduledAtUtc, ?string $reason): AppointmentDto
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($patientUserId, $providerUserId, $scheduledAtUtc, $reason, $userId): AppointmentDto {
            $when = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduledAtUtc, new DateTimeZone('UTC'));
            if ($when === false) {
                throw new ApiException('validation.invalid', 422, [
                    ['code' => 'validation.field', 'message' => 'scheduled_at must be YYYY-MM-DD HH:MM:SS (UTC).', 'field' => 'scheduled_at'],
                ]);
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($when < $now) {
                throw new ApiException('validation.past', 422, [
                    ['code' => 'validation.field', 'message' => 'Appointment must be in the future.', 'field' => 'scheduled_at'],
                ]);
            }
            if ($when > $now->modify('+90 days')) {
                throw new ApiException('validation.horizon', 422, [
                    ['code' => 'validation.field', 'message' => 'Book within 90 days.', 'field' => 'scheduled_at'],
                ]);
            }

            // The patient books for themselves — resolve their school id.
            $patient = $this->db->table('users')
                ->select('student_number, employee_number')
                ->where('id', $patientUserId)
                ->where('archived_at', null)
                ->get()->getRowArray();
            if ($patient === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => 'Patient record not found.'],
                ]);
            }
            $schoolId = (string) ($patient['student_number'] ?? $patient['employee_number'] ?? '');

            $this->assertNoClash('patient_user_id', $patientUserId, $scheduledAtUtc, 'You already have an appointment in that window.');
            $this->assertNoClash('provider_user_id', $providerUserId, $scheduledAtUtc, 'That provider is already booked at that time.');

            $nowSql = $now->format('Y-m-d H:i:s');
            $this->db->table('clinic_appointments')->insert([
                'patient_user_id'   => $patientUserId,
                'patient_school_id' => $schoolId,
                'provider_user_id'  => $providerUserId,
                'scheduled_at'      => $scheduledAtUtc,
                'status'            => 'scheduled',
                'reason'            => $reason !== null && $reason !== '' ? $reason : null,
                'created_at'        => $nowSql,
                'updated_at'        => $nowSql,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('clinic.appointment_scheduled', 'clinic_appointments', $id, $userId, ['next_status' => 'scheduled']);

            // Same-transaction provider notification (no PII).
            $this->notify->enqueue(
                $providerUserId,
                'appointment.assigned',
                ['resource_code' => 'appointment#' . $id, 'scheduled_at' => $scheduledAtUtc],
            );

            $row = $this->db->table('clinic_appointments')->where('id', $id)->get()->getRowArray();
            return AppointmentDto::fromRow($this->decorate([$row])[0]);
        });
    }

    /**
     * Self-scoped list of a patient's appointments (student portal) —
     * latest 20 with provider name resolved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function myAppointments(int $patientUserId): array
    {
        $rows = $this->db->table('clinic_appointments a')
            ->select('a.id, a.patient_school_id, a.provider_user_id, a.scheduled_at, a.status, a.reason, a.created_at, u.username AS provider_username')
            ->join('users u', 'u.id = a.provider_user_id', 'left')
            ->where('a.patient_user_id', $patientUserId)
            ->orderBy('a.scheduled_at', 'DESC')
            ->limit(20)
            ->get()->getResultArray();

        // NOTE: the DTO's `$row` is `readonly`, so decorate the raw
        // array BEFORE construction (never via withNames).
        return array_map(
            static function (array $r) {
                $r['provider_name'] = $r['provider_username'] ?? null;
                unset($r['provider_username']);
                return AppointmentDto::fromRow($r)->toArray();
            },
            $rows,
        );
    }

    /**
     * Minimal provider list for the student self-booking picker — the
     * clinic staff who can actually see patients. Name only (no roster
     * detail), gated by the student portal permission at the controller.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function providers(): array
    {
        $rows = $this->db->table('auth_groups_users gu')
            ->select('u.id, u.first_name, u.last_name, u.username')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->join('users u', 'u.id = gu.user_id')
            ->where('g.name', 'clinic_staff')
            ->where('u.archived_at', null)
            ->orderBy('u.last_name', 'ASC')
            ->orderBy('u.first_name', 'ASC')
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $name = trim((string) $r['first_name'] . ' ' . (string) $r['last_name']);
            if ($name === '') {
                $name = (string) $r['username'];
            }
            $out[] = ['id' => (int) $r['id'], 'name' => $name];
        }
        return $out;
    }

    /**
     * Reject when a scheduled/confirmed clinic appointment already
     * overlaps the target instant within ±60 minutes.
     */
    private function assertNoClash(string $column, int $userId, string $scheduledAtUtc, string $message): void
    {
        $clash = $this->db->table('clinic_appointments')
            ->where($column, $userId)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where('ABS(TIMESTAMPDIFF(SECOND, scheduled_at, ' . $this->db->escape($scheduledAtUtc) . ')) <', 3600)
            ->where('archived_at', null)
            ->countAllResults();
        if ($clash > 0) {
            throw new ApiException('validation.clash', 409, [
                ['code' => 'validation.clash', 'message' => $message, 'field' => 'scheduled_at'],
            ]);
        }
    }

    public function transition(int $appointmentId, string $nextStatus): AppointmentDto
    {
        $this->policy->check('appointmentsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        $from = self::TRANSITIONS[$nextStatus] ?? null;
        if ($from === null) {
            throw new ApiException('request.validation_failed', 422, [
                ['code' => 'validation.field', 'message' => "Unknown target status '{$nextStatus}'.", 'field' => 'status'],
            ]);
        }

        return $this->txn(function () use ($appointmentId, $nextStatus, $from, $userId): AppointmentDto {
            $row = $this->selectForUpdate('clinic_appointments', ['id' => $appointmentId, 'archived_at' => null]);

            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Appointment #{$appointmentId} not found."],
                ]);
            }
            if (! in_array($row['status'], $from, true)) {
                throw StateMachineException::invalidTransition((string) $row['status'], $nextStatus, 'appointment');
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->table('clinic_appointments')
                ->where('id', $appointmentId)
                ->update(['status' => $nextStatus, 'updated_at' => $now]);

            // Panel revision: checking in creates the day's ENCOUNTER
            // (the anchor for all clinic actions) and queues it — same
            // transaction, same discipline as the kiosk walk-in flow.
            if ($nextStatus === 'checked_in') {
                $this->openEncounterForAppointment($row, $userId, $now);
            }

            $this->audit->enqueue(
                'clinic.appointment_' . strtolower($nextStatus),
                'clinic_appointments',
                $appointmentId,
                $userId,
                ['previous_status' => (string) $row['status'], 'next_status' => $nextStatus],
            );

            $fresh = $this->db->table('clinic_appointments')->where('id', $appointmentId)->get()->getRowArray();
            return AppointmentDto::fromRow($this->decorate([$fresh])[0]);
        });
    }

    /**
     * Auto-open the encounter for a checked-in appointment and enqueue
     * it into today's queue. Runs inside the caller's transaction.
     *
     * The UNIQUE index on `clinic_encounters.appointment_id` is the
     * hard guard against double check-ins; the pre-check just keeps
     * the path idempotent without surfacing a duplicate-key error.
     *
     * @param array<string, mixed> $appt locked appointment row
     */
    private function openEncounterForAppointment(array $appt, int $userId, string $now): int
    {
        $appointmentId = (int) $appt['id'];

        $existing = $this->db->table('clinic_encounters')
            ->select('id')
            ->where('appointment_id', $appointmentId)
            ->get()->getRowArray();
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $reason = isset($appt['reason']) && $appt['reason'] !== null && $appt['reason'] !== ''
            ? (string) $appt['reason']
            : "Scheduled visit — appointment #{$appointmentId}";

        $this->db->table('clinic_encounters')->insert([
            'patient_school_id' => (string) $appt['patient_school_id'],
            'appointment_id'    => $appointmentId,
            'chief_complaint'   => $reason,
            'status'            => 'open',
            'attending_user_id' => (int) $appt['provider_user_id'],
            'started_at'        => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
        $encounterId = (int) $this->db->insertID();

        // Row-locked MAX(position) — same discipline as QueueService /
        // CheckinService so kiosk and desk check-ins never collide.
        $last = $this->db->query(
            'SELECT `position` FROM `clinic_queue_entries` WHERE `queue_date` = ? ORDER BY `position` DESC LIMIT 1 FOR UPDATE',
            [substr($now, 0, 10)],
        )->getRowArray();
        $position = ($last !== null ? (int) $last['position'] : 0) + 1;
        $this->db->table('clinic_queue_entries')->insert([
            'encounter_id' => $encounterId,
            'queue_date'   => substr($now, 0, 10),
            'position'     => $position,
            'status'       => 'waiting',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $this->audit->enqueue(
            'clinic.encounter_opened',
            'clinic_encounters',
            $encounterId,
            $userId,
            ['next_status' => 'open', 'resource_code' => 'appointment#' . $appointmentId],
        );

        return $encounterId;
    }

    /**
     * Single appointment by id. Mirrors the resource shape returned
     * by `list()` and `schedule()` so the SPA detail dialog can use
     * the same `appointmentSchema`.
     */
    public function show(int $appointmentId): AppointmentDto
    {
        $this->policy->check('appointmentsRead');

        $row = $this->db->table('clinic_appointments')
            ->where('id', $appointmentId)
            ->where('archived_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Appointment #{$appointmentId} not found."],
            ]);
        }

        return AppointmentDto::fromRow($this->decorate([$row])[0]);
    }

    /**
     * Partial update of an appointment. Only the Scheduling phase
     * allows edits — once a row has been checked_in, completed,
     * cancelled, or marked no_show, its slot is locked and the user
     * must book a new appointment to change anything.
     *
     * Updatable fields: `patient_school_id`, `provider_user_id`,
     * `scheduled_at`, `reason`. Audit + provider notification fire
     * when `provider_user_id` or `scheduled_at` actually change.
     *
     * @param array{patient_school_id?:string, provider_user_id?:int, scheduled_at?:string, reason?:?string} $input
     */
    public function update(int $appointmentId, array $input): AppointmentDto
    {
        $this->policy->check('appointmentsWrite');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($appointmentId, $input, $userId): AppointmentDto {
            $row = $this->selectForUpdate('clinic_appointments', ['id' => $appointmentId, 'archived_at' => null]);
            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Appointment #{$appointmentId} not found."],
                ]);
            }

            $currentStatus = (string) $row['status'];
            if ($currentStatus !== 'scheduled') {
                throw new ApiException('request.validation_failed', 422, [
                    ['code' => 'appointment.locked', 'message' => "Cannot edit a {$currentStatus} appointment. Schedule a new one instead.", 'field' => 'status'],
                ]);
            }

            $update = [];
            $changed = [];
            if (array_key_exists('patient_school_id', $input) && (string) $input['patient_school_id'] !== (string) $row['patient_school_id']) {
                $update['patient_school_id'] = (string) $input['patient_school_id'];
                // Re-resolve the patient to a user id (identity-consolidated).
                [, $patient] = (new PatientLookupService())->findByIdentifier((string) $input['patient_school_id']);
                $update['patient_user_id'] = $patient !== null ? (int) $patient['id'] : null;
                $changed[] = 'patient_school_id';
            }
            if (array_key_exists('provider_user_id', $input) && (int) $input['provider_user_id'] !== (int) $row['provider_user_id']) {
                $update['provider_user_id'] = (int) $input['provider_user_id'];
                $changed[] = 'provider_user_id';
            }
            if (array_key_exists('scheduled_at', $input) && (string) $input['scheduled_at'] !== (string) $row['scheduled_at']) {
                $update['scheduled_at'] = (string) $input['scheduled_at'];
                $changed[] = 'scheduled_at';
            }
            if (array_key_exists('reason', $input) && (string) ($input['reason'] ?? '') !== (string) ($row['reason'] ?? '')) {
                $update['reason'] = $input['reason'] !== null && $input['reason'] !== '' ? (string) $input['reason'] : null;
                $changed[] = 'reason';
            }

            if ($update === []) {
                // Nothing to do — caller asked for an idempotent edit.
                return AppointmentDto::fromRow($this->decorate([$row])[0]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $update['updated_at'] = $now;

            $this->db->table('clinic_appointments')
                ->where('id', $appointmentId)
                ->update($update);

            $this->audit->enqueue(
                'clinic.appointment_updated',
                'clinic_appointments',
                $appointmentId,
                $userId,
                ['fields' => implode(',', $changed)],
            );

            // Notify the (possibly new) provider when slot or provider
            // changed. Same-transaction guarantee: the row in the
            // notification matches the row the user just saved.
            if (in_array('provider_user_id', $changed, true) || in_array('scheduled_at', $changed, true)) {
                $this->notify->enqueue(
                    (int) ($update['provider_user_id'] ?? $row['provider_user_id']),
                    'appointment.assigned',
                    [
                        'resource_code' => 'appointment#' . $appointmentId,
                        'scheduled_at'   => (string) ($update['scheduled_at'] ?? $row['scheduled_at']),
                    ],
                );
            }

            $fresh = $this->db->table('clinic_appointments')->where('id', $appointmentId)->get()->getRowArray();
            return AppointmentDto::fromRow($this->decorate([$fresh])[0]);
        });
    }

    /**
     * Lazy auto-check-in sweep (panel revision, August 2026): every
     * `scheduled` appointment whose `scheduled_at` falls on today's
     * UTC window is opened + queued. Idempotent against kiosk / staff
     * races — re-running on a row whose encounter already exists
     * short-circuits in `openEncounterForAppointment()`, and the
     * status re-check inside the transaction guarantees we never
     * re-fire `checked_in` on an appointment a parallel kiosk / staff
     * path already advanced.
     *
     * Best-effort, per-row: a single failure (e.g. lock contention,
     * row vanished mid-sweep) is logged and skipped so the staff
     * `today()` read still succeeds.
     *
     * Runs WITHOUT the `appointmentsWrite` policy guard — this is the
     * system-level sweep that backs the staff queue page and is
     * invoked after `queueRead` has already cleared.
     *
     * @return int number of appointments actually advanced
     */
    public function autoCheckInTodaysPending(): int
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $today = substr($now, 0, 10);
        $tomorrow = (new DateTimeImmutable('today', new DateTimeZone('UTC')))
            ->modify('+1 day')
            ->format('Y-m-d');

        $ids = $this->db->table('clinic_appointments')
            ->select('id')
            ->where('status', 'scheduled')
            ->where('archived_at', null)
            ->where('scheduled_at >=', $today . ' 00:00:00')
            ->where('scheduled_at <',  $tomorrow . ' 00:00:00')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $advanced = 0;
        foreach ($ids as $r) {
            $id = (int) $r['id'];
            try {
                $advanced += $this->txn(function () use ($id, $now): int {
                    $row = $this->selectForUpdate('clinic_appointments', [
                        'id'          => $id,
                        'archived_at' => null,
                    ]);
                    if ($row === null || (string) $row['status'] !== 'scheduled') {
                        // Lost the race — a kiosk / staff path already
                        // advanced or cancelled this row. Skip silently.
                        return 0;
                    }

                    $userId = (int) ($row['provider_user_id'] ?? 0);

                    $this->db->table('clinic_appointments')
                        ->where('id', $id)
                        ->update(['status' => 'checked_in', 'updated_at' => $now]);

                    // Mirrors the `transition('checked_in')` cascade —
                    // opens the encounter, queues it under today's
                    // row-locked MAX(position) discipline, fires the
                    // encounter audit.
                    $this->openEncounterForAppointment($row, $userId, $now);

                    $this->audit->enqueue(
                        'clinic.appointment_checked_in',
                        'clinic_appointments',
                        $id,
                        $userId,
                        [
                            'previous_status' => 'scheduled',
                            'next_status'     => 'checked_in',
                            'source'          => 'queue_lazy_sweep',
                        ],
                    );

                    return 1;
                });
            } catch (Throwable $t) {
                log_message('warning', sprintf(
                    'AppointmentService::autoCheckInTodaysPending: id=%d skipped (%s)',
                    $id,
                    $t->getMessage(),
                ));
            }
        }
        return $advanced;
    }
}
