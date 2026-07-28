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

/**
 * AppointmentService — clinic scheduling (Phase 9).
 *
 * Lifecycle: Scheduled → CheckedIn → Completed
 *            Scheduled → Cancelled | NoShow
 *
 * Every state change runs under `selectForUpdate`; the audit AND
 * notification outbox rows are written in the SAME transaction.
 */
final class AppointmentService extends BaseService
{
    private const TRANSITIONS = [
        'CheckedIn' => ['Scheduled'],
        'Completed' => ['CheckedIn'],
        'Cancelled' => ['Scheduled', 'CheckedIn'],
        'NoShow'    => ['Scheduled'],
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
            ->select('id, patient_school_id, provider_user_id, scheduled_at, status, reason, created_at')
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

        $patientIds = array_values(array_unique(array_map(
            static fn (array $r) => (string) $r['patient_school_id'],
            $rows,
        )));
        $providerIds = array_values(array_unique(array_map(
            static fn (array $r) => (int) $r['provider_user_id'],
            $rows,
        )));

        // Students — patient_school_id matches students.student_number.
        $studentNames = [];
        if ($patientIds !== []) {
            $sRows = $this->db->table('patients_students')
                ->select('student_number, first_name, last_name, middle_name')
                ->whereIn('student_number', $patientIds)
                ->get()->getResultArray();
            foreach ($sRows as $s) {
                $studentNames[(string) $s['student_number']] = $this->composeName(
                    (string) $s['first_name'],
                    $s['middle_name'] !== null ? (string) $s['middle_name'] : null,
                    (string) $s['last_name'],
                );
            }
        }

        // Employees — patient_school_id matches employees.employee_number
        // (in case an employee is referred to the clinic).
        $employeeNames = [];
        if ($patientIds !== []) {
            $eRows = $this->db->table('patients_employees')
                ->select('employee_number, first_name, last_name, middle_name')
                ->whereIn('employee_number', $patientIds)
                ->get()->getResultArray();
            foreach ($eRows as $e) {
                $employeeNames[(string) $e['employee_number']] = $this->composeName(
                    (string) $e['first_name'],
                    $e['middle_name'] !== null ? (string) $e['middle_name'] : null,
                    (string) $e['last_name'],
                );
            }
        }

        // Provider users — users.id with a `username` for display.
        $providerNames = [];
        if ($providerIds !== []) {
            $uRows = $this->db->table('users')
                ->select('id, username')
                ->whereIn('id', $providerIds)
                ->get()->getResultArray();
            foreach ($uRows as $u) {
                $providerNames[(int) $u['id']] = (string) $u['username'];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $sid = (string) $r['patient_school_id'];
            $pid = (int)    $r['provider_user_id'];
            $r['patient_name']  = $studentNames[$sid]  ?? $employeeNames[$sid]  ?? null;
            $r['patient_kind']  = isset($studentNames[$sid]) ? 'student'
                                 : (isset($employeeNames[$sid]) ? 'employee' : null);
            $r['provider_name'] = $providerNames[$pid] ?? null;
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

            $this->db->table('clinic_appointments')->insert([
                'patient_school_id' => $patientSchoolId,
                'provider_user_id'  => $providerUserId,
                'scheduled_at'      => $scheduledAtUtc,
                'status'            => 'Scheduled',
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
                ['next_status' => 'Scheduled'],
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
     * allows edits — once a row has been CheckedIn, Completed,
     * Cancelled, or marked NoShow, its slot is locked and the user
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
            if ($currentStatus !== 'Scheduled') {
                throw new ApiException('request.validation_failed', 422, [
                    ['code' => 'appointment.locked', 'message' => "Cannot edit a {$currentStatus} appointment. Schedule a new one instead.", 'field' => 'status'],
                ]);
            }

            $update = [];
            $changed = [];
            if (array_key_exists('patient_school_id', $input) && (string) $input['patient_school_id'] !== (string) $row['patient_school_id']) {
                $update['patient_school_id'] = (string) $input['patient_school_id'];
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
}
