<?php

declare(strict_types=1);

namespace Tests\Feature;

use Config\Services;

/**
 * EmployeeDirectoryFiltersTest — the Employees tab facet filters.
 *
 * Covers the replacement for the removed teaching / non-teaching "Type"
 * filter. The FU MIS employee payload carries exactly seven fields —
 * `employee_id`, `last_name`, `first_name`, `middle_name`, `position`,
 * `department_code`, `department_name` — so Department and Position are the
 * only two categorical fields the directory can meaningfully filter on. MIS
 * supplies no teaching flag, which is why the old Type filter always
 * returned zero rows for "Teaching (faculty)".
 */
final class EmployeeDirectoryFiltersTest extends FeatureTestCase
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
     * Create an employee through the real route and return its identity.
     *
     * @param array<string, mixed> $overrides
     * @return array{number: string, id: int}
     */
    private function createEmployee(string $token, array $overrides = []): array
    {
        $number  = 'E' . random_int(100000, 999999) . strtoupper(bin2hex(random_bytes(2)));
        $payload = array_merge([
            'employee_number' => $number,
            'first_name'      => 'Facet',
            'last_name'       => 'Tester',
        ], $overrides);

        $res = $this->authed($token, 'post', 'api/v1/clinic/employees', $payload);
        $res->assertStatus(201);

        return [
            'number' => $number,
            'id'     => (int) ($this->envelope($res)['data']['id'] ?? 0),
        ];
    }

    /** @return list<string> */
    private function employeeNumbers(\CodeIgniter\Test\TestResponse $response): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['employee_number'],
            $this->envelope($response)['data'],
        );
    }

    public function testDepartmentFacetNarrowsTheEmployeeList(): void
    {
        $admin = $this->login(['clinic_admin']);

        $deptA = $this->unique('Dept Alpha');
        $deptB = $this->unique('Dept Beta');

        $a = $this->createEmployee($admin['token'], ['department' => $deptA]);
        $b = $this->createEmployee($admin['token'], ['department' => $deptB]);

        $res = $this->authed(
            $admin['token'],
            'get',
            'api/v1/clinic/employees?limit=100&department=' . rawurlencode($deptA),
        );
        $res->assertStatus(200);

        $body    = $this->envelope($res)['data'];
        $numbers = $this->employeeNumbers($res);

        $this->assertContains($a['number'], $numbers, 'The matching department must be returned.');
        $this->assertNotContains($b['number'], $numbers, 'A different department must be excluded.');

        foreach ($body as $row) {
            $this->assertSame($deptA, $row['department'], 'Every row must match the requested department.');
        }
    }

    public function testPositionFacetNarrowsTheEmployeeList(): void
    {
        $admin = $this->login(['clinic_admin']);

        $posA = $this->unique('Pos Alpha');
        $posB = $this->unique('Pos Beta');

        $a = $this->createEmployee($admin['token'], ['position' => $posA]);
        $b = $this->createEmployee($admin['token'], ['position' => $posB]);

        $res = $this->authed(
            $admin['token'],
            'get',
            'api/v1/clinic/employees?limit=100&position=' . rawurlencode($posA),
        );
        $res->assertStatus(200);

        $numbers = $this->employeeNumbers($res);
        $this->assertContains($a['number'], $numbers);
        $this->assertNotContains($b['number'], $numbers);

        foreach ($this->envelope($res)['data'] as $row) {
            $this->assertSame($posA, $row['position']);
        }
    }

    public function testDepartmentAndPositionCombineWithAndSemantics(): void
    {
        $admin = $this->login(['clinic_admin']);

        $dept   = $this->unique('Dept Combo');
        $posYes = $this->unique('Pos Combo');
        $posNo  = $this->unique('Pos Other');

        $match  = $this->createEmployee($admin['token'], ['department' => $dept, 'position' => $posYes]);
        $deptOnly = $this->createEmployee($admin['token'], ['department' => $dept, 'position' => $posNo]);
        $posOnly  = $this->createEmployee($admin['token'], ['department' => $this->unique('Dept Other'), 'position' => $posYes]);

        $res = $this->authed($admin['token'], 'get', sprintf(
            'api/v1/clinic/employees?limit=100&department=%s&position=%s',
            rawurlencode($dept),
            rawurlencode($posYes),
        ));
        $res->assertStatus(200);

        $numbers = $this->employeeNumbers($res);

        $this->assertContains($match['number'], $numbers);
        $this->assertNotContains($deptOnly['number'], $numbers, 'Position must also match.');
        $this->assertNotContains($posOnly['number'], $numbers, 'Department must also match.');
    }

    public function testFacetsStayInForceDuringSearch(): void
    {
        $admin   = $this->login(['clinic_admin']);
        $surname = $this->unique('Surname');
        $deptA   = $this->unique('Dept Search A');
        $deptB   = $this->unique('Dept Search B');

        $a = $this->createEmployee($admin['token'], ['last_name' => $surname, 'department' => $deptA]);
        $b = $this->createEmployee($admin['token'], ['last_name' => $surname, 'department' => $deptB]);

        $res = $this->authed($admin['token'], 'get', sprintf(
            'api/v1/clinic/employees/search?q=%s&department=%s',
            rawurlencode($surname),
            rawurlencode($deptA),
        ));
        $res->assertStatus(200);

        $numbers = $this->employeeNumbers($res);
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
        $this->createEmployee($admin['token'], ['department' => $prefix . '-b', 'position' => $prefix . '-b']);
        $this->createEmployee($admin['token'], ['department' => $prefix . '-a', 'position' => $prefix . '-a']);
        // Duplicate facet value — must appear once.
        $this->createEmployee($admin['token'], ['department' => $prefix . '-a', 'position' => $prefix . '-a']);
        // Blank facet values — must be excluded entirely.
        $this->createEmployee($admin['token'], ['department' => '', 'position' => '']);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees/facets');
        $res->assertStatus(200);

        $data = $this->envelope($res)['data'];
        $this->assertArrayHasKey('departments', $data);
        $this->assertArrayHasKey('positions', $data);
        $this->assertIsArray($data['departments']);
        $this->assertIsArray($data['positions']);

        foreach (['departments', 'positions'] as $key) {
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
    }

    public function testFacetsExcludeArchivedEmployees(): void
    {
        $admin = $this->login(['clinic_admin']);
        $dept  = $this->unique('Dept Archived');

        $emp = $this->createEmployee($admin['token'], ['department' => $dept]);

        $archive = $this->authed(
            $admin['token'],
            'post',
            sprintf('api/v1/clinic/employees/%d/archive', $emp['id']),
            ['archived' => true],
        );
        $archive->assertStatus(200);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees/facets');
        $res->assertStatus(200);

        $this->assertNotContains(
            $dept,
            $this->envelope($res)['data']['departments'],
            'Archived employees must not contribute facet options.',
        );
    }

    public function testFacetsExcludeEmployeesFromOtherTenants(): void
    {
        $admin = $this->login(['clinic_admin']);
        $dept  = $this->unique('Dept Other Tenant');

        $emp = $this->createEmployee($admin['token'], ['department' => $dept]);

        // Relocate the row into a different tenant.
        db_connect()->table('users')->where('id', $emp['id'])->update(['tenant_id' => 2]);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees/facets');
        $res->assertStatus(200);

        $this->assertNotContains(
            $dept,
            $this->envelope($res)['data']['departments'],
            'Facets must be scoped to the active tenant.',
        );
    }

    public function testFacetsEndpointRequiresPatientReadPermission(): void
    {
        $outsider = $this->login(['bmg_admin']);

        $res = $this->authed($outsider['token'], 'get', 'api/v1/clinic/employees/facets');
        $res->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:clinic.patients.read', $res);
    }

    /**
     * The web UI dropped the teaching filter, but the query parameter stays
     * accepted because the Flutter client still sends it.
     */
    public function testTeachingParameterRemainsAcceptedForApiCompatibility(): void
    {
        $admin = $this->login(['clinic_admin']);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees?limit=5&teaching=non_teaching');
        $res->assertStatus(200);
        $this->assertTrue($this->envelope($res)['success']);
    }
}
