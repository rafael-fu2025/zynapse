<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\MisSync;
use App\Services\FuMis\FuMisDirectorySyncService;
use App\Services\FuMis\HttpTransport;
use Config\Services;

/**
 * Offline feature test verifying MIS directory sync:
 * - Pagination walking through mock transport
 * - Dry-run preview vs apply writes
 * - Pre-login visibility in local student/employee and admin tables
 * - Role and local credential preservation
 */
final class MisDirectorySyncTest extends FeatureTestCase
{
    private MultiPageMockMisTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        Services::resetSingle('fuMisAuthService');
        Services::resetSingle('fuMisClient');
        Services::resetSingle('fuMisTransport');

        $this->transport = new MultiPageMockMisTransport();
        Services::injectMock('fuMisTransport', $this->transport);

        putenv('FUMIS_ENABLED=1');
        $_SERVER['FUMIS_ENABLED'] = '1';
        $_ENV['FUMIS_ENABLED'] = '1';
    }

    protected function tearDown(): void
    {
        putenv('FUMIS_ENABLED=0');
        $_SERVER['FUMIS_ENABLED'] = '0';
        $_ENV['FUMIS_ENABLED'] = '0';

        Services::resetSingle('fuMisTransport');
        Services::resetSingle('fuMisClient');
        Services::resetSingle('fuMisAuthService');

        parent::tearDown();
    }

    public function testDryRunPerformsNoLocalDatabaseWrites(): void
    {
        $id1 = '2026' . random_int(100000, 999999);
        $this->transport->studentPages = [
            1 => [
                'data'         => [['student_id' => $id1, 'first_name' => 'DryRunStudent']],
                'current_page' => 1,
                'max_page'     => 1,
            ],
        ];

        $service = new FuMisDirectorySyncService();
        $summary = $service->run('student', true, 10, 10);

        $this->assertTrue($summary['success']);
        $this->assertTrue($summary['dry_run']);
        $this->assertSame(1, $summary['kinds']['student']['created']);

        $db = Services::database();
        $exists = $db->table('users')->where('student_number', $id1)->countAllResults();
        $this->assertSame(0, $exists, 'Dry run must write zero local users');
    }

    public function testSyncMakesDirectoryStudentsAndEmployeesVisibleInListsBeforeLogin(): void
    {
        $stuId = '2026' . random_int(100000, 999999);
        $empId = 'EMP' . random_int(100000, 999999);

        $this->transport->studentPages = [
            1 => [
                'data'         => [['student_id' => $stuId, 'first_name' => 'PreLoginStudent', 'last_name' => 'Test', 'program' => 'BSCS', 'level' => 1]],
                'current_page' => 1,
                'max_page'     => 1,
            ],
        ];
        $this->transport->employeePages = [
            1 => [
                'data'         => [['employee_id' => $empId, 'first_name' => 'PreLoginEmployee', 'last_name' => 'Test', 'department' => 'IT', 'is_teaching' => true]],
                'current_page' => 1,
                'max_page'     => 1,
            ],
        ];

        $service = new FuMisDirectorySyncService();
        $summary = $service->run('all', false, 10, 10);

        $this->assertTrue($summary['success']);
        $this->assertSame(1, $summary['kinds']['student']['created']);
        $this->assertSame(1, $summary['kinds']['employee']['created']);

        $admin = $this->login(['clinic_admin']);

        // Default clinic students list (NO search term) now shows the student
        $stuListRes = $this->authed($admin['token'], 'get', 'api/v1/clinic/students');
        $stuListRes->assertStatus(200);
        $stuRows = $this->envelope($stuListRes)['data'];
        $foundStudent = array_values(array_filter($stuRows, static fn ($r) => ($r['student_number'] ?? null) === $stuId));
        $this->assertNotEmpty($foundStudent, 'Student must appear in regular list before any login');
        $this->assertFalse($foundStudent[0]['is_directory_record']);

        // Default clinic employees list now shows the employee
        $empListRes = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees');
        $empListRes->assertStatus(200);
        $empRows = $this->envelope($empListRes)['data'];
        $foundEmployee = array_values(array_filter($empRows, static fn ($r) => ($r['employee_number'] ?? null) === $empId));
        $this->assertNotEmpty($foundEmployee, 'Employee must appear in regular list before any login');
        $this->assertFalse($foundEmployee[0]['is_directory_record']);

        // Verify no local password credential exists for synced account
        $db = Services::database();
        $userRow = $db->table('users')->where('employee_number', $empId)->get()->getRowArray();
        $this->assertNotNull($userRow);
        $idCount = $db->table('auth_identities')
            ->where('user_id', (int) $userRow['id'])
            ->where('type', 'email_password')
            ->countAllResults();
        $this->assertSame(0, $idCount, 'Synced directory users must have no local password identity');
    }

    public function testSyncRerunPreservesCustomRolesAndUsername(): void
    {
        $empId = 'EMP' . random_int(100000, 999999);
        $this->transport->employeePages = [
            1 => [
                'data'         => [['employee_id' => $empId, 'first_name' => 'InitialName', 'last_name' => 'Staff']],
                'current_page' => 1,
                'max_page'     => 1,
            ],
        ];

        $service = new FuMisDirectorySyncService();
        $service->run('employee', false, 10, 10);

        $db = Services::database();
        $user = $db->table('users')->where('employee_number', $empId)->get()->getRowArray();
        $userId = (int) $user['id'];

        // Assign privileged superadmin role and custom username locally
        $superGroup = $db->table('auth_groups')->where('name', 'superadmin')->get()->getRowArray();
        $db->table('auth_groups_users')->insert([
            'user_id'    => $userId,
            'group_id'   => (int) $superGroup['id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $customUsername = 'custom-owner-' . bin2hex(random_bytes(4));
        $db->table('users')->where('id', $userId)->update(['username' => $customUsername]);

        // Rerun sync with updated profile fields
        $this->transport->employeePages[1]['data'][0]['first_name'] = 'UpdatedName';
        $summary = $service->run('employee', false, 10, 10);

        $this->assertTrue($summary['success']);
        $this->assertSame(1, $summary['kinds']['employee']['updated']);

        $refreshed = $db->table('users')->where('id', $userId)->get()->getRowArray();
        $this->assertSame($customUsername, $refreshed['username'], 'Custom username must be preserved');
        $this->assertSame('UpdatedName', $refreshed['first_name'], 'Demographic profile should update');

        $groups = array_column(
            $db->table('auth_groups_users gu')
                ->select('g.name')
                ->join('auth_groups g', 'g.id = gu.group_id')
                ->where('gu.user_id', $userId)
                ->get()
                ->getResultArray(),
            'name',
        );
        $this->assertContains('employee', $groups);
        $this->assertContains('superadmin', $groups, 'Platform Owner role must remain intact across sync');
    }

    public function testSyncCommandSupportsDryRunAndApply(): void
    {
        $stuId = '2026' . random_int(100000, 999999);
        $this->transport->studentPages = [
            1 => [
                'data'         => [['student_id' => $stuId, 'first_name' => 'CommandStu']],
                'current_page' => 1,
                'max_page'     => 1,
            ],
        ];

        $cmd = new MisSync(service('logger'), service('commands'));

        // Default is dry-run
        $code = $cmd->run(['kind' => 'student']);
        $this->assertSame(0, $code);

        $db = Services::database();
        $this->assertSame(0, $db->table('users')->where('student_number', $stuId)->countAllResults());

        // Apply
        $codeApply = $cmd->run(['kind' => 'student', 'apply' => null]);
        $this->assertSame(0, $codeApply);
        $this->assertSame(1, $db->table('users')->where('student_number', $stuId)->countAllResults());
    }

    public function testAdminSyncDirectoryEndpointRequiresRbacManageAndSupportsDryRunAndApply(): void
    {
        $actorStudent = $this->login(['student']);
        $actorAdmin = $this->login(['superadmin']);

        // Non-admin is rejected
        $forbiddenRes = $this->authed($actorStudent['token'], 'post', 'api/v1/admin/users/sync-directory', [
            'kind'    => 'student',
            'dry_run' => true,
        ]);
        $forbiddenRes->assertStatus(403);

        $stuId = '2026' . random_int(100000, 999999);
        $this->transport->studentPages = [
            1 => [
                'data'         => [['student_id' => $stuId, 'first_name' => 'ApiSyncStudent']],
                'current_page' => 1,
                'max_page'     => 1,
            ],
        ];

        // Admin dry-run
        $dryRes = $this->authed($actorAdmin['token'], 'post', 'api/v1/admin/users/sync-directory', [
            'kind'    => 'student',
            'dry_run' => true,
        ]);
        $dryRes->assertStatus(200);
        $dryData = $this->envelope($dryRes)['data'];
        $this->assertTrue($dryData['success']);
        $this->assertTrue($dryData['dry_run']);
        $this->assertSame(1, $dryData['kinds']['student']['created']);

        $db = Services::database();
        $this->assertSame(0, $db->table('users')->where('student_number', $stuId)->countAllResults());

        // Admin apply
        $applyRes = $this->authed($actorAdmin['token'], 'post', 'api/v1/admin/users/sync-directory', [
            'kind'    => 'student',
            'dry_run' => false,
        ]);
        $applyRes->assertStatus(200);
        $applyData = $this->envelope($applyRes)['data'];
        $this->assertTrue($applyData['success']);
        $this->assertFalse($applyData['dry_run']);

        $this->assertSame(1, $db->table('users')->where('student_number', $stuId)->countAllResults());
    }

    public function testMisAutoSyncServiceRespectsCooldownAndDisabledConfig(): void
    {
        $stuId = '2026' . random_int(100000, 999999);
        $this->transport->studentPages = [
            1 => [
                'data'         => [['student_id' => $stuId, 'first_name' => 'AutoSyncStudent']],
                'current_page' => 1,
                'max_page'     => 1,
            ],
        ];

        $config = new \Config\FuMis();
        $config->autoSyncEnabled = true;
        $config->autoSyncCooldownSeconds = 3600;

        $tempCacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synapse_test_cache_' . bin2hex(random_bytes(4));
        @mkdir($tempCacheDir, 0777, true);

        $autoService = new \App\Services\FuMis\MisAutoSyncService($config, $tempCacheDir);

        // First run executes and populates database
        $summary = $autoService->maybeRun(false);
        $this->assertNotNull($summary);
        $this->assertTrue($summary['success']);

        $db = Services::database();
        $this->assertSame(1, $db->table('users')->where('student_number', $stuId)->countAllResults());

        // Second run immediately after is skipped due to cooldown
        $skippedSummary = $autoService->maybeRun(false);
        $this->assertNull($skippedSummary, 'Immediate second run must be blocked by cooldown');

        // Cleanup
        @unlink($tempCacheDir . DIRECTORY_SEPARATOR . 'synapse_mis_sync_at');
        @rmdir($tempCacheDir);
    }

    public function testLoginIsDecoupledFromBulkDirectorySyncAndDoesNotFetchFullList(): void
    {
        $loginStuId = '2026' . random_int(100000, 999999);

        // Clear recorded requests
        $this->transport->recordedUrls = [];
        $this->transport->studentLoginResponse = [
            'status'  => 'success',
            'message' => 'Login successful.',
            'data'    => [
                'data' => [
                    'student_id' => $loginStuId,
                    'first_name' => 'SingleUserLogin',
                    'last_name'  => 'Decoupled',
                    'program'    => 'BSIT',
                    'level'      => '2',
                ],
                'access_token'  => 'mock-tok',
                'refresh_token' => 'mock-ref',
                'expires_at'    => '2026-12-31 23:59:59',
            ],
        ];

        // Execute login for this single user
        $res = $this->withBodyFormat('json')->call('post', 'api/v1/auth/login', [
            'identifier' => $loginStuId,
            'password'   => 'SecretPass123!',
        ]);
        $res->assertStatus(200);

        // Verify only the single login endpoint was queried, NEVER the bulk directory listing endpoints
        $requestedUrls = $this->transport->recordedUrls;
        $this->assertNotEmpty($requestedUrls);

        $bulkDirectoryCalls = array_filter(
            $requestedUrls,
            static fn (string $url): bool => str_contains($url, '/api/v1/students?') || str_contains($url, '/api/v1/employees?'),
        );
        $this->assertSame([], $bulkDirectoryCalls, 'Individual login must NEVER trigger bulk directory list queries');

        $loginCalls = array_filter(
            $requestedUrls,
            static fn (string $url): bool => str_contains($url, '/api/v1/students/login'),
        );
        $this->assertCount(1, $loginCalls, 'Login must only query the specific user login endpoint');
    }
}

final class MultiPageMockMisTransport implements HttpTransport
{
    /** @var array<int, array<string, mixed>> */
    public array $studentPages = [];

    /** @var array<int, array<string, mixed>> */
    public array $employeePages = [];

    /** @var array<string, mixed>|null */
    public ?array $studentLoginResponse = null;

    /** @var list<string> */
    public array $recordedUrls = [];

    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $body = null,
        int $timeoutSeconds = 10,
    ): array {
        $this->recordedUrls[] = $url;

        if (str_contains($url, '/api/v1/students/login')) {
            if ($this->studentLoginResponse !== null) {
                return $this->studentLoginResponse;
            }
            return ['status' => 'error', 'message' => 'Invalid credentials'];
        }
        if (str_contains($url, '/api/tokens/generate')) {
            return [
                'status'  => 'success',
                'message' => 'Token generated',
                'data'    => ['refresh_token' => 'mock-sync-refresh'],
            ];
        }

        if (str_contains($url, '/api/tokens/refresh')) {
            return [
                'status'  => 'success',
                'message' => 'Token refreshed',
                'data'    => [
                    'access_token'  => 'mock-sync-access',
                    'refresh_token' => 'mock-sync-refresh-next',
                    'expires_at'    => '2026-12-31 23:59:59',
                ],
            ];
        }

        if (str_contains($url, '/api/v1/students')) {
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $page = isset($query['page']) ? (int) $query['page'] : 1;

            if (isset($this->studentPages[$page])) {
                return ['status' => 'success', 'data' => $this->studentPages[$page]];
            }

            return [
                'status' => 'success',
                'data'   => [
                    'data'         => [],
                    'current_page' => $page,
                    'max_page'     => 1,
                ],
            ];
        }

        if (str_contains($url, '/api/v1/employees')) {
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $page = isset($query['page']) ? (int) $query['page'] : 1;

            if (isset($this->employeePages[$page])) {
                return ['status' => 'success', 'data' => $this->employeePages[$page]];
            }

            return [
                'status' => 'success',
                'data'   => [
                    'data'         => [],
                    'current_page' => $page,
                    'max_page'     => 1,
                ],
            ];
        }

        return ['status' => 'success', 'data' => []];
    }
}
