<?php

declare(strict_types=1);

namespace Modules\Clinic\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Modules\Shared\ManilaDay;
use App\Modules\Shared\StateMachineException;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;
use App\Services\Notify\NotificationOutboxService;
use CodeIgniter\Database\RawSql;
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
        'no_show'    => ['scheduled', 'checked_in'],
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
    public function list(?string $cursor, int $limit, ?string $status, ?string $q = null): array
    {
        $this->policy->check('appointmentsRead');

        $builder = $this->db->table('clinic_appointments AS a')
            ->where('a.tenant_id', CurrentTenant::id())
            ->select('a.id, a.patient_user_id, a.patient_school_id, a.provider_user_id, a.scheduled_at, a.status, a.reason, a.created_at')
            ->where('a.archived_at', null)
            ->orderBy('a.created_at', 'DESC')
            ->orderBy('a.id', 'DESC');

        if ($status !== null && $status !== '') {
            $builder->where('a.status', $status);
        }

        // Free-text search (2026-08-05): matches the appointment #,
        // the patient school id, the patient's name, the provider's
        // name, or the schedule window (e.g. `2026-08-05` or `14:00`).
        // Users are joined ONLY when a term is present so the default
        // list stays lean — names are decorated post-query by
        // `decorate()`. The patient join mirrors the encounter/queue
        // fallback so legacy rows (patient_user_id NULL) still match
        // by registry number.
        if ($q !== null && $q !== '') {
            // The UI placeholder hints "# · name · ID · provider · date",
            // so users naturally type `#67`. A leading '#' is just the
            // appointment-number hint — strip it before searching (IDs
            // are numeric, names/numbers never legitimately start with
            // '#', so removing every leading '#' is safe).
            if (str_starts_with($q, '#')) {
                $q = ltrim($q, '#');
            }

            $builder
                ->join(
                    'users AS pa',
                    'pa.id = a.patient_user_id'
                    . ' OR (a.patient_user_id IS NULL'
                    .   ' AND (pa.student_number = a.patient_school_id'
                    .     ' OR pa.employee_number = a.patient_school_id))',
                    'left',
                )
                ->join('users AS pr', 'pr.id = a.provider_user_id', 'left')
                ->groupStart()
                    ->like('a.id', $q)
                    ->orLike('a.patient_school_id', $q)
                    ->orLike('pa.first_name', $q)
                    ->orLike('pa.middle_name', $q)
                    ->orLike('pa.last_name', $q)
                    ->orLike('pr.first_name', $q)
                    ->orLike('pr.last_name', $q)
                    ->orLike('a.scheduled_at', $q)
                    // Month/date/time matching (2026-08-05). The raw
                    // `a.scheduled_at` LIKE above only matches the stored
                    // `YYYY-MM-DD HH:MM:SS` (and its left-prefixes). Users
                    // think in the DISPLAY format, so also match against
                    // the formatted month name ("Aug" / "August"), the
                    // `M/D` date, and the `M/D/YYYY` + 12h clock. The
                    // scheduled_at is stored in UTC but rendered in
                    // Asia/Manila (UTC+8, no DST), so CONVERT_TZ first —
                    // otherwise a 14:00 UTC row (shown "10:00 PM") would
                    // only match "2:00 pm", not what the user sees. All of
                    // these are case-insensitive via the utf8mb4_unicode_ci
                    // collation — same as the Patients live search.
                    // NOTE: the field must be a RawSql expression — CI4
                    // would otherwise quote the DATE_FORMAT(...) call and
                    // the whole LIKE would silently match nothing.
                    ->orLike(new RawSql("DATE_FORMAT(CONVERT_TZ(a.scheduled_at, '+00:00', '+08:00'), '%b %e, %Y %l:%i %p')"), $q)
                    ->orLike(new RawSql("DATE_FORMAT(CONVERT_TZ(a.scheduled_at, '+00:00', '+08:00'), '%M %e, %Y %l:%i %p')"), $q)
                    ->orLike(new RawSql("DATE_FORMAT(CONVERT_TZ(a.scheduled_at, '+00:00', '+08:00'), '%m/%d/%Y')"), $q)
                    ->orLike(new RawSql("DATE_FORMAT(CONVERT_TZ(a.scheduled_at, '+00:00', '+08:00'), '%m/%d')"), $q)
                    ->orLike(new RawSql("DATE_FORMAT(CONVERT_TZ(a.scheduled_at, '+00:00', '+08:00'), '%l:%i %p')"), $q)
                ->groupEnd();
        }

        KeysetPaginator::apply($builder, $cursor, $limit, 'a.created_at', 'a.id');

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
                ->where('users.tenant_id', CurrentTenant::id())
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
                ->where('users.tenant_id', CurrentTenant::id())
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
                ->where('users.tenant_id', CurrentTenant::id())
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
                ->where('clinic_encounters.tenant_id', CurrentTenant::id())
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

            // The provider must be a real, active user of THIS tenant —
            // the notification outbox fans out to this id, so an
            // arbitrary number must not be accepted (2026-09 audit).
            $provider = $this->db->table('users')
                ->where('users.tenant_id', CurrentTenant::id())
                ->where('id', $providerUserId)
                ->where('archived_at', null)
                ->get()->getRowArray();
            if ($provider === null || (string) $provider['status'] !== 'active') {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'provider_user_id must be an active staff member.', 'field' => 'provider_user_id'],
                ]);
            }

            // Resolve the patient to a user id (identity-consolidated).
            [, $patient] = (new PatientLookupService())->findByIdentifier($patientSchoolId);
            $patientUserId = $patient !== null ? (int) $patient['id'] : null;

            // Same double-booking discipline as self-service: neither the
            // provider nor the patient may hold another scheduled visit
            // within ±60 minutes of the slot (back-dating for record
            // correction stays allowed, clashes do not).
            $this->assertNoClash('provider_user_id', $providerUserId, $scheduledAtUtc, 'The provider already has an appointment within an hour of this slot.');
            if ($patientUserId !== null) {
                $this->assertNoClash('patient_user_id', $patientUserId, $scheduledAtUtc, 'The patient already has an appointment within an hour of this slot.');
            }

            // QR proof-of-booking: mint a high-entropy token, store only its
            // HMAC hash; the plaintext is returned for QR rendering.
            [$plain, $hash] = $this->newQrToken();

            $this->db->table('clinic_appointments')->insert([
                'tenant_id'         => CurrentTenant::id(),
                'patient_user_id'   => $patientUserId,
                'patient_school_id' => $patientSchoolId,
                'provider_user_id'  => $providerUserId,
                'scheduled_at'      => $scheduledAtUtc,
                'status'            => 'scheduled',
                'reason'            => $reason,
                'qr_token_hash'     => $hash,
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
                ['resource_code' => 'appointment#' . $id, 'scheduled_at' => $scheduledAtUtc, 'appointment_at' => $scheduledAtUtc, 'appointment_status' => 'scheduled', 'destination' => 'clinic'],
            );
            if ($patientUserId !== null && $patientUserId !== $providerUserId) {
                $this->notify->enqueue($patientUserId, 'appointment.scheduled', ['resource_code' => 'appointment#' . $id, 'appointment_at' => $scheduledAtUtc, 'appointment_status' => 'scheduled', 'destination' => 'clinic']);
            }

            $row = $this->db->table('clinic_appointments')->where('clinic_appointments.tenant_id', CurrentTenant::id())->where('id', $id)->get()->getRowArray();
            return AppointmentDto::fromRow($this->decorate([$row])[0])->withQrToken($plain);
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
                ->where('users.tenant_id', CurrentTenant::id())
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
            // QR proof-of-booking: mint a high-entropy token, store only its
            // HMAC hash; the plaintext is returned for QR rendering.
            [$plain, $hash] = $this->newQrToken();

            $this->db->table('clinic_appointments')->insert([
                'tenant_id'         => CurrentTenant::id(),
                'patient_user_id'   => $patientUserId,
                'patient_school_id' => $schoolId,
                'provider_user_id'  => $providerUserId,
                'scheduled_at'      => $scheduledAtUtc,
                'status'            => 'scheduled',
                'reason'            => $reason !== null && $reason !== '' ? $reason : null,
                'qr_token_hash'     => $hash,
                'created_at'        => $nowSql,
                'updated_at'        => $nowSql,
            ]);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue('clinic.appointment_scheduled', 'clinic_appointments', $id, $userId, ['next_status' => 'scheduled']);

            // Same-transaction provider notification (no PII).
            $this->notify->enqueue(
                $providerUserId,
                'appointment.assigned',
                ['resource_code' => 'appointment#' . $id, 'scheduled_at' => $scheduledAtUtc, 'appointment_at' => $scheduledAtUtc, 'appointment_status' => 'scheduled', 'destination' => 'clinic'],
            );
            if ($patientUserId !== $providerUserId) {
                $this->notify->enqueue($patientUserId, 'appointment.scheduled', ['resource_code' => 'appointment#' . $id, 'appointment_at' => $scheduledAtUtc, 'appointment_status' => 'scheduled', 'destination' => 'clinic']);
            }

            $row = $this->db->table('clinic_appointments')->where('clinic_appointments.tenant_id', CurrentTenant::id())->where('id', $id)->get()->getRowArray();
            return AppointmentDto::fromRow($this->decorate([$row])[0])->withQrToken($plain);
        });
    }

    /**
     * Issue (or re-issue) an appointment's QR proof-of-booking token.
     *
     * Only the plaintext is returned to the caller; the DB stores the HMAC
     * hash. Allowed for staff with `appointmentsWrite` OR the owning patient
     * viewing their own appointment (self-service). Re-issuing rotates the
     * token (the old QR stops verifying), mirroring referral QR behaviour.
     */
    public function issueQr(int $id): string
    {
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $userId): string {
            $row = $this->db->table('clinic_appointments')
                ->where('clinic_appointments.tenant_id', CurrentTenant::id())
                ->select('id, patient_user_id')
                ->where('id', $id)
                ->where('archived_at', null)
                ->get()->getRowArray();

            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Appointment #{$id} not found."],
                ]);
            }

            $isStaff = true;
            try {
                $this->policy->check('appointmentsWrite');
            } catch (\Throwable) {
                $isStaff = false;
            }
            $isOwner = (int) ($row['patient_user_id'] ?? 0) === $userId;
            if (! $isStaff && ! $isOwner) {
                throw new ApiException('rbac.denied', 403, [
                    ['code' => 'rbac.denied', 'message' => 'Not allowed to view this appointment QR.'],
                ]);
            }

            [$plain, $hash] = $this->newQrToken();
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('clinic_appointments')
                ->where('clinic_appointments.tenant_id', CurrentTenant::id())
                ->where('id', $id)
                ->update(['qr_token_hash' => $hash, 'updated_at' => $now]);

            $this->audit->enqueue('clinic.appointment_qr_issued', 'clinic_appointments', $id, $userId, []);

            return $plain;
        });
    }

    /**
     * MINIMUM-DISCLOSURE verify — PUBLIC endpoint. Returns ONLY
     * { valid, status, scheduled_at }; NEVER returns PII.
     */
    public function verify(string $plainToken): array
    {
        $hash = $this->hashToken($plainToken);

        $row = $this->db->table('clinic_appointments')
            ->where('clinic_appointments.tenant_id', CurrentTenant::id())
            ->select('id, status, scheduled_at')
            ->where('qr_token_hash', $hash)
            ->where('archived_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            return ['valid' => false, 'status' => null, 'scheduled_at' => null];
        }

        return [
            'valid'        => true,
            'status'       => (string) $row['status'],
            'scheduled_at' => (string) $row['scheduled_at'],
        ];
    }

    /**
     * Mint a 128-bit CSPRNG token (base64url) + its HMAC-SHA256 hash.
     *
     * @return array{0: string, 1: string} [plain, hash]
     */
    private function newQrToken(): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        return [$plain, $this->hashToken($plain)];
    }

    private function hashToken(string $plain): string
    {
        $key = (string) (getenv('APPOINTMENT_HMAC_KEY') ?: getenv('REFERRAL_HMAC_KEY') ?: '');
        if ($key === '') {
            throw new \RuntimeException('APPOINTMENT_HMAC_KEY (or REFERRAL_HMAC_KEY) is not configured.');
        }
        return hash_hmac('sha256', $plain, $key);
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
            ->where('a.tenant_id', CurrentTenant::id())
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
            // Tenant-scope the staff roster: without this, a student's
            // booking picker listed providers of every tenant (2026-09 audit).
            ->where('u.tenant_id', CurrentTenant::id())
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
    private function assertNoClash(string $column, int $userId, string $scheduledAtUtc, string $message, ?int $excludeId = null): void
    {
        $builder = $this->db->table('clinic_appointments')
            ->where('clinic_appointments.tenant_id', CurrentTenant::id())
            ->where($column, $userId)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where('ABS(TIMESTAMPDIFF(SECOND, scheduled_at, ' . $this->db->escape($scheduledAtUtc) . ')) <', 3600)
            ->where('archived_at', null);
        if ($excludeId !== null) {
            $builder->where('clinic_appointments.id !=', $excludeId);
        }
        $clash = $builder->countAllResults();
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
            $row = $this->selectForUpdate('clinic_appointments', ['tenant_id' => CurrentTenant::id(), 'id' => $appointmentId, 'archived_at' => null]);

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
                ->where('clinic_appointments.tenant_id', CurrentTenant::id())
                ->where('id', $appointmentId)
                ->update(['status' => $nextStatus, 'updated_at' => $now]);

            // Panel revision: checking in creates the day's ENCOUNTER
            // (the anchor for all clinic actions) and queues it — same
            // transaction, same discipline as the kiosk walk-in flow.
            if ($nextStatus === 'checked_in') {
                $this->openEncounterForAppointment($row, $userId, $now);
            }
            if (in_array($nextStatus, ['cancelled', 'no_show'], true)) {
                $encounter = $this->db->table('clinic_encounters')->where('clinic_encounters.tenant_id', CurrentTenant::id())->select('id')->where('appointment_id', $appointmentId)->get()->getRowArray();
                if ($encounter !== null) {
                    $this->db->table('clinic_queue_entries')->where('clinic_queue_entries.tenant_id', CurrentTenant::id())->where('encounter_id', (int) $encounter['id'])->whereIn('status', ['waiting','called'])->update(['status'=>'skipped','finished_at'=>$now,'updated_at'=>$now]);
                }
            }

            $this->audit->enqueue(
                'clinic.appointment_' . strtolower($nextStatus),
                'clinic_appointments',
                $appointmentId,
                $userId,
                ['previous_status' => (string) $row['status'], 'next_status' => $nextStatus],
            );
            foreach (array_unique(array_filter([(int) ($row['patient_user_id'] ?? 0), (int) $row['provider_user_id']])) as $recipient) {
                $this->notify->enqueue($recipient, 'appointment.' . $nextStatus, ['resource_code'=>'appointment#'.$appointmentId,'appointment_at'=>(string)$row['scheduled_at'],'appointment_status'=>$nextStatus,'destination'=>'clinic']);
            }

            $fresh = $this->db->table('clinic_appointments')->where('clinic_appointments.tenant_id', CurrentTenant::id())->where('id', $appointmentId)->get()->getRowArray();
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
            ->where('clinic_encounters.tenant_id', CurrentTenant::id())
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
            'tenant_id'         => CurrentTenant::id(),
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
        // The patient is standing at the desk NOW, so the queue row
        // must land on today's Manila queue — not the appointment's
        // original day (2026-09 audit: checking in a stale appointment
        // filed the row under a past queue_date, invisible to staff).
        $queueDate = ManilaDay::today();
        $last = $this->db->query(
            'SELECT `position` FROM `clinic_queue_entries` WHERE `tenant_id` = ? AND `queue_date` = ? ORDER BY `position` DESC LIMIT 1 FOR UPDATE',
            [CurrentTenant::id(), $queueDate],
        )->getRowArray();
        $position = ($last !== null ? (int) $last['position'] : 0) + 1;
        $this->db->table('clinic_queue_entries')->insert([
            'tenant_id'    => CurrentTenant::id(),
            'encounter_id' => $encounterId,
            'queue_date'   => $queueDate,
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
            ->where('clinic_appointments.tenant_id', CurrentTenant::id())
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
            $row = $this->selectForUpdate('clinic_appointments', ['tenant_id' => CurrentTenant::id(), 'id' => $appointmentId, 'archived_at' => null]);
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

            // Re-check the ±60-minute clash discipline when the slot,
            // provider or patient changed — excluding this row itself.
            if (in_array('scheduled_at', $changed, true) || in_array('provider_user_id', $changed, true) || in_array('patient_user_id', $update, true)) {
                $slot    = (string) ($update['scheduled_at'] ?? $row['scheduled_at']);
                $provId  = (int) ($update['provider_user_id'] ?? $row['provider_user_id']);
                $patId   = array_key_exists('patient_user_id', $update)
                    ? ($update['patient_user_id'] !== null ? (int) $update['patient_user_id'] : null)
                    : ($row['patient_user_id'] !== null ? (int) $row['patient_user_id'] : null);
                $this->assertNoClash('provider_user_id', $provId, $slot, 'The provider already has an appointment within an hour of this slot.', $appointmentId);
                if ($patId !== null) {
                    $this->assertNoClash('patient_user_id', $patId, $slot, 'The patient already has an appointment within an hour of this slot.', $appointmentId);
                }
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $update['updated_at'] = $now;

            $this->db->table('clinic_appointments')
                ->where('clinic_appointments.tenant_id', CurrentTenant::id())
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
                $slot = (string) ($update['scheduled_at'] ?? $row['scheduled_at']);
                $this->notify->enqueue(
                    (int) ($update['provider_user_id'] ?? $row['provider_user_id']),
                    'appointment.rescheduled',
                    [
                        'resource_code' => 'appointment#' . $appointmentId,
                        'scheduled_at' => $slot, 'appointment_at' => $slot,
                        'appointment_status' => 'scheduled', 'destination' => 'clinic',
                    ],
                );
                $patientId = (int) ($update['patient_user_id'] ?? $row['patient_user_id'] ?? 0);
                if ($patientId > 0) {
                    $this->notify->enqueue($patientId, 'appointment.rescheduled', ['resource_code'=>'appointment#'.$appointmentId,'appointment_at'=>$slot,'appointment_status'=>'scheduled','destination'=>'clinic']);
                }
            }

            $fresh = $this->db->table('clinic_appointments')->where('clinic_appointments.tenant_id', CurrentTenant::id())->where('id', $appointmentId)->get()->getRowArray();
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
        $dueAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+15 minutes')->format('Y-m-d H:i:s');
        $localStartUtc = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $ids = $this->db->table('clinic_appointments')
            ->where('clinic_appointments.tenant_id', CurrentTenant::id())
            ->select('id')
            ->where('status', 'scheduled')
            ->where('archived_at', null)
            ->where('scheduled_at >=', $localStartUtc)
            ->where('scheduled_at <=', $dueAt)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $advanced = 0;
        foreach ($ids as $r) {
            $id = (int) $r['id'];
            try {
                $advanced += $this->txn(function () use ($id, $now): int {
                    $row = $this->selectForUpdate('clinic_appointments', [
                        'tenant_id'   => CurrentTenant::id(),
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
                        ->where('clinic_appointments.tenant_id', CurrentTenant::id())
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

    /**
     * No-show aging sweep (2026-09 audit): every `scheduled`
     * appointment whose slot lies BEFORE today's Manila business day
     * can never be honoured — transition it to `no_show` so the staff
     * list stops accumulating ghost "Scheduled" rows and the Counselling
     * three-strike counter analogue has an input signal. Idempotent and
     * race-safe: each row is re-locked and status-re-checked inside its
     * transaction. Run from `synapse:appointments-enqueue-due`.
     */
    public function agePastDueNoShows(): int
    {
        $now = $this->utcNow();
        $localStartUtc = ManilaDay::startOfDayUtcSql();

        $ids = $this->db->table('clinic_appointments')
            ->where('clinic_appointments.tenant_id', CurrentTenant::id())
            ->select('id')
            ->where('status', 'scheduled')
            ->where('archived_at', null)
            ->where('scheduled_at <', $localStartUtc)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $aged = 0;
        foreach ($ids as $r) {
            $id = (int) $r['id'];
            try {
                $aged += $this->txn(function () use ($id, $now): int {
                    $row = $this->selectForUpdate('clinic_appointments', [
                        'tenant_id'   => CurrentTenant::id(),
                        'id'          => $id,
                        'archived_at' => null,
                    ]);
                    if ($row === null || (string) $row['status'] !== 'scheduled') {
                        return 0; // lost the race
                    }

                    $this->db->table('clinic_appointments')
                        ->where('clinic_appointments.tenant_id', CurrentTenant::id())
                        ->where('id', $id)
                        ->update(['status' => 'no_show', 'updated_at' => $now]);

                    // No queue-entry cascade is needed here: queue rows
                    // hang off `encounter_id`, and an appointment that
                    // aged out never opened an encounter.

                    $providerId = (int) ($row['provider_user_id'] ?? 0);
                    $this->audit->enqueue(
                        'clinic.appointment_no_show',
                        'clinic_appointments',
                        $id,
                        $providerId,
                        [
                            'previous_status' => 'scheduled',
                            'next_status'     => 'no_show',
                            'source'          => 'aging_sweep',
                        ],
                    );
                    $this->notify->enqueue(
                        $providerId,
                        'appointment.no_show',
                        ['resource_code' => 'appointment#' . $id, 'appointment_status' => 'no_show', 'destination' => 'clinic'],
                    );
                    $patientId = (int) ($row['patient_user_id'] ?? 0);
                    if ($patientId > 0) {
                        $this->notify->enqueue($patientId, 'appointment.no_show', ['resource_code' => 'appointment#' . $id, 'appointment_status' => 'no_show', 'destination' => 'clinic']);
                    }

                    return 1;
                });
            } catch (Throwable $t) {
                log_message('warning', sprintf(
                    'AppointmentService::agePastDueNoShows: id=%d skipped (%s)',
                    $id,
                    $t->getMessage(),
                ));
            }
        }
        return $aged;
    }
}
