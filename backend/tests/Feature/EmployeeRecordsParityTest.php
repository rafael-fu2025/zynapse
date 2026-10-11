<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Employee medical + guidance parity (2026-10):
 *
 *   - the person-level medical record (allergies + emergency contacts)
 *     works for EMPLOYEES through the new employee routes, and students
 *     keep working unchanged;
 *   - the "Referred to Guidance" indicator rides the employees list and
 *     search, boolean-only;
 *   - the per-person guidance record filter (`?patient_school_id=`)
 *     lists one person's counselling sessions, 404s on unknown
 *     identifiers, and stays behind `counselling.records.read`.
 */
final class EmployeeRecordsParityTest extends FeatureTestCase
{
    /** @return array{token: string, userId: int, email: string} */
    private function makeClinicStaff(): array
    {
        return $this->login(['clinic_staff']);
    }

    private function makeEmployee(): array
    {
        $employee = $this->login([]);
        $employeeNumber = 'EMP' . random_int(10000, 99999);
        db_connect()->table('users')->where('id', $employee['userId'])->update([
            'kind'            => 'employee',
            'employee_number' => $employeeNumber,
        ]);
        return ['token' => $employee['token'], 'userId' => $employee['userId'], 'employeeNumber' => $employeeNumber];
    }

    private function makeStudent(): array
    {
        $student = $this->login([]);
        $studentNumber = '2025' . random_int(100000, 999999);
        db_connect()->table('users')->where('id', $student['userId'])->update([
            'kind'           => 'student',
            'student_number' => $studentNumber,
        ]);
        return ['token' => $student['token'], 'userId' => $student['userId'], 'studentNumber' => $studentNumber];
    }

    public function testEmployeeMedicalRecordCrudAndStudentStillWorks(): void
    {
        $staff = $this->makeClinicStaff();
        $employee = $this->makeEmployee();

        // Add an allergy to an EMPLOYEE — previously 404 (student-locked).
        $added = $this->authed($staff['token'], 'post', 'api/v1/clinic/employees/' . $employee['userId'] . '/allergies', [
            'allergen' => 'Penicillin',
            'severity' => 'severe',
            'reaction' => 'Rash',
        ]);
        $added->assertStatus(201);
        $allergies = $this->envelope($added)['data']['allergies'];
        $this->assertCount(1, $allergies);
        $this->assertSame('Penicillin', $allergies[0]['allergen']);
        $allergyId = (int) $allergies[0]['id'];

        // Update + delete through the employee routes.
        $this->authed($staff['token'], 'post', 'api/v1/clinic/employees/' . $employee['userId'] . '/allergies/' . $allergyId, [
            'allergen' => 'Penicillin G',
            'severity' => 'moderate',
        ])->assertStatus(200);

        $contact = $this->authed($staff['token'], 'post', 'api/v1/clinic/employees/' . $employee['userId'] . '/contacts', [
            'contact_name' => 'Maria Cruz',
            'relationship' => 'spouse',
            'phone'        => '09171234567',
            'is_primary'   => true,
        ]);
        $contact->assertStatus(201);
        $contacts = $this->envelope($contact)['data']['contacts'];
        $this->assertCount(1, $contacts);
        $this->assertTrue($contacts[0]['is_primary']);

        // The employee detail carries the medical record on read.
        $detail = $this->authed($staff['token'], 'get', 'api/v1/clinic/employees/' . $employee['userId']);
        $detail->assertStatus(200);
        $this->assertCount(1, $this->envelope($detail)['data']['allergies']);

        $this->authed($staff['token'], 'post', 'api/v1/clinic/employees/' . $employee['userId'] . '/allergies/' . $allergyId . '/delete')->assertStatus(200);
        $this->assertSame(0, db_connect()->table('patient_allergies')->where('user_id', $employee['userId'])->countAllResults());

        // Students keep working exactly as before.
        $student = $this->makeStudent();
        $studentAdd = $this->authed($staff['token'], 'post', 'api/v1/clinic/students/' . $student['userId'] . '/allergies', [
            'allergen' => 'Peanuts',
            'severity' => 'mild',
        ]);
        $studentAdd->assertStatus(201);
        $this->assertCount(1, $this->envelope($studentAdd)['data']['allergies']);
    }

    public function testMedicalRecordRequiresClinicWrite(): void
    {
        $employee = $this->makeEmployee();
        // Guidance staff (no clinic.patients.write) → 403.
        $guidance = $this->login(['guidance_admin']);
        $this->authed($guidance['token'], 'post', 'api/v1/clinic/employees/' . $employee['userId'] . '/allergies', [
            'allergen' => 'Latex',
        ])->assertStatus(403);
    }

