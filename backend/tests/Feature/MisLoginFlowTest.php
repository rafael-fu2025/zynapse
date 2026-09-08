<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FuMis\FuMisException;
use App\Services\FuMis\HttpTransport;
use Config\Services;

/**
 * End-to-end integration test for the MIS-delegated login flow.
 *
 * Exercises the entire pipeline under FUMIS_ENABLED=true:
 *   - student-number login → MIS studentLogin called → JIT users row
 *     created with kind='student' and group='student' → JWT issued with
 *     new token epoch;
 *   - employee-number login → studentLogin 401s, employeeLogin called →
 *     JIT row with kind='employee' and is_teaching preserved;
 *   - wrong password on both namespaces → 401 auth.credentials_invalid +
 *     throttle registered;
 *   - MIS transport down (off-campus / timeout) → 503 auth.mis_unavailable;
 *   - /auth/me returns the university identifier;
 *   - ambiguous payload (both email AND identifier) → 422;
 *   - local email login still works alongside MIS for admin accounts.
 *
 * Uses an in-memory HttpTransport injected into Services::injectMock so
 * the tests never hit the real university network.
 */
final class MisLoginFlowTest extends FeatureTestCase
{
    private TestTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any shared service instances left by prior feature tests.
        Services::resetSingle('fuMisAuthService');
        Services::resetSingle('fuMisClient');
        Services::resetSingle('fuMisTransport');

        $this->transport = new TestTransport();
        Services::injectMock('fuMisTransport', $this->transport);
        // Force FUMIS_ENABLED=true for this suite.
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

    public function testStudentLoginJitProvisionsLocalUserAndIssuesJwt(): void
    {
        $this->transport->studentResponse = [
            'status'  => 'success',
            'message' => 'Login successful.',
            'data'    => [
                'data' => [
                    'student_id'  => '20261111',
                    'first_name'  => 'Carlos',
                    'last_name'   => 'Perez',
                    'program'     => 'BSIT',
                    'level'       => '3',
                    'department'  => 'CCS',
                    'section'     => 'Block A',
                ],
                'access_token'  => 'mis-at-1',
                'refresh_token' => 'mis-rt-1',
                'expires_at'    => '2026-09-08 18:00:00',
            ],
        ];

        $res = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['identifier' => '20261111', 'password' => 'MisSecretPass1!'],
        );

        $res->assertStatus(200);
        $body = $this->envelope($res);
        $token = $body['data']['access_token'] ?? null;
        $this->assertIsString($token);

        // Verify local JIT row.
        $db = \Config\Services::database();
        $user = $db->table('users')->where('student_number', '20261111')->get()->getRowArray();
        $this->assertNotNull($user, 'Local user row must be provisioned on first login.');
        $this->assertSame('Carlos', $user['first_name']);
        $this->assertSame('Perez', $user['last_name']);
        $this->assertSame('BSIT', $user['course']);
        $this->assertSame(3, (int) $user['year_level']);
        $this->assertSame('student', $user['kind']);

        // Group assigned.
        $group = $db->table('auth_groups_users agu')
            ->join('auth_groups ag', 'ag.id = agu.group_id')
            ->where('agu.user_id', (int) $user['id'])
            ->where('ag.name', 'student')
            ->get()->getRowArray();
        $this->assertNotNull($group, 'student group must be assigned.');

        // /auth/me returns the identifier.
        $me = $this->authed($token, 'get', 'api/v1/auth/me');
        $me->assertStatus(200);
        $meBody = $this->envelope($me);
        $this->assertSame('20261111', $meBody['data']['identifier']);
        $this->assertSame('student', $meBody['data']['person_kind']);
    }

    public function testEmployeeLoginFallsBackFromStudentNamespace(): void
    {
        // Student login returns 401 (not a student).
        $this->transport->studentError = new FuMisException('invalid', 401);

        $this->transport->employeeResponse = [
            'data' => [
                'employee_id'       => '20269099',
                'first_name'        => 'Elena',
                'last_name'         => 'Reyes',
                'department'        => 'College of Computer Studies',
                'position'          => 'Dean',
                'employment_status' => 'active',
                'is_teaching'       => true,
            ],
            'access_token'  => 'mis-at-emp',
            'refresh_token' => 'mis-rt-emp',
            'expires_at'    => '2026-09-08T18:00:00+08:00',
        ];

        $res = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['identifier' => '20269099', 'password' => 'DeanPassword!'],
        );

        $res->assertStatus(200);
        $body = $this->envelope($res);
        $token = $body['data']['access_token'] ?? null;
        $this->assertIsString($token);

        $db = \Config\Services::database();
        $user = $db->table('users')->where('employee_number', '20269099')->get()->getRowArray();
        $this->assertNotNull($user);
        $this->assertSame('employee', $user['kind']);
        $this->assertSame(1, (int) $user['is_teaching']);

        $me = $this->authed($token, 'get', 'api/v1/auth/me');
        $me->assertStatus(200);
        $meBody = $this->envelope($me);
        $this->assertSame('20269099', $meBody['data']['identifier']);
        $this->assertTrue($meBody['data']['is_teaching']);
    }

    public function testWrongCredentialsInBothNamespacesReturns401(): void
    {
        $this->transport->studentError  = new FuMisException('invalid', 401);
        $this->transport->employeeError = new FuMisException('invalid', 401);

        $res = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['identifier' => '00000000', 'password' => 'WrongWrong!'],
        );

        $res->assertStatus(401);
        $this->assertErrorCode('auth.credentials_invalid', $res);
    }

    public function testMisTransportFailureReturns503(): void
    {
        // Off-campus / timeout failure (upstreamStatus = 0).
        $this->transport->studentError = new FuMisException('fumis.transport', 0);

        $res = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['identifier' => '20261234', 'password' => 'AnyPassword!'],
        );

        $res->assertStatus(503);
        $this->assertErrorCode('auth.mis_unavailable', $res);
    }

    public function testProvidingBothEmailAndIdentifierIsRejected(): void
    {
        $res = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['identifier' => '20261234', 'email' => 'admin@example.test', 'password' => 'AnyPass1!'],
        );

        $res->assertStatus(422);
    }

    public function testLocalAdminEmailLoginStillWorksUnderFumis(): void
    {
        $email = $this->uniqueEmail('admin-mis');
        $this->createUser(['admin'], $email);

        $res = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['email' => $email, 'password' => self::TEST_PASSWORD],
        );

        $res->assertStatus(200);
        $body = $this->envelope($res);
        $this->assertIsString($body['data']['access_token'] ?? null);
    }
}

/**
 * Feature test transport double — injects directly into Services.
 */
final class TestTransport implements HttpTransport
{
    public ?array $studentResponse = null;
    public ?FuMisException $studentError = null;

    public ?array $employeeResponse = null;
    public ?FuMisException $employeeError = null;

    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $body = null,
        int $timeoutSeconds = 10,
    ): array {
        if (str_contains($url, '/students/login')) {
            if ($this->studentError !== null) {
                throw $this->studentError;
            }
            return $this->studentResponse ?? ['data' => [], 'access_token' => 'x', 'refresh_token' => 'x', 'expires_at' => 'x'];
        }

        if (str_contains($url, '/employees/login')) {
            if ($this->employeeError !== null) {
                throw $this->employeeError;
            }
            return $this->employeeResponse ?? ['data' => [], 'access_token' => 'x', 'refresh_token' => 'x', 'expires_at' => 'x'];
        }

        return [];
    }
}
