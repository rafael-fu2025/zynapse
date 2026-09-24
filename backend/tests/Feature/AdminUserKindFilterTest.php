<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * AdminUserKindFilterTest — the person-type facet on the Admin Users list.
 *
 * `users.kind` is a closed enum (`student|employee|contractor|alumni`) and is
 * nullable: platform accounts, the seeded superadmin among them, carry no
 * person record at all. The facet offers the values actually present plus
 * `unlinked` for that NULL bucket, so every option matches at least one user —
 * `contractor` and `alumni` are valid enum members that are empty today, and
 * offering them would build a dropdown that can only ever return nothing.
 *
 * The test users created by `login()` are themselves `kind IS NULL`, since the
 * suite's user factory never sets `kind`.
 */
final class AdminUserKindFilterTest extends FeatureTestCase
{
    /** @return array{id: int, number: string} */
    private function createStudent(string $token): array
    {
        $number = 'S' . random_int(100000, 999999);

        $res = $this->authed($token, 'post', 'api/v1/clinic/students', [
            'student_number' => $number,
            'first_name'     => 'Kind',
            'last_name'      => 'Probe',
        ]);
        $res->assertStatus(201);

        return [
            'id'     => (int) ($this->envelope($res)['data']['id'] ?? 0),
            'number' => $number,
        ];
    }

    /** @return array{id: int, number: string} */
    private function createEmployee(string $token): array
    {
        $number = 'E' . random_int(100000, 999999);

        $res = $this->authed($token, 'post', 'api/v1/clinic/employees', [
            'employee_number' => $number,
            'first_name'      => 'Kind',
            'last_name'       => 'Probe',
        ]);
        $res->assertStatus(201);

        return [
            'id'     => (int) ($this->envelope($res)['data']['id'] ?? 0),
            'number' => $number,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listUsers(string $token, string $query): array
    {
        $res = $this->authed($token, 'get', 'api/v1/admin/users?' . $query);
        $res->assertStatus(200);

        return $this->envelope($res)['data'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    public function testKindFilterReturnsOnlyRowsOfTheRequestedKind(): void
    {
        $admin = $this->login(['clinic_admin']);

        $this->createStudent($admin['token']);
        $this->createEmployee($admin['token']);

        foreach (['student', 'employee'] as $kind) {
            $rows = $this->listUsers($admin['token'], 'limit=50&kind=' . $kind);

            $this->assertNotSame([], $rows, "The {$kind} filter must return rows.");

            foreach ($rows as $row) {
                $this->assertSame(
                    $kind,
                    $row['person_kind'],
                    'Every row must carry the requested person type.',
                );
            }
        }
    }

    public function testKindFilterExcludesOtherKinds(): void
    {
        $admin = $this->login(['clinic_admin']);

        $student  = $this->createStudent($admin['token']);
        $employee = $this->createEmployee($admin['token']);

        $studentIds = $this->ids($this->listUsers($admin['token'], 'limit=100&kind=student'));
        $this->assertContains($student['id'], $studentIds, 'The matching student must be returned.');
        $this->assertNotContains($employee['id'], $studentIds, 'An employee must be excluded.');

        $employeeIds = $this->ids($this->listUsers($admin['token'], 'limit=100&kind=employee'));
        $this->assertContains($employee['id'], $employeeIds, 'The matching employee must be returned.');
        $this->assertNotContains($student['id'], $employeeIds, 'A student must be excluded.');
    }

    public function testUnlinkedKindReturnsOnlyAccountsWithoutAPersonRecord(): void
    {
        // Every login() user has kind IS NULL, so this one is a known member
        // of the unlinked bucket.
        $admin = $this->login(['clinic_admin']);

        $rows = $this->listUsers($admin['token'], 'limit=100&kind=unlinked');

        $this->assertNotSame([], $rows, 'The unlinked bucket must not be empty.');

        $ids = $this->ids($rows);
        $this->assertContains($admin['userId'], $ids, 'A platform account belongs in the unlinked bucket.');

        foreach ($rows as $row) {
            $this->assertNull($row['person_kind'], 'Unlinked rows must have no person kind.');
        }

        // And the bucket is disjoint from the person-backed kinds.
        $studentIds = $this->ids($this->listUsers($admin['token'], 'limit=100&kind=student'));
        $this->assertSame([], array_intersect($ids, $studentIds), 'Unlinked and student rows must not overlap.');
    }

    /**
     * The point of deriving the options from the rows: every option the UI
     * offers resolves to at least one user. The enum also carries
     * `contractor` and `alumni`, which are empty here and must not appear.
     */
    public function testEveryFacetOptionMatchesAtLeastOneUser(): void
    {
        $admin = $this->login(['clinic_admin']);
        $this->createStudent($admin['token']);
        $this->createEmployee($admin['token']);

        $res = $this->authed($admin['token'], 'get', 'api/v1/admin/users/facets');
        $res->assertStatus(200);

        $kinds = $this->envelope($res)['data']['kinds'];
        $this->assertIsArray($kinds);
        $this->assertNotSame([], $kinds);
        $this->assertSame(array_values(array_unique($kinds)), $kinds, 'Facet kinds must be deduplicated.');

        $this->assertContains('student', $kinds);
        $this->assertContains('employee', $kinds);
        $this->assertContains('unlinked', $kinds);

        foreach ($kinds as $kind) {
            $this->assertContains(
                $kind,
                ['student', 'employee', 'contractor', 'alumni', 'unlinked'],
                "Facet option {$kind} is outside the accepted vocabulary.",
            );

            $rows = $this->listUsers($admin['token'], 'limit=1&kind=' . $kind);
            $this->assertNotSame(
                [],
                $rows,
                "Facet option {$kind} must match at least one user.",
            );
        }
    }

    public function testInvalidKindIsRejected(): void
    {
        $admin = $this->login(['clinic_admin']);

        $res = $this->authed($admin['token'], 'get', 'api/v1/admin/users?kind=wizard');
        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);
    }

    public function testFacetsRequireRbacRead(): void
    {
        $outsider = $this->login(['counsellor']);

        $res = $this->authed($outsider['token'], 'get', 'api/v1/admin/users/facets');
        $res->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:rbac.read', $res);
    }
}
