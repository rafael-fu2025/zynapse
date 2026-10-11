<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Clinic → Guidance referrals raised from the student-records Actions
 * menu ("Refer to Guidance"), end-to-end through the real routes:
 *
 *   - create with priority (enum-validated, default normal);
 *   - duplicate-active referral → 409 carrying the existing referral;
 *   - the student list carries an `active_guidance_referral` boolean
 *     (no referral content leaks) that clears when the referral closes;
 *   - the receiving-side gate: Clinic staff cannot acknowledge their own
 *     clinic→counselling referral; Guidance staff can, and close clears
 *     the indicator;
 *   - priority echoes through `referrals.read`.
 *
 * EncryptionService needs COUNSELLING_KEY (notes are AES-encrypted) —
 * pinned per-suite like SurveyTest.
 */
final class ReferralGuidanceTest extends FeatureTestCase
{
    private const KEY = 'f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3f3';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('COUNSELLING_KEY=' . self::KEY);
        putenv('COUNSELLING_KEY_VERSION=1');
    }

    protected function tearDown(): void
    {
        putenv('COUNSELLING_KEY');
        putenv('COUNSELLING_KEY_VERSION');
        parent::tearDown();
    }

    /** @return array{token: string, userId: int, email: string} */
    private function makeClinicStaff(): array
    {
        return $this->login(['clinic_staff']);
    }

    /** @return array{token: string, userId: int, email: string, studentNumber: string} */
    private function makeStudent(): array
    {
        $student = $this->login([]);
        $studentNumber = '2025' . random_int(100000, 999999);
        db_connect()->table('users')->where('id', $student['userId'])->update([
            'kind'           => 'student',
            'student_number' => $studentNumber,
        ]);
        return ['token' => $student['token'], 'userId' => $student['userId'], 'email' => $student['email'], 'studentNumber' => $studentNumber];
    }

    /**
     * @return array{id: int, priority: string}
     */
    private function createReferral(array $staff, string $studentNumber, array $overrides = []): array
    {
        $res = $this->authed($staff['token'], 'post', 'api/v1/referrals', array_merge([
            'patient_school_id' => $studentNumber,
            'source_module'     => 'clinic',
            'target_module'     => 'counselling',
            'artifact_type'     => 'intake_pass',
            'priority'          => 'high',
            'reason_code'       => 'academic_stress',
            'notes_plaintext'   => 'Asked for help after exam week.',
        ], $overrides));
        $res->assertStatus(201);
        $body = $this->envelope($res)['data'];
        return ['id' => (int) $body['id'], 'priority' => (string) $body['priority']];
    }

    public function testClinicStaffRefersStudentToGuidanceWithPriority(): void
    {
        $staff = $this->makeClinicStaff();
        $student = $this->makeStudent();

        $created = $this->authed($staff['token'], 'post', 'api/v1/referrals', [
            'patient_school_id' => $student['studentNumber'],
            'source_module'     => 'clinic',
            'target_module'     => 'counselling',
            'artifact_type'     => 'intake_pass',
            'priority'          => 'high',
            'reason_code'       => 'academic_stress',
            'notes_plaintext'   => 'Asked for help after exam week.',
        ]);
        $created->assertStatus(201);
        $body = $this->envelope($created)['data'];
        $this->assertSame('high', $body['priority']);
        $this->assertSame('submitted', $body['status']);
        $referralId = (int) $body['id'];

        // Priority echoes through the list the receiving side reads.
        $list = $this->authed($staff['token'], 'get', 'api/v1/referrals');
        $list->assertStatus(200);
        $rows = $this->envelope($list)['data'] ?? [];
        $row = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === $referralId))[0] ?? null;
        $this->assertIsArray($row);
        $this->assertSame('high', $row['priority']);
        $this->assertSame('counselling', $row['target_module']);
    }

    public function testPriorityIsValidatedAgainstTheEnum(): void
    {
        $staff = $this->makeClinicStaff();
        $student = $this->makeStudent();

        $res = $this->authed($staff['token'], 'post', 'api/v1/referrals', [
            'patient_school_id' => $student['studentNumber'],
            'source_module'     => 'clinic',
            'target_module'     => 'counselling',
            'artifact_type'     => 'intake_pass',
            'priority'          => 'asap',
        ]);
        $res->assertStatus(422);

        // Omitted priority defaults to normal (create + echo).
        $created = $this->authed($staff['token'], 'post', 'api/v1/referrals', [
            'patient_school_id' => $student['studentNumber'],
            'source_module'     => 'clinic',
            'target_module'     => 'counselling',
            'artifact_type'     => 'intake_pass',
            'reason_code'       => 'other',
        ]);
        $created->assertStatus(201);
        $this->assertSame('normal', $this->envelope($created)['data']['priority']);
    }

    public function testDuplicateActiveReferralIsRejectedWithTheExistingPayload(): void
    {
        $staff = $this->makeClinicStaff();
        $student = $this->makeStudent();
        $first = $this->createReferral($staff, $student['studentNumber']);

        $dup = $this->authed($staff['token'], 'post', 'api/v1/referrals', [
            'patient_school_id' => $student['studentNumber'],
            'source_module'     => 'clinic',
            'target_module'     => 'counselling',
            'artifact_type'     => 'intake_pass',
        ]);
        $dup->assertStatus(409);
        $errors = $this->envelope($dup)['errors'];
        $this->assertSame('referral.active_duplicate', $errors[0]['code']);
        $this->assertSame($first['id'], (int) $errors[0]['details']['referral']['id']);
    }

    public function testStudentListCarriesReferredIndicatorThatClearsOnClose(): void
    {
        $staff = $this->makeClinicStaff();
        $student = $this->makeStudent();
        $referral = $this->createReferral($staff, $student['studentNumber']);

        // The clinic student list marks the student as referred (boolean
        // only — no referral content in the payload).
        $list = $this->authed($staff['token'], 'get', 'api/v1/clinic/students');
        $list->assertStatus(200);
        $rows = $this->envelope($list)['data'] ?? [];
        $row = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === $student['userId']))[0] ?? null;
        $this->assertIsArray($row);
        $this->assertTrue($row['active_guidance_referral']);
        $this->assertArrayNotHasKey('reason_code', $row);
        $this->assertArrayNotHasKey('notes_cipher', $row);

        // Detail carries the same flag.
        $detail = $this->authed($staff['token'], 'get', 'api/v1/clinic/students/' . $student['userId']);
        $detail->assertStatus(200);
        $this->assertTrue($this->envelope($detail)['data']['active_guidance_referral']);

        // Clinic staff are the SOURCE side — they cannot acknowledge.
        $this->authed($staff['token'], 'post', 'api/v1/referrals/' . $referral['id'] . '/acknowledge', [])->assertStatus(403);

        // Guidance (receiving side) acknowledges then closes; closure
        // clears the indicator.
        $guidance = $this->login(['guidance_admin']);
        $this->authed($guidance['token'], 'post', 'api/v1/referrals/' . $referral['id'] . '/acknowledge', [])->assertStatus(200);
        $this->authed($guidance['token'], 'post', 'api/v1/referrals/' . $referral['id'] . '/close', [])->assertStatus(200);

        $after = $this->authed($staff['token'], 'get', 'api/v1/clinic/students');
        $afterRows = $this->envelope($after)['data'] ?? [];
        $afterRow = array_values(array_filter($afterRows, static fn (array $r): bool => (int) $r['id'] === $student['userId']))[0] ?? null;
        $this->assertIsArray($afterRow);
        $this->assertFalse($afterRow['active_guidance_referral']);
    }
}
