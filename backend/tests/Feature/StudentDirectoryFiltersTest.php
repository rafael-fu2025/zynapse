<?php

declare(strict_types=1);

namespace Tests\Feature;

use Config\Services;

/**
 * StudentDirectoryFiltersTest — the Students tab facet filters.
 *
 * Covers Department, Course and Year Level: the three categorical fields the
 * FU MIS student endpoint filters on itself (`department`, `program`,
 * `level`), stored locally as `users.department`, `users.course` and
 * `users.year_level`. They are the only student facets the UI offers.
 *
 * There is deliberately no Section facet. MIS returns exactly
 * `student_id, first_name, middle_name, last_name, program, level,
 * department` — no section — so `users.section` is null for every synced
 * student and a Section filter could never match a row.
 *
 * `department` is not writable through the create/update routes (MIS owns it
 * for students), so the department cases seed it directly, the same way the
 * tenant-scoping case relocates a row.
 */
final class StudentDirectoryFiltersTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep the search path deterministic and offline: the MIS fallback
        // must not be reached. All three channels are set because
        // Config\FuMis reads $_ENV first, then $_SERVER, then getenv().
        putenv('FUMIS_ENABLED=0');
        $_SERVER['FUMIS_ENABLED'] = '0';
        $_ENV['FUMIS_ENABLED']    = '0';
        Services::resetSingle('fuMisAuthService');
        Services::resetSingle('fuMisClient');
        Services::resetSingle('fuMisTransport');
    }

    protected function tearDown(): void
    {
        // Never leave a stale FUMIS_ENABLED override behind. Config\FuMis
        // reads $_ENV first, then $_SERVER, then getenv() — and the feature
        // suite shares one process. A leftover $_ENV value here would shadow
        // the putenv()/$_SERVER overrides that HybridDirectorySearchTest and
        // MisLoginFlowTest rely on, silently disabling MIS for them.
        putenv('FUMIS_ENABLED');
        unset($_SERVER['FUMIS_ENABLED'], $_ENV['FUMIS_ENABLED']);

        Services::resetSingle('fuMisTransport');
        Services::resetSingle('fuMisClient');
        Services::resetSingle('fuMisAuthService');

        parent::tearDown();
    }

    /** Collision-proof facet value. */
    private function unique(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Create a student through the real route and return its identity.
     *
     * @param array<string, mixed> $overrides
     * @return array{number: string, id: int}
     */
    private function createStudent(string $token, array $overrides = []): array
    {
        $number  = 'S' . random_int(100000, 999999) . strtoupper(bin2hex(random_bytes(2)));
        $payload = array_merge([
            'student_number' => $number,
            'first_name'     => 'Facet',
            'last_name'      => 'Student',
        ], $overrides);

        $res = $this->authed($token, 'post', 'api/v1/clinic/students', $payload);
        $res->assertStatus(201);

        return [
            'number' => $number,
            'id'     => (int) ($this->envelope($res)['data']['id'] ?? 0),
        ];
    }

    /**
     * Seed `users.department` directly — the create route does not accept it
     * because MIS owns the field for students.
     */
    private function seedDepartment(int $studentId, string $department): void
    {
        db_connect()->table('users')->where('id', $studentId)->update(['department' => $department]);
    }

    /** @return list<string> */
    private function studentNumbers(\CodeIgniter\Test\TestResponse $response): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['student_number'],
            $this->envelope($response)['data'],
        );
    }

    public function testDepartmentFacetNarrowsTheStudentList(): void
    {
        $admin = $this->login(['clinic_admin']);

        $deptA = $this->unique('Dept Alpha');
        $deptB = $this->unique('Dept Beta');

        $a = $this->createStudent($admin['token']);
        $b = $this->createStudent($admin['token']);
        $this->seedDepartment($a['id'], $deptA);
        $this->seedDepartment($b['id'], $deptB);

        $res = $this->authed(
            $admin['token'],
            'get',
            'api/v1/clinic/students?limit=100&department=' . rawurlencode($deptA),
        );
        $res->assertStatus(200);

        $body    = $this->envelope($res)['data'];
        $numbers = $this->studentNumbers($res);

        $this->assertContains($a['number'], $numbers, 'The matching department must be returned.');
        $this->assertNotContains($b['number'], $numbers, 'A different department must be excluded.');

        foreach ($body as $row) {
            $this->assertSame($deptA, $row['department'], 'Every row must match the requested department.');
        }
    }

    public function testCourseFacetNarrowsTheStudentList(): void
    {
        $admin = $this->login(['clinic_admin']);

        $courseA = $this->unique('Course Alpha');
        $courseB = $this->unique('Course Beta');

        $a = $this->createStudent($admin['token'], ['course' => $courseA]);
        $b = $this->createStudent($admin['token'], ['course' => $courseB]);

        $res = $this->authed(
            $admin['token'],
            'get',
            'api/v1/clinic/students?limit=100&course=' . rawurlencode($courseA),
        );
        $res->assertStatus(200);

        $numbers = $this->studentNumbers($res);
        $this->assertContains($a['number'], $numbers);
        $this->assertNotContains($b['number'], $numbers);

        foreach ($this->envelope($res)['data'] as $row) {
            $this->assertSame($courseA, $row['course']);
        }
    }

    public function testYearLevelFacetNarrowsTheStudentList(): void
    {
        $admin = $this->login(['clinic_admin']);

        $a = $this->createStudent($admin['token'], ['year_level' => 1]);
        $b = $this->createStudent($admin['token'], ['year_level' => 4]);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/students?limit=100&year_level=1');
        $res->assertStatus(200);

        $numbers = $this->studentNumbers($res);
        $this->assertContains($a['number'], $numbers);
        $this->assertNotContains($b['number'], $numbers);

        foreach ($this->envelope($res)['data'] as $row) {
            // The wire value is the bare number, not a "Year n" label.
            $this->assertSame(1, $row['year_level']);
        }
    }

    public function testFacetsCombineWithAndSemantics(): void
    {
        $admin = $this->login(['clinic_admin']);

        $course = $this->unique('Course Combo');
        $dept   = $this->unique('Dept Combo');

        $match       = $this->createStudent($admin['token'], ['course' => $course, 'year_level' => 2]);
        $courseOnly  = $this->createStudent($admin['token'], ['course' => $course, 'year_level' => 3]);
        $yearOnly    = $this->createStudent($admin['token'], ['course' => $this->unique('Course Other'), 'year_level' => 2]);
        $this->seedDepartment($match['id'], $dept);

        $res = $this->authed($admin['token'], 'get', sprintf(
            'api/v1/clinic/students?limit=100&course=%s&year_level=2&department=%s',
            rawurlencode($course),
            rawurlencode($dept),
        ));
        $res->assertStatus(200);

        $numbers = $this->studentNumbers($res);

        $this->assertContains($match['number'], $numbers);
        $this->assertNotContains($courseOnly['number'], $numbers, 'Year level must also match.');
        $this->assertNotContains($yearOnly['number'], $numbers, 'Course must also match.');
    }

    public function testFacetsStayInForceDuringSearch(): void
    {
        $admin   = $this->login(['clinic_admin']);
        $surname = $this->unique('Surname');

        $courseA = $this->unique('Course Search A');
        $courseB = $this->unique('Course Search B');

        $a = $this->createStudent($admin['token'], ['last_name' => $surname, 'course' => $courseA]);
        $b = $this->createStudent($admin['token'], ['last_name' => $surname, 'course' => $courseB]);

        $res = $this->authed($admin['token'], 'get', sprintf(
            'api/v1/clinic/students/search?q=%s&course=%s',
            rawurlencode($surname),
            rawurlencode($courseA),
        ));
        $res->assertStatus(200);

        $numbers = $this->studentNumbers($res);
        $this->assertContains($a['number'], $numbers);
        $this->assertNotContains(
            $b['number'],
            $numbers,
            'The facet must keep narrowing results while a search query is active.',
        );
    }

    public function testFacetsEndpointReturnsDistinctSortedOptionsAndDropsBlanks(): void
    {
        $admin  = $this->login(['clinic_admin']);
        $prefix = 'ZZFacet' . bin2hex(random_bytes(3));

        // Insert out of sort order so ordering is actually exercised.
        $b = $this->createStudent($admin['token'], ['course' => $prefix . '-b', 'year_level' => 2]);
        $a = $this->createStudent($admin['token'], ['course' => $prefix . '-a', 'year_level' => 1]);
        // Duplicate facet value — must appear once.
        $this->createStudent($admin['token'], ['course' => $prefix . '-a', 'year_level' => 1]);
        // Blank course — must be excluded entirely.
        $this->createStudent($admin['token'], ['course' => '']);
        $this->seedDepartment($a['id'], $prefix . '-a');
        $this->seedDepartment($b['id'], $prefix . '-b');

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/students/facets');
        $res->assertStatus(200);

        $data = $this->envelope($res)['data'];
        $this->assertArrayHasKey('departments', $data);
        $this->assertArrayHasKey('courses', $data);
        $this->assertArrayHasKey('yearLevels', $data);
        $this->assertIsArray($data['departments']);
        $this->assertIsArray($data['courses']);
        $this->assertIsArray($data['yearLevels']);

        foreach (['departments', 'courses'] as $key) {
            $values = $data[$key];

            $this->assertNotContains('', $values, "Blank values must not appear in {$key}.");
            $this->assertSame(
                array_values(array_unique($values)),
                $values,
                "Facet {$key} must be deduplicated.",
            );

            // Isolate our own prefixed values: their relative order in the
            // response must be ascending.
            $mine = array_values(array_filter(
                $values,
                static fn (string $v): bool => str_starts_with($v, $prefix),
            ));
            $expected = $mine;
            sort($expected);

            $this->assertSame([$prefix . '-a', $prefix . '-b'], $mine, "Facet {$key} must contain both values once.");
            $this->assertSame($expected, $mine, "Facet {$key} must be returned in ascending order.");
        }

        // Year levels are integers, so the select can label them "Year n".
        foreach ($data['yearLevels'] as $level) {
            $this->assertIsInt($level, 'Year levels must be integers, not strings.');
        }
    }

    public function testFacetsExcludeArchivedStudents(): void
    {
        $admin  = $this->login(['clinic_admin']);
        $course = $this->unique('Course Archived');

        $student = $this->createStudent($admin['token'], ['course' => $course]);

        $archive = $this->authed(
            $admin['token'],
            'post',
            sprintf('api/v1/clinic/students/%d/archive', $student['id']),
            ['archived' => true],
        );
        $archive->assertStatus(200);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/students/facets');
        $res->assertStatus(200);

        $this->assertNotContains(
            $course,
            $this->envelope($res)['data']['courses'],
            'Archived students must not contribute facet options.',
        );
    }

    public function testFacetsExcludeStudentsFromOtherTenants(): void
    {
        $admin  = $this->login(['clinic_admin']);
        $course = $this->unique('Course Other Tenant');

        $student = $this->createStudent($admin['token'], ['course' => $course]);

        // Relocate the row into a different tenant.
        db_connect()->table('users')->where('id', $student['id'])->update(['tenant_id' => 2]);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/students/facets');
        $res->assertStatus(200);

        $this->assertNotContains(
            $course,
            $this->envelope($res)['data']['courses'],
            'Facets must be scoped to the active tenant.',
        );
    }

    public function testFacetsEndpointRequiresPatientReadPermission(): void
    {
        $outsider = $this->login(['bmg_admin']);

        $res = $this->authed($outsider['token'], 'get', 'api/v1/clinic/students/facets');
        $res->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:clinic.patients.read', $res);
    }
}
