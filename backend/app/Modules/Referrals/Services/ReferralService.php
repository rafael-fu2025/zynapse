<?php

declare(strict_types=1);

namespace Modules\Referrals\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use App\Services\Crypto\EncryptionService;
use App\Services\Notify\NotificationOutboxService;
use App\Services\CurrentTenant;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Referrals\DTOs\ReferralDto;
use Modules\Referrals\Policies\ReferralPolicy;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\AppointmentService;
use Modules\Clinic\Services\ClinicService;
use Modules\Clinic\Services\QueueService as ClinicQueueService;
use Modules\Counselling\Policies\CounsellingPolicy;
use Modules\Counselling\Services\QueueService as CounsellingQueueService;

final class ReferralService extends BaseService
{
    public function __construct(
        private readonly ReferralPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly EncryptionService $crypto,
        private readonly NotificationOutboxService $notify,
    ) {
        parent::__construct();
    }

    /**
     * Receiving-side permission codes that should see a new referral.
     * Mirrors ReferralPolicy::checkReceivingSide.
     *
     * @return array<int, string>
     */
    private function targetSidePermissions(string $targetModule): array
    {
        return $targetModule === 'counselling'
            ? ['counselling.records.read', 'counselling.schedule.read']
            : ['clinic.encounters.read'];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function list(?string $cursor, int $limit, ?string $status): array
    {
        $this->policy->check('list');

        $builder = $this->db->table('referral_referrals AS r')
            ->select('r.id, r.patient_school_id, r.source_encounter_id, r.source_session_id, r.source_module, r.target_module, r.artifact_type, r.status, r.reason_code, r.provider_user_id, r.queue_handoff_destination, r.queue_handoff_entry_id, r.queue_handoff_at, r.created_at, r.updated_at, r.qr_expires_at, r.qr_revoked_at, u.username AS provider_name')
            ->join('users AS u', 'u.id = r.provider_user_id', 'left')
            ->where('r.archived_at', null);

        // Side-aware board scoping (2026-08-05): a bridge handler sees
        // referrals TARGETING their module (clinic staff →
        // counselling→clinic; a counsellor → clinic→counselling) PLUS
        // referrals they issued, so outgoing referrals stay visible.
        // Users serving both sides (admin wildcard) see the full board.
        // Non-handler referrers (faculty) see only what they issued.
        $me    = \App\Auth\CurrentUser::assert();
        $sides = $this->policy->servingSides();
        if ($sides !== []) {
            $builder->groupStart()
                ->whereIn('r.target_module', $sides)
                ->orWhere('r.issuer_user_id', $me)
                ->groupEnd();
        } else {
            $builder->where('r.issuer_user_id', $me);
        }

        $builder->orderBy('r.created_at', 'DESC')
            ->orderBy('r.id', 'DESC');

        if ($status !== null) {
            $builder->where('r.status', $status);
        }

        KeysetPaginator::apply($builder, $cursor, $limit, 'r.created_at', 'r.id');

        $rows = $builder->get()->getResultArray();
        $final = KeysetPaginator::finalize($rows, $limit);

        return [
            'data'  => array_map(static fn (array $r) => ReferralDto::fromRow($r)->toArray(), $final['rows']),
            'next'  => $final['nextCursor'],
            'count' => $limit,
        ];
    }

    public function create(
        string $patientSchoolId,
        string $sourceModule,
        string $targetModule,
        string $artifactType,
        ?string $reasonCode,
        ?string $notesPlaintext,
        ?int $providerUserId = null,
        ?int $sourceEncounterId = null,
        ?int $sourceSessionId = null,
        bool $clinicalStaffContext = false,
    ): ReferralDto {
        $this->policy->check('create');
        $userId = \App\Auth\CurrentUser::assert();

        if (! in_array($sourceModule, ['clinic', 'counselling'], true)
            || ! in_array($targetModule, ['clinic', 'counselling'], true)
            || $sourceModule === $targetModule) {
            throw new ApiException('validation.invalid', 422, [
                ['code' => 'validation.invalid', 'message' => 'source_module and target_module must differ and be one of clinic|counselling.'],
            ]);
        }

        // Phase 12 (extension of Phase 11): teaching-only referral gate.
        //
        // When the referral is clinic-originated AND the issuer is on
        // the employee registry, the issuer must be a teaching employee
        // (`is_teaching = 1`). The check is intentionally a NEGATIVE
        // gate: an issuer who is NOT on the employee registry (e.g. an
        // admin user without a `patients_employees` link) is NOT
        // affected by this rule — the existing `referrals.create`
        // permission is what governs them. Only clinic staff who are
        // ALSO listed as employees (and whose type is non-teaching —
        // e.g. School Nurse, IT, facilities) are blocked.
        //
        // We resolve the issuer's employee record by the
        // `patients_employees.user_id` UNIQUE link (Phase 11). If the
        // link is NULL, the issuer has no employee record and the gate
        // is a no-op.
        if (! $clinicalStaffContext && $sourceModule === 'clinic' && ! $this->issuerIsTeachingEmployee($userId)) {
            throw new ApiException('rbac.referrals.forbidden', 403, [
                ['code' => 'referral.teaching_required', 'message' => 'Only teaching employees (faculty) can refer students to counselling.'],
            ]);
        }

        return $this->txn(function () use ($patientSchoolId, $sourceModule, $targetModule, $artifactType, $reasonCode, $notesPlaintext, $providerUserId, $sourceEncounterId, $sourceSessionId, $clinicalStaffContext, $userId): ReferralDto {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $contextPatient = $this->lockAndAuthorizeSourceContext(
                $sourceModule,
                $sourceEncounterId,
                $sourceSessionId,
                $clinicalStaffContext,
            );
            if ($contextPatient !== null && $contextPatient !== $patientSchoolId) {
                throw new ApiException('referral.patient_context_mismatch', 409, [[
                    'code' => 'referral.patient_context_mismatch',
                    'message' => 'The referral patient does not match the active clinical session.',
                ]]);
            }
            $patientUserId = $this->resolvePatientUserId($patientSchoolId);
            if ($patientUserId === null) {
                throw new ApiException('referral.patient_not_found', 422, [[
                    'code' => 'referral.patient_not_found',
                    'message' => 'The selected patient is no longer available. Search and select the patient again.',
                    'field' => 'patient_school_id',
                ]]);
            }

            // Lock the stable patient row before checking so two concurrent
            // requests for the same direction cannot both pass the lookup.
            $this->db->query('SELECT `id` FROM `users` WHERE `id` = ? FOR UPDATE', [$patientUserId]);
            $existing = $this->db->table('referral_referrals')
                ->where('tenant_id', CurrentTenant::id())
                ->where('patient_user_id', $patientUserId)
                ->where('source_module', $sourceModule)
                ->where('target_module', $targetModule)
                ->where('status !=', REFERRAL_STATUS_CLOSED)
                ->where('archived_at', null)
                ->orderBy('id', 'DESC')
                ->get()->getRowArray();
            if ($existing !== null) {
                $referral = ReferralDto::fromRow($existing)->toArray();
                throw new ApiException('referral.active_duplicate', 409, [[
                    'code' => 'referral.active_duplicate',
                    'message' => sprintf('Referral #%d is already %s for this patient.', $referral['id'], str_replace('_', ' ', $referral['status'])),
                    'details' => ['referral' => $referral],
                ]]);
            }

            $row = [
                'tenant_id'         => CurrentTenant::id(),
                'patient_user_id'   => $patientUserId,
                'patient_school_id' => $patientSchoolId,
                'source_encounter_id' => $sourceEncounterId,
                'source_session_id' => $sourceSessionId,
                'source_module'     => $sourceModule,
                'target_module'     => $targetModule,
                'artifact_type'     => $artifactType,
                'issuer_user_id'    => $userId,
                'provider_user_id'  => $this->resolveProviderId($providerUserId),
                'status'            => REFERRAL_STATUS_SUBMITTED,
                'reason_code'       => $reasonCode,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];

            if ($notesPlaintext !== null && $notesPlaintext !== '') {
                $env = $this->crypto->encryptField($notesPlaintext);
                $row['notes_cipher']      = $env['ciphertext'];
                $row['notes_nonce']       = $env['nonce'];
                $row['notes_key_version'] = $env['key_version'];
            }

            $this->db->table('referral_referrals')->insert($row);
            $id = (int) $this->db->insertID();

            $this->audit->enqueue(
                'referral.created',
                'referral_referrals',
                $id,
                $userId,
                ['next_status' => REFERRAL_STATUS_SUBMITTED],
            );

            // Notify the receiving side so staff pick the referral up
            // promptly (permission-driven fan-out, same transaction).
            $this->notify->enqueueToPermissions(
                $this->targetSidePermissions($targetModule),
                'referral.created',
                [
                    'resource_code' => 'referral#' . $id,
                    'next_status'   => REFERRAL_STATUS_SUBMITTED,
                    'source_module' => $sourceModule,
                    'target_module' => $targetModule,
                ],
            );

            $fresh = $this->db->table('referral_referrals')->where('id', $id)->get()->getRowArray();
            return ReferralDto::fromRow($fresh);
        });
    }

    /**
     * Minimal patient lookup for the referral form (audit fix): the kiosk
     * lookup requires `clinic.patients.read`, which teaching employees —
     * the primary referrers — do NOT have. This is a narrow, referrals-
     * scoped search (same query, no PII beyond id/name/school_id) gated
     * by `referrals.create`.
     *
     * @return array<int, array{id: int, kind: string, name: string, school_id: string}>
     */
    public function lookupPatient(string $q, int $limit = 8): array
    {
        $this->policy->check('create');
        $limit = max(1, min($limit, 12));
        $qTrim = trim($q);
        if ($qTrim === '') {
            return [];
        }

        $rows = $this->db->table('users')
            ->select('id, kind, first_name, last_name, middle_name, student_number, employee_number')
            ->whereIn('kind', ['student', 'employee'])
            ->where('archived_at', null)
            ->groupStart()
                ->like('student_number', $qTrim)
                ->orLike('employee_number', $qTrim)
                ->orLike('last_name', $qTrim)
                ->orLike('first_name', $qTrim)
            ->groupEnd()
            ->orderBy('last_name', 'ASC')
            ->orderBy('first_name', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $schoolId = (string) ($r['kind'] === 'student' ? $r['student_number'] : $r['employee_number']);
            $middle   = $r['middle_name'] !== null && $r['middle_name'] !== ''
                ? ' ' . mb_substr((string) $r['middle_name'], 0, 1) . '.'
                : '';
            $out[] = [
                'id'        => (int) $r['id'],
                'kind'      => (string) $r['kind'],
                'name'      => trim((string) $r['last_name'] . ', ' . (string) $r['first_name'] . $middle),
                'school_id' => $schoolId,
            ];
        }

        return $out;
    }

    public function acknowledge(int $id, ?int $providerUserId = null): ReferralDto
    {
        $this->policy->check('acknowledge');
        $userId = \App\Auth\CurrentUser::assert();
        // Panel revision: acknowledging assigns the handling PROVIDER
        // (nurse / counsellor). Default: the acknowledging user.
        return $this->transition($id, REFERRAL_STATUS_ACKNOWLEDGED, $userId, $providerUserId ?? $userId);
    }

    public function review(int $id): ReferralDto
    {
        $this->policy->check('review');
        $userId = \App\Auth\CurrentUser::assert();
        return $this->transition($id, REFERRAL_STATUS_UNDER_REVIEW, $userId);
    }

    public function close(int $id): ReferralDto
    {
        $this->policy->check('close');
        $userId = \App\Auth\CurrentUser::assert();
        return $this->transition($id, REFERRAL_STATUS_CLOSED, $userId);
    }

    /**
     * Explicit receiving-side handoff into the target module's independent
     * queue. Referral state is not changed and repeated calls return the same
     * logical queue association.
     *
     * @return array{referral:array<string,mixed>,queue:array<string,mixed>}
     */
    public function handoffToReceivingQueue(int $id): array
    {
        $this->policy->check('handoff');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $userId): array {
            $referral = $this->selectForUpdate('referral_referrals', ['id' => $id, 'archived_at' => null]);
            if ($referral === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Referral #{$id} not found."],
                ]);
            }
            $target = (string) $referral['target_module'];
            $this->policy->checkReceivingSide($target);
            if ((string) $referral['status'] === REFERRAL_STATUS_CLOSED) {
                throw new ApiException('statemachine.referral.closed', 409, [
                    ['code' => 'statemachine.referral.closed', 'message' => 'A closed referral cannot be sent to a queue.'],
                ]);
            }
            if (! in_array((string) $referral['status'], [REFERRAL_STATUS_ACKNOWLEDGED, REFERRAL_STATUS_UNDER_REVIEW], true)) {
                throw new ApiException('statemachine.referral.not_accepted', 409, [
                    ['code' => 'statemachine.referral.not_accepted', 'message' => 'A referral must be acknowledged before queue handoff.'],
                ]);
            }