    public function testEmployeeListCarriesReferredToGuidanceIndicator(): void
    {
        $staff = $this->makeClinicStaff();
        $employee = $this->makeEmployee();

        $list = $this->authed($staff['token'], 'get', 'api/v1/clinic/employees');
        $list->assertStatus(200);
        $before = array_values(array_filter(
            $this->envelope($list)['data'] ?? [],
            static fn (array $r): bool => (int) $r['id'] === $employee['userId'],
        ))[0] ?? null;
        $this->assertIsArray($before);
        $this->assertFalse($before['active_guidance_referral']);

        // Clinic → counselling referral for the employee.
        $this->authed($staff['token'], 'post', 'api/v1/referrals', [
            'patient_school_id' => $employee['employeeNumber'],
            'source_module'     => 'clinic',
            'target_module'     => 'counselling',
            'artifact_type'     => 'intake_pass',
            'priority'          => 'urgent',
            'reason_code'       => 'emotional_concern',
        ])->assertStatus(201);

        $after = $this->authed($staff['token'], 'get', 'api/v1/clinic/employees');
        $row = array_values(array_filter(
            $this->envelope($after)['data'] ?? [],
            static fn (array $r): bool => (int) $r['id'] === $employee['userId'],
        ))[0] ?? null;
        $this->assertIsArray($row);
        $this->assertTrue($row['active_guidance_referral'], 'Employees carry the same referral indicator as students.');
        $this->assertArrayNotHasKey('reason_code', $row);
    }

    public function testGuidanceRecordFiltersToOnePatientAndNeedsTheGuidancePermission(): void
    {
        $guidance = $this->login(['guidance_admin']);
        $employee = $this->makeEmployee();
        $other = $this->makeStudent();

        // Open one session per person via the counselling route.
        foreach ([$employee, $other] as $person) {
            $this->authed($guidance['token'], 'post', 'api/v1/counselling/sessions', [
                'patient_school_id' => $person['employeeNumber'] ?? $person['studentNumber'],
            ])->assertStatus(201);
        }

        // The per-person record lists only that person's sessions.
        $res = $this->authed($guidance['token'], 'get', 'api/v1/counselling/sessions?patient_school_id=' . $employee['employeeNumber']);
        $res->assertStatus(200);
        $rows = $this->envelope($res)['data'] ?? [];
        $this->assertCount(1, $rows);
        $this->assertSame($employee['employeeNumber'], $rows[0]['patient_school_id']);

        // Unknown identifier → 404 (never a tenant-wide list).
        $this->authed($guidance['token'], 'get', 'api/v1/counselling/sessions?patient_school_id=NOPE-404')->assertStatus(404);

        // Clinic staff hold no counselling.records.read → 403.
        $clinic = $this->makeClinicStaff();
        $this->authed($clinic['token'], 'get', 'api/v1/counselling/sessions?patient_school_id=' . $employee['employeeNumber'])->assertStatus(403);
    }

    /**
     * Paging the filtered list exercises the keyset clauses WITH a cursor —
     * the case where the counsellor-name join would make bare `id` /
     * `created_at` ambiguous (SQL error) if the columns were unqualified.
     */
    public function testFilteredSessionListPagesWithACursor(): void
    {
        $guidance = $this->login(['guidance_admin']);
        $employee = $this->makeEmployee();

        for ($i = 0; $i < 3; $i++) {
            $this->authed($guidance['token'], 'post', 'api/v1/counselling/sessions', [
                'patient_school_id' => $employee['employeeNumber'],
            ])->assertStatus(201);
        }

        $first = $this->authed($guidance['token'], 'get', 'api/v1/counselling/sessions?patient_school_id=' . $employee['employeeNumber'] . '&limit=1');
        $first->assertStatus(200);
        $meta = $this->envelope($first)['meta']['pagination'] ?? [];
        $this->assertCount(1, $this->envelope($first)['data']);
        $cursor = $meta['next_cursor'] ?? null;
        $this->assertNotNull($cursor, 'Three sessions with limit=1 must yield a next cursor.');

        $second = $this->authed($guidance['token'], 'get', 'api/v1/counselling/sessions?patient_school_id=' . $employee['employeeNumber'] . '&limit=1&cursor=' . urlencode((string) $cursor));
        $second->assertStatus(200);
        $this->assertCount(1, $this->envelope($second)['data']);
    }
}
