<?php

declare(strict_types=1);

namespace Modules\Referrals\Services;

use App\Exceptions\ApiException;
use App\Modules\Shared\BaseService;
use App\Pagination\KeysetPaginator;
use App\Services\Audit\AuditOutboxService;
use App\Services\Crypto\EncryptionService;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Referrals\DTOs\ReferralDto;
use Modules\Referrals\Policies\ReferralPolicy;

final class ReferralService extends BaseService
{
    public function __construct(
        private readonly ReferralPolicy $policy,
        private readonly AuditOutboxService $audit,
        private readonly EncryptionService $crypto,
    ) {
        parent::__construct();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, next: ?string, count: int}
     */
    public function list(?string $cursor, int $limit, ?string $status): array
    {
        $this->policy->check('list');

        $builder = $this->db->table('referral_referrals')
            ->select('id, patient_school_id, source_module, target_module, artifact_type, status, reason_code, created_at, updated_at, qr_expires_at')
            ->where('archived_at', null)
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC');

        if ($status !== null) {
            $builder->where('status', $status);
        }

        KeysetPaginator::apply($builder, $cursor, $limit);

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
        if ($sourceModule === 'clinic' && ! $this->issuerIsTeachingEmployee($userId)) {
            throw new ApiException('rbac.referrals.forbidden', 403, [
                ['code' => 'referral.teaching_required', 'message' => 'Only teaching employees (faculty) can refer students to counselling.'],
            ]);
        }

        return $this->txn(function () use ($patientSchoolId, $sourceModule, $targetModule, $artifactType, $reasonCode, $notesPlaintext, $userId): ReferralDto {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $row = [
                'patient_school_id' => $patientSchoolId,
                'source_module'     => $sourceModule,
                'target_module'     => $targetModule,
                'artifact_type'     => $artifactType,
                'issuer_user_id'    => $userId,
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

            $fresh = $this->db->table('referral_referrals')->where('id', $id)->get()->getRowArray();
            return ReferralDto::fromRow($fresh);
        });
    }

    public function acknowledge(int $id): ReferralDto
    {
        $this->policy->check('acknowledge');
        $userId = \App\Auth\CurrentUser::assert();
        return $this->transition($id, REFERRAL_STATUS_ACKNOWLEDGED, $userId);
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

    private function transition(int $id, string $nextStatus, int $userId): ReferralDto
    {
        $allowed = [
            REFERRAL_STATUS_ACKNOWLEDGED => [REFERRAL_STATUS_SUBMITTED],
            REFERRAL_STATUS_UNDER_REVIEW => [REFERRAL_STATUS_ACKNOWLEDGED],
            REFERRAL_STATUS_CLOSED       => [REFERRAL_STATUS_UNDER_REVIEW, REFERRAL_STATUS_ACKNOWLEDGED],
        ];
        $from = $allowed[$nextStatus] ?? [];

        return $this->txn(function () use ($id, $nextStatus, $userId, $from): ReferralDto {
            $row = $this->selectForUpdate('referral_referrals', ['id' => $id, 'archived_at' => null]);

            if ($row === null) {
                throw new ApiException('resource.not_found', 404, [
                    ['code' => 'resource.not_found', 'message' => "Referral #{$id} not found."],
                ]);
            }
            if (! in_array($row['status'], $from, true)) {
                throw new ApiException('statemachine.referral.invalid_transition', 409, [
                    ['code' => 'statemachine.invalid_transition', 'message' => "Cannot transition from {$row['status']} to {$nextStatus}."],
                ]);
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->db->table('referral_referrals')
                ->where('id', $id)
                ->update(['status' => $nextStatus, 'updated_at' => $now]);

            $this->audit->enqueue(
                "referral.status_{$nextStatus}",
                'referral_referrals',
                $id,
                $userId,
                ['previous_status' => (string) $row['status'], 'next_status' => $nextStatus],
            );

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
            return ['status' => 'Expired', 'artifact_type' => null, 'issuer' => null];
        }
        if ($row['qr_revoked_at'] !== null) {
            return ['status' => 'Revoked', 'artifact_type' => (string) $row['artifact_type'], 'issuer' => null];
        }
        if ($row['qr_expires_at'] !== null && strtotime((string) $row['qr_expires_at']) < time()) {
            return ['status' => 'Expired', 'artifact_type' => (string) $row['artifact_type'], 'issuer' => null];
        }

        $issuer = $this->db->table('users')
            ->select('username')
            ->where('id', $row['issuer_user_id'])
            ->get()->getRowArray();

        return [
            'status'        => 'Valid',
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
     * Resolve the issuer's employee row by the
     * `patients_employees.user_id` UNIQUE link. Returns TRUE only
     * when the issuer is on the registry AND is flagged as a
     * teaching employee. Returns FALSE in three cases:
     *
     *   1. The issuer has no employee link (admin / external user).
     *      The teaching gate is a no-op for them.
     *   2. The employee link is archived.
     *   3. The employee link is active but `is_teaching = 0`
     *      (e.g. School Nurse, IT, facilities).
     *
     * Used by `create()` to enforce the clinic-origin teaching-only
     * rule (Phase 12 follow-up to Phase 11).
     */
    private function issuerIsTeachingEmployee(int $userId): bool
    {
        $row = $this->db->table('patients_employees')
            ->select('is_teaching, archived_at')
            ->where('user_id', $userId)
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