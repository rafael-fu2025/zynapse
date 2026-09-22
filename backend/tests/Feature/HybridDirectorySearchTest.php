<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FuMis\HttpTransport;
use Config\Services;
use Modules\Clinic\Services\PatientLookupService;

/**
 * HybridDirectorySearchTest — tests real-time MIS directory search,
 * deduplication with local users, and on-demand JIT provisioning.
 */
final class HybridDirectorySearchTest extends FeatureTestCase
{
    private MockMisTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('fuMisAuthService');
        Services::resetSingle('fuMisClient');
        Services::resetSingle('fuMisTransport');

        $this->transport = new MockMisTransport();
        Services::injectMock('fuMisTransport', $this->transport);

        putenv('FUMIS_ENABLED=1');
        $_SERVER['FUMIS_ENABLED'] = '1';
    }

    protected function tearDown(): void
    {
        putenv('FUMIS_ENABLED=0');
        $_SERVER['FUMIS_ENABLED'] = '0';
        Services::resetSingle('fuMisTransport');
        Services::resetSingle('fuMisClient');
        Services::resetSingle('fuMisAuthService');

        parent::tearDown();
    }

    public function testSearchStudentsCombinesLocalAndMisDirectory(): void
    {
        $admin = $this->login(['clinic_admin']);
        $uniqueSurname = 'Torres' . bin2hex(random_bytes(3));

        // Create a local student
        $localNum = '2026' . random_int(1000, 9999);
        $this->authed($admin['token'], 'post', 'api/v1/clinic/students', [
            'student_number' => $localNum,
            'first_name'     => 'Ana',
            'last_name'      => $uniqueSurname,
            'course'         => 'BSIT',
            'year_level'     => 3,
        ]);

        // Configure mock MIS directory response with the same student and a new student
        $newDirNum = '2026' . random_int(1000, 9999);
        $this->transport->students = [
            [
                'student_id' => $localNum,
                'first_name' => 'Ana Upstream',
                'last_name'  => $uniqueSurname,
                'program'    => 'BSIT',
                'level'      => '3',
            ],
            [
                'student_id' => $newDirNum,
                'first_name' => 'Ben',
                'last_name'  => $uniqueSurname,
                'program'    => 'BSN',
                'level'      => '1',
            ],
        ];

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/students/search?q=' . $uniqueSurname);
        $res->assertStatus(200);

        $data = $this->envelope($res)['data'];
        $this->assertCount(2, $data, 'Must return deduplicated local + directory student matches');

        $localMatch = null;
        $dirMatch = null;
        foreach ($data as $item) {
            if ($item['student_number'] === $localNum) {
                $localMatch = $item;
            }
            if ($item['student_number'] === $newDirNum) {
                $dirMatch = $item;
            }
        }

        $this->assertNotNull($localMatch);
        $this->assertFalse($localMatch['is_directory_record']);
        $this->assertSame('Ana', $localMatch['first_name']);

        $this->assertNotNull($dirMatch);
        $this->assertTrue($dirMatch['is_directory_record']);
        $this->assertSame('Ben', $dirMatch['first_name']);
    }

    public function testShowStudentAutoProvisionsUnprovisionedDirectoryRecord(): void
    {
        $admin = $this->login(['clinic_admin']);
        $dirNum = '2026' . random_int(1000, 9999);

        $this->transport->studentDetail = [
            'student_id' => $dirNum,
            'first_name' => 'Clara',
            'last_name'  => 'Oswald',
            'program'    => 'BSEd',
            'level'      => '2',
        ];

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/students/' . $dirNum);
        $res->assertStatus(200);

        $data = $this->envelope($res)['data'];
        $this->assertSame($dirNum, $data['student_number']);
        $this->assertSame('Clara', $data['first_name']);
        $this->assertSame('Oswald', $data['last_name']);
        $this->assertGreaterThan(0, $data['id']);

        // Assert row actually exists in MariaDB users table
        $db = Services::database();
        $row = $db->table('users')->where('student_number', $dirNum)->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('student', $row['kind']);
    }

    public function testPatientLookupServiceAutoResolvesAndProvisions(): void
    {
        $dirNum = '2026' . random_int(1000, 9999);
        $this->transport->studentDetail = [
            'student_id' => $dirNum,
            'first_name' => 'Rose',
            'last_name'  => 'Tyler',
            'program'    => 'BSCS',
            'level'      => '4',
        ];

        $lookup = new PatientLookupService();
        [$kind, $patient] = $lookup->findByIdentifier($dirNum);

        $this->assertSame('student', $kind);
        $this->assertNotNull($patient);
        $this->assertSame($dirNum, $patient['student_number']);
        $this->assertSame('Rose', $patient['first_name']);
    }

    public function testSearchEmployeesCombinesLocalAndMisDirectory(): void
    {
        $admin = $this->login(['clinic_admin']);
        $uniqueSurname = 'Dent' . bin2hex(random_bytes(3));

        $empNum = 'EMP' . random_int(1000, 9999);
        $this->transport->employees = [
            [
                'employee_id' => $empNum,
                'first_name'  => 'Arthur',
                'last_name'   => $uniqueSurname,
                'department'  => 'Library',
                'position'    => 'Librarian',
                'status'      => 'active',
            ],
        ];

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees/search?q=' . $uniqueSurname);
        $res->assertStatus(200);

        $data = $this->envelope($res)['data'];
        $this->assertNotEmpty($data);
        $match = $data[0];
        $this->assertSame($empNum, $match['employee_number']);
        $this->assertTrue($match['is_directory_record']);
    }

    public function testAdminUsersSearchIncludesDirectoryAndProvisionEndpoint(): void
    {
        $admin = $this->login(['superadmin']);
        $uniqueSurname = 'Prefect' . bin2hex(random_bytes(3));

        $empNum = 'EMP' . random_int(1000, 9999);
        $this->transport->employees = [
            [
                'employee_id' => $empNum,
                'first_name'  => 'Ford',
                'last_name'   => $uniqueSurname,
                'department'  => 'Research',
                'position'    => 'Field Researcher',
                'status'      => 'active',
            ],
        ];

        // Search in Admin Users
        $res = $this->authed($admin['token'], 'get', 'api/v1/admin/users?q=' . $uniqueSurname);
        $res->assertStatus(200);

        $rows = $this->envelope($res)['data'];
        $dirMatches = array_filter($rows, static fn ($u) => ($u['username'] ?? null) === 'emp-' . $empNum);
        $this->assertNotEmpty($dirMatches);

        // Provision via admin endpoint
        $provRes = $this->authed($admin['token'], 'post', 'api/v1/admin/users/provision', [
            'identifier' => $empNum,
            'kind'       => 'employee',
        ]);
        $provRes->assertStatus(200);
        $provData = $this->envelope($provRes)['data'];
        $this->assertSame('emp-' . $empNum, $provData['username']);
        $this->assertGreaterThan(0, $provData['id']);
        $this->assertFalse($provData['is_directory_record']);
    }

    /**
     * Search → grant: a directory hit with NO local row is provisioned by
     * the explicit Save, and the requested role is ADDED on top of the
     * MIS default (employee) — never replacing it.
     */
    public function testProvisionGrantsRequestedRolesToNewMisOnlyEmployee(): void
    {
        $admin = $this->login(['superadmin']);
        $surname = 'Quill' . bin2hex(random_bytes(3));
        $empNum = 'EMP' . random_int(100000, 999999);

        $profile = [
            'employee_id'       => $empNum,
            'first_name'        => 'Peter',
            'last_name'         => $surname,
            'department'        => 'Research',
            'position'          => 'Analyst',
            'employment_status' => 'active',
        ];
        $this->transport->employees = [$profile];
        $this->transport->employeeDetail = $profile;

        // The directory search exposes a synthetic (negative-id) row.
        $search = $this->authed($admin['token'], 'get', 'api/v1/admin/users?q=' . $surname);
        $search->assertStatus(200);
        $dir = $this->findRowByUsername($this->envelope($search)['data'], 'emp-' . $empNum);
        $this->assertNotNull($dir, 'Directory search must surface the MIS-only employee.');
        $this->assertTrue($dir['is_directory_record']);
        $this->assertLessThan(0, $dir['id'], 'Directory rows carry a synthetic negative id.');

        // No local row exists before the explicit Save.
        $db = Services::database();
        $this->assertNull(
            $db->table('users')->where('employee_number', $empNum)->get()->getRowArray(),
            'A directory hit must not create a local row before Save.',
        );

        // Save → provision + grant the requested role.
        $prov = $this->authed($admin['token'], 'post', 'api/v1/admin/users/provision', [
            'identifier' => $empNum,
            'kind'       => 'employee',
            'groups'     => ['clinic_staff'],
        ]);
        $prov->assertStatus(200);
        $data = $this->envelope($prov)['data'];
        $this->assertSame('emp-' . $empNum, $data['username']);
        $this->assertGreaterThan(0, $data['id']);
        $this->assertFalse($data['is_directory_record']);

        $groups = $data['groups'];
        sort($groups);
        $this->assertSame(['clinic_staff', 'employee'], $groups, 'Requested roles are added to the MIS default, not substituted for it.');

        // Exactly two memberships — no duplicate rows.
        $this->assertSame(
            2,
            $db->table('auth_groups_users')->where('user_id', (int) $data['id'])->countAllResults(),
        );
    }

    /**
     * An employee who already has a local row with a custom username and
     * custom roles keeps BOTH: provisioning is additive and never rewrites
     * the person's identity or drops memberships.
     */
    public function testProvisionPreservesExistingCustomUsernameAndRoles(): void
    {
        $admin = $this->login(['superadmin']);
        $empNum = 'EMP' . random_int(100000, 999999);
        $db = Services::database();
        $now = date('Y-m-d H:i:s');

        $db->table('users')->insert([
            'tenant_id'       => 1,
            'username'        => 'custom-' . strtolower($empNum),
            'employee_number' => $empNum,
            'kind'            => 'employee',
            'first_name'      => 'Custom',
            'last_name'       => 'Employee',
            'status'          => 'active',
            'active'          => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $userId = (int) $db->insertID();
        $this->assignGroups($userId, ['clinic_staff', 'counsellor'], $now);

        $prov = $this->authed($admin['token'], 'post', 'api/v1/admin/users/provision', [
            'identifier' => $empNum,
            'kind'       => 'employee',
            'groups'     => ['employee'],
        ]);
        $prov->assertStatus(200);
        $data = $this->envelope($prov)['data'];

        $this->assertSame($userId, (int) $data['id']);
        $this->assertSame('custom-' . strtolower($empNum), $data['username'], 'Provisioning must not overwrite the local username.');

        $groups = $data['groups'];
        sort($groups);
        $this->assertSame(['clinic_staff', 'counsellor', 'employee'], $groups, 'Existing roles must be preserved; only the missing role is added.');
    }

    /**
     * Repeating the same provision call is a no-op: same user, no new
     * memberships, no duplicate rows.
     */
    public function testProvisionIsIdempotent(): void
    {
        $admin = $this->login(['superadmin']);
        $empNum = 'EMP' . random_int(100000, 999999);
        $this->transport->employeeDetail = [
            'employee_id'       => $empNum,
            'first_name'        => 'Repeat',
            'last_name'         => 'Caller',
            'department'        => 'Registry',
            'position'          => 'Clerk',
            'employment_status' => 'active',
        ];

        $payload = ['identifier' => $empNum, 'kind' => 'employee', 'groups' => ['clinic_staff']];

        $first = $this->authed($admin['token'], 'post', 'api/v1/admin/users/provision', $payload);
        $first->assertStatus(200);
        $firstId = (int) $this->envelope($first)['data']['id'];

        $second = $this->authed($admin['token'], 'post', 'api/v1/admin/users/provision', $payload);
        $second->assertStatus(200);
        $secondId = (int) $this->envelope($second)['data']['id'];

        $this->assertSame($firstId, $secondId, 'A repeated provision must resolve the same local user.');

        $db = Services::database();
        $this->assertSame(1, $db->table('users')->where('employee_number', $empNum)->countAllResults());
        $this->assertSame(
            2,
            $db->table('auth_groups_users')->where('user_id', $firstId)->countAllResults(),
            'Repeated provisioning must not insert duplicate memberships.',
        );
    }

    /**
     * An unknown role is rejected by validation BEFORE any MIS work: no
     * upstream lookup, no local user.
     */
    public function testProvisionRejectsUnknownGroupBeforeAnyMisWork(): void
    {
        $admin = $this->login(['superadmin']);
        $empNum = 'EMP' . random_int(100000, 999999);
        $this->transport->employeeDetail = [
            'employee_id'       => $empNum,
            'first_name'        => 'Unknown',
            'last_name'         => 'Role',
            'department'        => 'Research',
            'position'          => 'Analyst',
            'employment_status' => 'active',
        ];

        $res = $this->authed($admin['token'], 'post', 'api/v1/admin/users/provision', [
            'identifier' => $empNum,
            'kind'       => 'employee',
            'groups'     => ['not_a_real_role'],
        ]);

        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);

        $db = Services::database();
        $this->assertNull(
            $db->table('users')->where('employee_number', $empNum)->get()->getRowArray(),
            'A rejected role must never provision a local user.',
        );
    }

    /**
     * Malformed identifiers are rejected before any MIS call.
     */
    public function testProvisionRejectsMalformedIdentifier(): void
    {
        $admin = $this->login(['superadmin']);

        foreach (['../etc/passwd', 'has space', 'slash/inside', "tab\there"] as $bad) {
            $res = $this->authed($admin['token'], 'post', 'api/v1/admin/users/provision', [
                'identifier' => $bad,
                'kind'       => 'employee',
                'groups'     => ['employee'],
            ]);
            $res->assertStatus(422);
            $this->assertErrorCode('validation.field', $res);
        }
    }

    /**
     * A caller without `rbac.manage` is refused at the route, before the
     * service (and therefore before any MIS work or user creation).
     */
    public function testProvisionRequiresRbacManage(): void
    {
        $actor = $this->login(['student']);
        $empNum = 'EMP' . random_int(100000, 999999);

        $res = $this->authed($actor['token'], 'post', 'api/v1/admin/users/provision', [
            'identifier' => $empNum,
            'kind'       => 'employee',
            'groups'     => ['employee'],
        ]);

        $res->assertStatus(403);

        $db = Services::database();
        $this->assertNull($db->table('users')->where('employee_number', $empNum)->get()->getRowArray());
    }

    /**
     * A `rbac.manage`-only unit admin may not grant a privileged role via
     * the directory path: the escalation gate fires BEFORE any MIS work,
     * so no user is created.
     */
    public function testProvisionRejectsPrivilegedGrantBeforeAnyMisWork(): void
    {
        $actor = $this->login(['guidance_admin']);
        $empNum = 'EMP' . random_int(100000, 999999);
        $this->transport->employeeDetail = [
            'employee_id'       => $empNum,
            'first_name'        => 'Escalate',
            'last_name'         => 'Attempt',
            'department'        => 'Guidance',
            'position'          => 'Coordinator',
            'employment_status' => 'active',
        ];

        $res = $this->authed($actor['token'], 'post', 'api/v1/admin/users/provision', [
            'identifier' => $empNum,
            'kind'       => 'employee',
            'groups'     => ['clinic_admin'],
        ]);

        $res->assertStatus(403);
        $this->assertErrorCode('rbac.escalation_forbidden', $res);

        $db = Services::database();
        $this->assertNull(
            $db->table('users')->where('employee_number', $empNum)->get()->getRowArray(),
            'A rejected privileged grant must never provision a local user.',
        );
    }

    /**
     * An identifier already owned by another tenant is a conflict, and the
     * foreign row is left completely untouched.
     */
    public function testProvisionRejectsIdentifierOwnedByAnotherTenant(): void
    {
        $admin = $this->login(['superadmin']);
        $empNum = 'EMP' . random_int(100000, 999999);
        $db = Services::database();
        $now = date('Y-m-d H:i:s');

        $db->table('users')->insert([
            'tenant_id'       => 2,
            'username'        => 'other-' . strtolower($empNum),
            'employee_number' => $empNum,
            'kind'            => 'employee',
            'first_name'      => 'Other',
            'last_name'       => 'Tenant',
            'status'          => 'active',
            'active'          => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        $foreignId = (int) $db->insertID();

        $res = $this->authed($admin['token'], 'post', 'api/v1/admin/users/provision', [
            'identifier' => $empNum,
            'kind'       => 'employee',
            'groups'     => ['employee'],
        ]);

        $res->assertStatus(409);
        $this->assertErrorCode('resource.conflict', $res);

        $this->assertSame(
            0,
            $db->table('auth_groups_users')->where('user_id', $foreignId)->countAllResults(),
            'The other tenant\'s row must not be mutated.',
        );
        $this->assertSame(
            1,
            $db->table('users')->where('employee_number', $empNum)->countAllResults(),
            'No second local row may be created for the foreign identifier.',
        );
    }

    /**
     * Regression: revoking a privileged role is the same authorization
     * problem as granting one. A `rbac.manage`-only unit admin used to be
     * able to strip a privileged role because only the DESIRED list was
     * authorized.
     */
    public function testUnitAdminCannotRevokePrivilegedRole(): void
    {
        $actor  = $this->login(['guidance_admin']);
        $target = $this->createUser(['clinic_admin', 'counsellor']);

        $res = $this->authed($actor['token'], 'post', 'api/v1/admin/users/' . $target['id'] . '/groups', [
            'groups' => ['counsellor'],
        ]);

        $res->assertStatus(403);
        $this->assertErrorCode('rbac.escalation_forbidden', $res);

        // The privileged role must still be attached.
        $db = Services::database();
        $stillPrivileged = $db->table('auth_groups_users gu')
            ->join('auth_groups g', 'g.id = gu.group_id')
            ->where('gu.user_id', $target['id'])
            ->where('g.name', 'clinic_admin')
            ->countAllResults();
        $this->assertSame(1, $stillPrivileged);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findRowByUsername(array $rows, string $username): ?array
    {
        foreach ($rows as $row) {
            if (($row['username'] ?? null) === $username) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param list<string> $groups
     */
    private function assignGroups(int $userId, array $groups, string $now): void
    {
        $db = Services::database();
        foreach ($groups as $groupName) {
            $group = $db->table('auth_groups')->where('name', $groupName)->get()->getRowArray();
            $this->assertNotNull($group, sprintf('Group "%s" must exist.', $groupName));
            $db->table('auth_groups_users')->insert([
                'group_id'   => (int) $group['id'],
                'user_id'    => $userId,
                'created_at' => $now,
            ]);
        }
    }
}

/**
 * MockMisTransport implements HttpTransport for token minting, student, and employee endpoints.
 */
final class MockMisTransport implements HttpTransport
{
    /** @var list<array<string, mixed>> */
    public array $students = [];

    /** @var array<string, mixed>|null */
    public ?array $studentDetail = null;

    /** @var list<array<string, mixed>> */
    public array $employees = [];

    /** @var array<string, mixed>|null */
    public ?array $employeeDetail = null;

    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $body = null,
        int $timeoutSeconds = 10,
    ): array {
        if (str_contains($url, '/api/tokens/generate')) {
            return [
                'status'  => 'success',
                'message' => 'Token generated',
                'data'    => [
                    'refresh_token' => 'mock-refresh-token-123',
                ],
            ];
        }

        if (str_contains($url, '/api/tokens/refresh')) {
            return [
                'status'  => 'success',
                'message' => 'Token refreshed',
                'data'    => [
                    'access_token'  => 'mock-access-token-123',
                    'refresh_token' => 'mock-refresh-token-456',
                    'expires_at'    => '2026-12-31 23:59:59',
                ],
            ];
        }

        if (str_contains($url, '/api/v1/students/')) {
            if ($this->studentDetail !== null) {
                return ['status' => 'success', 'data' => $this->studentDetail];
            }
            return ['status' => 'error', 'message' => 'Not found'];
        }

        if (str_contains($url, '/api/v1/students')) {
            return [
                'status' => 'success',
                'data'   => [
                    'data'         => $this->students,
                    'limit'        => 20,
                    'current_page' => 1,
                    'max_page'     => 1,
                ],
            ];
        }

        if (str_contains($url, '/api/v1/employees/')) {
            if ($this->employeeDetail !== null) {
                return ['status' => 'success', 'data' => $this->employeeDetail];
            }
            return ['status' => 'error', 'message' => 'Not found'];
        }

        if (str_contains($url, '/api/v1/employees')) {
            return [
                'status' => 'success',
                'data'   => [
                    'data'         => $this->employees,
                    'limit'        => 20,
                    'current_page' => 1,
                    'max_page'     => 1,
                ],
            ];
        }

        return ['status' => 'success', 'data' => []];
    }
}