            $patientSchoolId = (string) $referral['patient_school_id'];
            $patientUserId = isset($referral['patient_user_id']) && $referral['patient_user_id'] !== null
                ? (int) $referral['patient_user_id']
                : ($this->resolvePatientUserId($patientSchoolId) ?? 0);
            if ($patientUserId <= 0) {
                throw ApiException::validationFailure([
                    ['code' => 'validation.field', 'message' => 'Queue handoff requires a registered patient.', 'field' => 'patient_school_id'],
                ]);
            }

            if ($target === 'counselling') {
                $queueService = new CounsellingQueueService(
                    new CounsellingPolicy(),
                    $this->audit,
                    $this->notify,
                );
                $queue = ($referral['queue_handoff_entry_id'] ?? null) !== null
                    ? $queueService->getForHandoff((int) $referral['queue_handoff_entry_id'])
                    : $queueService->enqueueFromReferral($patientUserId, $patientSchoolId, $id);
            } else {
                $clinicPolicy = new ClinicPolicy();
                $queueService = new ClinicQueueService(
                    $clinicPolicy,
                    $this->audit,
                    new AppointmentService($clinicPolicy, $this->audit, $this->notify),
                    new ClinicService($clinicPolicy, $this->audit, $this->notify),
                    $this->notify,
                );
                $queue = ($referral['queue_handoff_entry_id'] ?? null) !== null
                    ? $queueService->getForHandoff((int) $referral['queue_handoff_entry_id'])
                    : $queueService->enqueueFromReferral($patientUserId, $patientSchoolId, $id);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            if (($referral['queue_handoff_entry_id'] ?? null) === null) {
                $this->db->table('referral_referrals')->where('id', $id)->update([
                    'queue_handoff_destination' => $target,
                    'queue_handoff_entry_id' => (int) $queue['id'],
                    'queue_handoff_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->audit->enqueue('referral.queue_handoff', 'referral_referrals', $id, $userId, [
                    'destination' => $target,
                    'queue_entry_id' => (int) $queue['id'],
                    // Source queue ownership remains unchanged. Receiving
                    // staff never gain permission to complete the source row.
                    'source_queue_disposition' => 'unchanged',
                ]);
                $this->notify->enqueueToPermissions(
                    $target === 'counselling' ? ['counselling.queue.read'] : ['clinic.queue.read'],
                    'referral.queue_handoff',
                    ['resource_code' => 'referral#' . $id, 'destination' => $target],
                );
            }

            $fresh = $this->db->table('referral_referrals')->where('id', $id)->get()->getRowArray();
            return ['referral' => ReferralDto::fromRow($fresh)->toArray(), 'queue' => $queue];
        });
    }

    public function createFromEncounter(int $encounterId, ?string $reasonCode, ?string $notesPlaintext): ReferralDto
    {
        $row = $this->db->table('clinic_encounters')->select('patient_school_id')
            ->where('id', $encounterId)->where('archived_at', null)->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [[
                'code' => 'resource.not_found', 'message' => "Encounter #{$encounterId} not found.",
            ]]);
        }
        return $this->create((string) $row['patient_school_id'], 'clinic', 'counselling', 'intake_pass', $reasonCode, $notesPlaintext, null, $encounterId, null, true);
    }

    public function createFromSession(int $sessionId, ?string $reasonCode, ?string $notesPlaintext): ReferralDto
    {
        $row = $this->db->table('counselling_sessions')->select('patient_school_id')
            ->where('id', $sessionId)->where('archived_at', null)->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [[
                'code' => 'resource.not_found', 'message' => "Session #{$sessionId} not found.",
            ]]);
        }
        return $this->create((string) $row['patient_school_id'], 'counselling', 'clinic', 'referral_letter', $reasonCode, $notesPlaintext, null, null, $sessionId, true);
    }

    private function lockAndAuthorizeSourceContext(
        string $sourceModule,
        ?int $sourceEncounterId,
        ?int $sourceSessionId,
        bool $clinicalStaffContext,
    ): ?string {
        if (! $clinicalStaffContext) {
            if ($sourceEncounterId !== null || $sourceSessionId !== null) {
                throw new ApiException('referral.context_forbidden', 403, [[
                    'code' => 'referral.context_forbidden', 'message' => 'Clinical source context may only be assigned by its workflow endpoint.',
                ]]);
            }
            return null;
        }
        if ($sourceModule === 'clinic' && $sourceEncounterId !== null && $sourceSessionId === null) {
            $record = $this->selectForUpdate('clinic_encounters', ['id' => $sourceEncounterId, 'archived_at' => null]);
            if ($record === null) throw new ApiException('resource.not_found', 404, [['code' => 'resource.not_found', 'message' => "Encounter #{$sourceEncounterId} not found."]]);
            (new ClinicPolicy())->check('refer', $record);
            if ((string) $record['status'] !== 'open') throw new ApiException('statemachine.clinic.encounter_closed', 409, [['code' => 'statemachine.clinic.encounter_closed', 'message' => 'Only an open Clinic encounter can create a referral.']]);
            return (string) $record['patient_school_id'];
        }
        if ($sourceModule === 'counselling' && $sourceSessionId !== null && $sourceEncounterId === null) {
            $record = $this->selectForUpdate('counselling_sessions', ['id' => $sourceSessionId, 'archived_at' => null]);
            if ($record === null) throw new ApiException('resource.not_found', 404, [['code' => 'resource.not_found', 'message' => "Session #{$sourceSessionId} not found."]]);
            (new CounsellingPolicy())->check('refer', $record);
            if ($record['ended_at'] !== null) throw new ApiException('statemachine.counselling.session_closed', 409, [['code' => 'statemachine.counselling.session_closed', 'message' => 'Only an active Guidance session can create a referral.']]);
            return (string) $record['patient_school_id'];
        }
        throw new ApiException('validation.invalid', 422, [[
            'code' => 'validation.invalid', 'message' => 'Referral source context does not match its module.',
        ]]);
    }

    private function transition(int $id, string $nextStatus, int $userId, ?int $providerUserId = null): ReferralDto
    {
        $allowed = [
            REFERRAL_STATUS_ACKNOWLEDGED => [REFERRAL_STATUS_SUBMITTED],
            REFERRAL_STATUS_UNDER_REVIEW => [REFERRAL_STATUS_ACKNOWLEDGED],
            REFERRAL_STATUS_CLOSED       => [REFERRAL_STATUS_UNDER_REVIEW, REFERRAL_STATUS_ACKNOWLEDGED],
        ];
        $from = $allowed[$nextStatus] ?? [];

        return $this->txn(function () use ($id, $nextStatus, $userId, $providerUserId, $from): ReferralDto {
            $row = $this->selectForUpdate('referral_referrals', ['id' => $id, 'archived_at' => null]);

            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Referral #{$id} not found."],
                ]);
            }

            // R6 (RBAC_SECURITY_REVIEW): only the TARGET module's staff may
            // acknowledge / review / close an incoming referral.
            $this->policy->checkReceivingSide((string) $row['target_module']);

            if (! in_array($row['status'], $from, true)) {
                throw new ApiException('statemachine.referral.invalid_transition', 409, [
                    ['code' => 'statemachine.invalid_transition', 'message' => "Cannot transition from {$row['status']} to {$nextStatus}."],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $update = ['status' => $nextStatus, 'updated_at' => $now];
            if ($providerUserId !== null) {
                $update['provider_user_id'] = $this->resolveProviderId($providerUserId);
            }
            $this->db->table('referral_referrals')
                ->where('id', $id)
                ->update($update);

            $this->audit->enqueue(
                "referral.status_{$nextStatus}",
                'referral_referrals',
                $id,
                $userId,
                ['previous_status' => (string) $row['status'], 'next_status' => $nextStatus],
            );

            // Progress notices to the ISSUER (teacher / referring party):
            // they care when their referral is picked up or closed.
            $issuerId = (int) ($row['issuer_user_id'] ?? 0);
            if ($issuerId > 0 && $issuerId !== $userId && in_array($nextStatus, [REFERRAL_STATUS_ACKNOWLEDGED, REFERRAL_STATUS_CLOSED], true)) {
                $this->notify->enqueue(
                    $issuerId,
                    'referral.' . $nextStatus,
                    [
                        'resource_code' => 'referral#' . $id,
                        'next_status'   => $nextStatus,
                        'target_module' => (string) $row['target_module'],
                    ],
                );
            }

            $fresh = $this->db->table('referral_referrals')->where('id', $id)->get()->getRowArray();
            return ReferralDto::fromRow($fresh);
        });
    }

    /**
     * Issues a QR token. The plaintext token is returned ONCE; only the
     * keyed HMAC hash is persisted.
     */
    public function issueQr(int $id, int $ttlSeconds): array
    {
        $this->policy->check('issueQr');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $ttlSeconds, $userId): array {
            $row = $this->selectForUpdate('referral_referrals', ['id' => $id, 'archived_at' => null]);

            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Referral #{$id} not found."],
                ]);
            }

            // 128-bit CSPRNG token, base64url-encoded.
            $plain = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
            $hash  = $this->hashToken($plain);

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $expires = $now->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s');

            $this->db->table('referral_referrals')
                ->where('id', $id)
                ->update([
                    'qr_token_hash' => $hash,
                    'qr_expires_at' => $expires,
                    'qr_revoked_at' => null,
                    'updated_at'    => $now->format('Y-m-d H:i:s'),
                ]);

            $this->audit->enqueue(
                'referral.qr_issued',
                'referral_referrals',
                $id,
                $userId,
                ['resource_code' => 'referral#' . $id],
            );

            return [
                'referral_id' => $id,
                'token'       => $plain,
                'expires_at'  => $expires,
                'artifact_type' => (string) $row['artifact_type'],
            ];
        });
    }

    /**
     * Revokes the currently issued QR token for a referral. A revoked
     * token reports `status=revoked` on the PUBLIC verify endpoint —
     * useful when a printed token was misplaced or a hand-off fell
     * through. Gated by the same `referrals.issue_qr` permission as
     * issuing; re-issuing later resets the revocation.
     */
    public function revokeQr(int $id): ReferralDto
    {
        $this->policy->check('issueQr');
        $userId = \App\Auth\CurrentUser::assert();

        return $this->txn(function () use ($id, $userId): ReferralDto {
            $row = $this->selectForUpdate('referral_referrals', ['id' => $id, 'archived_at' => null]);

            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Referral #{$id} not found."],
                ]);
            }
            if (($row['qr_token_hash'] ?? null) === null) {
                throw new ApiException('referral.qr_not_issued', 409, [
                    ['code' => 'referral.qr_not_issued', 'message' => 'No QR token has been issued for this referral.'],
                ]);
            }
            if (($row['qr_revoked_at'] ?? null) !== null) {
                throw new ApiException('referral.qr_already_revoked', 409, [
                    ['code' => 'referral.qr_already_revoked', 'message' => 'The QR token has already been revoked.'],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('referral_referrals')
                ->where('id', $id)
                ->update(['qr_revoked_at' => $now, 'updated_at' => $now]);

            $this->audit->enqueue(
                'referral.qr_revoked',
                'referral_referrals',
                $id,
                $userId,
                ['resource_code' => 'referral#' . $id],
            );

            $fresh = $this->db->table('referral_referrals')->where('id', $id)->get()->getRowArray();
            return ReferralDto::fromRow($fresh);
        });
    }

    /**
     * MINIMUM-DISCLOSURE verify endpoint.
     *
     * Returns ONLY { status, artifact_type, issuer }. NEVER returns PII.
     */
    public function verify(string $plainToken): array
    {
        $hash = $this->hashToken($plainToken);

        $row = $this->db->table('referral_referrals')
            ->select('id, artifact_type, issuer_user_id, qr_token_hash, qr_expires_at, qr_revoked_at')
            ->where('qr_token_hash', $hash)
            ->where('archived_at', null)
            ->get()->getRowArray();

        if ($row === null) {
            return ['status' => 'expired', 'artifact_type' => null, 'issuer' => null];
        }
        if ($row['qr_revoked_at'] !== null) {
            return ['status' => 'revoked', 'artifact_type' => (string) $row['artifact_type'], 'issuer' => null];
        }
        if ($row['qr_expires_at'] !== null && strtotime((string) $row['qr_expires_at']) < time()) {
            return ['status' => 'expired', 'artifact_type' => (string) $row['artifact_type'], 'issuer' => null];
        }

        $issuer = $this->db->table('users')
            ->select('username')
            ->where('id', $row['issuer_user_id'])
            ->get()->getRowArray();

        return [
            'status'        => 'valid',
            'artifact_type' => (string) $row['artifact_type'],
            'issuer'        => $issuer['username'] ?? null,
        ];
    }

    private function hashToken(string $plain): string
    {
        $key = (string) (getenv('REFERRAL_HMAC_KEY') ?: '');
        if ($key === '') {
            throw new \RuntimeException('REFERRAL_HMAC_KEY is not configured.');
        }
        return hash_hmac('sha256', $plain, $key);
    }

    /**
     * Validate a provider user id: NULL passes through; a non-null id
     * must reference an existing user. Providers are the handling
     * staff (nurse / counsellor) on the referral's receiving side.
     */
    private function resolveProviderId(?int $providerUserId): ?int
    {
        if ($providerUserId === null || $providerUserId <= 0) {
            return null;
        }
        $row = $this->db->table('users')->select('id')->where('id', $providerUserId)->get()->getRowArray();
        if ($row === null) {
            throw new ApiException('resource.not_found', 404, [
                ['code' => 'resource.not_found', 'message' => "Provider user #{$providerUserId} not found.", 'field' => 'provider_user_id'],
            ]);
        }
        return $providerUserId;
    }

    /**
     * Resolve a patient identifier (student/employee number) to a
     * consolidated `users.id`, or null when unknown.
     */
    private function resolvePatientUserId(string $identifier): ?int
    {
        if ($identifier === '') {
            return null;
        }
        [, $patient] = (new \Modules\Clinic\Services\PatientLookupService())->findByIdentifier($identifier);
        return $patient !== null ? (int) $patient['id'] : null;
    }

    /**
     * Resolve the issuer's employee profile directly from `users`
     * (identity-consolidated — the employee IS the user). Returns TRUE
     * only when the issuer is a non-archived employee flagged as
     * teaching. Returns FALSE in three cases:
     *
     *   1. The issuer has no employee profile (admin / external user).
     *      The teaching gate is a no-op for them.
     *   2. The employee profile is archived.
     *   3. The employee profile is active but `is_teaching = 0`
     *      (e.g. School Nurse, IT, facilities).
     *
     * Used by `create()` to enforce the clinic-origin teaching-only
     * rule (Phase 12 follow-up to Phase 11).
     */
    private function issuerIsTeachingEmployee(int $userId): bool
    {
        $row = $this->db->table('users')
            ->select('is_teaching, archived_at')
            ->where('id', $userId)
            ->where('kind', 'employee')
            ->get()->getRowArray();

        if ($row === null) {
            // Not on the employee registry — gate is a no-op.
            return true;
        }
        if ($row['archived_at'] !== null) {
            // Archived employees cannot refer. (Treat as non-teaching
            // so the gate rejects; the audit trail records the attempt.)
            return false;
        }
        return (int) $row['is_teaching'] === 1;
    }
}
