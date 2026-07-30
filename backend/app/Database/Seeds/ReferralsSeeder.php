<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Services\Crypto\EncryptionService;
use CodeIgniter\Database\Seeder;

/**
 * ReferralsSeeder — DEV/STAGING ONLY.
 *
 * Wipes + seeds `referral_referrals` so the cross-module queue has
 * a visible flow on first run:
 *
 *   - 1 Closed          (clinic -> counselling; full lifecycle)
 *   - 1 UnderReview     (counselling -> clinic; midway)
 *   - 1 Acknowledged    (clinic -> counselling; just acknowledged)
 *   - 1 Submitted       (counselling -> clinic; fresh)
 *   - 1 QR-issued Valid (clinic -> counselling; HMAC token stored)
 *
 * Notes are written through `EncryptionService::encryptField` so
 * `COUNSELLING_KEY` must be present in the env (same key the
 * counselling module uses — both modules share the same key_ref
 * `COUNSELLING_KEY`).
 *
 * Refuses to run in production. Idempotent.
 */
final class ReferralsSeeder extends Seeder
{
    public function run(): void
    {
        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            throw new \RuntimeException('ReferralsSeeder must never run in production.');
        }

        $clinicIssuerId = $this->findUserIdByGroup('clinic_staff');
        $counsellingIssuerId = $this->findUserIdByGroup('counsellor');
        if ($clinicIssuerId === null || $counsellingIssuerId === null) {
            throw new \RuntimeException('ReferralsSeeder: need at least one clinic_staff and one counsellor user. Run DevUserSeeder first.');
        }

        $studentIds = $this->collectStudentIds();
        if ($studentIds === []) {
            throw new \RuntimeException('ReferralsSeeder: no students found. Run PatientRegistrySeeder first.');
        }

        $this->wipe();
        $this->seedReferrals($clinicIssuerId, $counsellingIssuerId, $studentIds);

        fwrite(STDOUT, "ReferralsSeeder: 5 referrals inserted (1 Closed, 1 UnderReview, 1 Acknowledged, 1 Submitted, 1 QR-issued).\n");
    }

    private function findUserIdByGroup(string $groupName): ?int
    {
        $row = $this->db->table('auth_groups_users agu')
            ->select('agu.user_id')
            ->join('auth_groups g', 'g.id = agu.group_id', 'inner', false)
            ->where('g.name', $groupName)
            ->limit(1)
            ->get()->getRowArray();
        return $row !== null ? (int) $row['user_id'] : null;
    }

    /**
     * @return list<string>
     */
    private function collectStudentIds(): array
    {
        $rows = $this->db->table('patients_students')
            ->select('student_number')
            ->orderBy('id', 'ASC')
            ->limit(8)
            ->get()->getResultArray();
        return array_map(static fn (array $r) => (string) $r['student_number'], $rows);
    }

    private function wipe(): void
    {
        $this->db->table('referral_referrals')->emptyTable();
        $this->db->table('audit_outbox')
            ->groupStart()
                ->like('action_code', 'referral.', 'after')
                ->orWhere('entity_type', 'referral_referrals')
            ->groupEnd()
            ->delete();
    }

    /**
     * @param list<string> $studentIds
     */
    private function seedReferrals(int $clinicIssuerId, int $counsellingIssuerId, array $studentIds): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowStr = $now->format('Y-m-d H:i:s');
        $crypto = new EncryptionService();

        // Each row is one referral at the moment of submission;
        // the `closure_at` and `acknowledged_at` timestamps are
        // back-dated by adjusting `created_at` + `updated_at` so
        // the ReferralsPage timeline is intuitive.
        $rows = [];

        // 1. Closed — clinic -> counselling, full lifecycle, 7d ago.
        $closed = $this->makeRow(
            patient:    $studentIds[0],
            source:     'clinic',
            target:     'counselling',
            artifact:   'referral_letter',
            reason:     'behavioural_concern',
            note:       'Teacher reported sudden withdrawal from class. Recommended counselling intake within 1 week.',
            issuerId:   $clinicIssuerId,
            created:    $now->modify('-7 days'),
        );
        $closed['status'] = 'closed';
        $rows[] = $closed;

        // 2. UnderReview — counselling -> clinic, 3d ago.
        $under = $this->makeRow(
            patient:    $studentIds[1],
            source:     'counselling',
            target:     'clinic',
            artifact:   'referral_letter',
            reason:     'somatic_symptom',
            note:       'Patient reports persistent headaches over 2 weeks. Please rule out vision/medical causes before next session.',
            issuerId:   $counsellingIssuerId,
            created:    $now->modify('-3 days'),
        );
        $under['status'] = 'under_review';
        $rows[] = $under;

        // 3. Acknowledged — clinic -> counselling, 1d ago.
        $ack = $this->makeRow(
            patient:    $studentIds[2],
            source:     'clinic',
            target:     'counselling',
            artifact:   'referral_letter',
            reason:     'academic_stress',
            note:       'Student sought clinic after failing 2 exams. Recommended coping-skills session.',
            issuerId:   $clinicIssuerId,
            created:    $now->modify('-1 day'),
        );
        $ack['status'] = 'acknowledged';
        $rows[] = $ack;

        // 4. Submitted — counselling -> clinic, just now.
        $rows[] = $this->makeRow(
            patient:    $studentIds[3],
            source:     'counselling',
            target:     'clinic',
            artifact:   'referral_letter',
            reason:     'physical_symptom',
            note:       'Patient disclosed recurring stomach pain with no clear emotional trigger. Please assess.',
            issuerId:   $counsellingIssuerId,
            created:    $now,
        );

        // 5. QR-issued valid — clinic -> counselling, with an active HMAC.
        $qrRow = $this->makeRow(
            patient:    $studentIds[4],
            source:     'clinic',
            target:     'counselling',
            artifact:   'intake_pass',
            reason:     'follow_up',
            note:       'Returning student — intake pass for the follow-up session. Present this QR at the counselling desk.',
            issuerId:   $clinicIssuerId,
            created:    $now,
        );
        $rows[] = $qrRow;

        // Encrypt the notes column for every row.
        foreach ($rows as $i => $r) {
            if ($r['notes_cipher'] !== null) {
                $env = $crypto->encryptField((string) $r['notes_cipher']);
                $rows[$i]['notes_cipher']      = $env['ciphertext'];
                $rows[$i]['notes_nonce']       = $env['nonce'];
                $rows[$i]['notes_key_version'] = $env['key_version'];
            }
        }

        $this->db->table('referral_referrals')->insertBatch($rows);

        // Issue the QR for the last row — direct HMAC write, since
        // the policy check inside the service would fail under a
        // seeder context that has no auth user.
        // `insertBatch` only returns the FIRST inserted id; for
        // a batch insert we re-read the last id by scanning for
        // the most recently created row that is still missing a QR.
        $lastRow = $this->db->table('referral_referrals')
            ->select('id')
            ->where('qr_token_hash', null)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();
        $lastId = $lastRow !== null ? (int) $lastRow['id'] : 0;
        $this->issueDemoQr($lastId, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRow(
        string $patient,
        string $source,
        string $target,
        string $artifact,
        string $reason,
        string $note,
        int $issuerId,
        \DateTimeImmutable $created,
    ): array {
        return [
            'patient_school_id' => $patient,
            'source_module'     => $source,
            'target_module'     => $target,
            'artifact_type'     => $artifact,
            'issuer_user_id'    => $issuerId,
            'status'            => 'submitted',
            'reason_code'       => $reason,
            // Will be encrypted after the row is built; we keep the
            // plaintext in this field as a staging slot.
            'notes_cipher'      => $note,
            'created_at'        => $created->format('Y-m-d H:i:s'),
            'updated_at'        => $created->format('Y-m-d H:i:s'),
        ];
    }

    private function issueDemoQr(int $referralId, \DateTimeImmutable $now): void
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $key   = (string) (getenv('REFERRAL_HMAC_KEY') ?: '');
        if ($key === '') {
            fwrite(STDERR, "WARN: REFERRAL_HMAC_KEY not set — referral #{$referralId} seeded without a QR token.\n");
            return;
        }
        $hash    = hash_hmac('sha256', $plain, $key);
        $expires = $now->modify('+30 days')->format('Y-m-d H:i:s');

        $this->db->table('referral_referrals')
            ->where('id', $referralId)
            ->update([
                'qr_token_hash' => $hash,
                'qr_expires_at' => $expires,
                'updated_at'    => $now->format('Y-m-d H:i:s'),
            ]);

        fwrite(STDOUT, "  -> referral #{$referralId} QR token (dev only): {$plain}\n");
    }
}
