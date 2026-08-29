<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\CurrentUser;
use App\Services\CurrentTenant;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Feature\Support\SafeMockCache;

/**
 * Base class for feature tests that drive real routes end-to-end.
 *
 * Unlike the unit suite (which never boots the framework), these tests run
 * the full pipeline: Router → Filters (api_auth, api_ratelimit, cors) →
 * Controller → Service → MySQL. That is the only way to cover the things
 * the unit suite structurally cannot reach — filter behaviour, the RBAC
 * 403 matrix, the response envelope, and the DB-level BMG mass-invariant
 * triggers.
 *
 * Migrations run ONCE per suite rather than per test ($refresh = false):
 * there are 84 of them and ~40 emit raw DDL including 4 stored triggers, so
 * a per-test refresh would dominate the runtime. Tests must therefore leave
 * the database usable for the next case — create your own rows with unique
 * identifiers (see uniqueEmail()) instead of assuming an empty table.
 */
abstract class FeatureTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait {
        // call() is overridden below to render controller exceptions as
        // the production envelope. `parent::call()` cannot reach the trait
        // (traits are not in the parent hierarchy), so alias it instead.
        call as traitCall;
    }

    /**
     * Run every migration once for the suite.
     */
    protected $migrate = true;

    /**
     * Do NOT re-migrate between tests — see the class docblock.
     */
    protected $refresh = false;

    /**
     * Migrations live in the app namespace, not a module.
     */
    protected $namespace = 'App';

    /**
     * Permissions and groups are a precondition for every authorization
     * assertion, so seed the RBAC tables for the suite.
     */
    protected $seed = 'App\Database\Seeds\PermissionsAndGroupsSeeder';

    /**
     * Password used for every account this suite creates. Not a shared
     * fixture credential — each test makes its own throwaway user.
     */
    protected const TEST_PASSWORD = 'FeatureTestPassw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        // The rate-limit filter increments a per-window counter through
        // Services::cache() on every request. The framework's MockCache
        // throws on the first increment of any key (see SafeMockCache), so
        // swap in the fixed double — otherwise every feature request would
        // error before reaching the controller.
        Services::injectMock('cache', new SafeMockCache());

        // Both are process-wide statics. Left dirty, they leak across
        // tests (and, in a persistent runtime, across requests) — the same
        // hazard tests/unit/BmgTenantFilterTest.php guards against.
        CurrentUser::forget();
        CurrentTenant::reset();
    }

    protected function tearDown(): void
    {
        Services::resetSingle('cache');
        CurrentUser::forget();
        CurrentTenant::reset();
        $_COOKIE = [];
        service('superglobals')->setGlobalArray('cookie', []);

        parent::tearDown();
    }

    /**
     * Collision-proof email for a throwaway account. The suite does not
     * truncate between tests, and `auth_identities.secret` is unique.
     */
    protected function uniqueEmail(string $prefix = 'user'): string
    {
        return sprintf('%s+%s@feature.test', $prefix, bin2hex(random_bytes(6)));
    }

    /**
     * Render an exception the way production does.
     *
     * CodeIgniter's `run()` catch block re-throws every non-404 exception
     * (system/CodeIgniter.php); in production PHP's global handler then
     * routes it to ApiExceptionHandler::handle(), which renders the
     * envelope and `exit()`s. FeatureTestTrait calls run() directly, so
     * the exception unwinds into PHPUnit instead — every controller-level
     * ApiException would surface as a raw test error and the envelope
     * contract would be untestable. Catch it here and render through the
     * app's REAL mapping (ApiExceptionHandler::renderToResponse) so the
     * tests exercise the same envelope rules production applies.
     */
    public function call(string $method, string $path, ?array $params = null)
    {
        try {
            return $this->traitCall($method, $path, $params);
        } catch (\App\Exceptions\ApiException $e) {
            $response = (new \App\Exceptions\ApiExceptionHandler(config('Exceptions')))
                ->renderToResponse($e, service('response'));

            return new \CodeIgniter\Test\TestResponse($response);
        }
    }

    /**
     * Send a request cookie.
     *
     * FeatureTestTrait::withHeaders() sets headers on an IncomingRequest
     * whose cookie store was ALREADY built from $_COOKIE at construction,
     * so a `Cookie:` header arrives too late and $request->getCookie()
     * stays empty. Seeding the superglobal before call() is the only
     * delivery path the framework honours here.
     */
    private function withRequestCookie(string $name, string $value): self
    {
        $_COOKIE[$name] = $value;
        // CI4 4.7 reads superglobals through the Superglobals service
        // singleton, which holds its own snapshot — mutating $_COOKIE
        // alone never reaches the request's cookie store.
        service('superglobals')->setGlobalArray('cookie', $_COOKIE);

        return $this;
    }

    /**
     * Send a request cookie for the next `call()`. The superglobal is
     * what IncomingRequest's cookie store reads at construction — see
     * withRequestCookie() for why headers don't work.
     */
    protected function withCookies(array $cookies): self
    {
        foreach ($cookies as $name => $value) {
            $this->withRequestCookie((string) $name, (string) $value);
        }

        return $this;
    }

    /**
     * Create an active user with an email/password identity.
     *
     * @param list<string> $groups Shield group names, e.g. ['clinic_staff'].
     * @return array{id:int, email:string, password:string}
     */
    protected function createUser(array $groups = [], ?string $email = null, bool $forceReset = false): array
    {
        $db    = db_connect();
        $now   = date('Y-m-d H:i:s');
        $email ??= $this->uniqueEmail();

        $db->table('users')->insert([
            'username'   => 'ft-' . bin2hex(random_bytes(5)),
            'status'     => 'active',
            'active'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) $db->insertID();

        // `force_reset` lives on the identity row, not on users — this is
        // what AccountStateService reads (`COALESCE(i.force_reset, 0)`).
        $db->table('auth_identities')->insert([
            'user_id'     => $userId,
            'type'        => 'email_password',
            'secret'      => $email,
            'secret2'     => password_hash(self::TEST_PASSWORD, PASSWORD_DEFAULT),
            'force_reset' => $forceReset ? 1 : 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        foreach ($groups as $groupName) {
            $group = $db->table('auth_groups')->where('name', $groupName)->get()->getRowArray();
            if ($group === null) {
                $this->fail(sprintf(
                    'Group "%s" does not exist. Is PermissionsAndGroupsSeeder seeded, and is the name spelled as in app/Config/AuthGroups.php?',
                    $groupName,
                ));
            }
            $db->table('auth_groups_users')->insert([
                'group_id'   => (int) $group['id'],
                'user_id'    => $userId,
                'created_at' => $now,
            ]);
        }

        return ['id' => $userId, 'email' => $email, 'password' => self::TEST_PASSWORD];
    }

    /**
     * Log in over the real `POST /api/v1/auth/login` route and return the
     * access token. Goes through the actual controller rather than minting
     * a JWT directly, so the login path itself stays covered.
     *
     * @return array{token:string, userId:int, email:string}
     */
    protected function login(array $groups = [], ?string $email = null): array
    {
        $user = $this->createUser($groups, $email);

        $result = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['email' => $user['email'], 'password' => $user['password']],
        );

        $result->assertStatus(200);
        $body = json_decode($result->getJSON() ?: '{}', true);

        $token = $body['data']['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            $this->fail('Login did not return data.access_token. Body: ' . (string) $result->getJSON());
        }

        return ['token' => $token, 'userId' => $user['id'], 'email' => $user['email']];
    }

    /**
     * Perform an authenticated request through the full filter pipeline.
     *
     * @param array<string, mixed>|null $body
     */
    protected function authed(
        string $token,
        string $method,
        string $route,
        ?array $body = null,
    ): \CodeIgniter\Test\TestResponse {
        $test = $this->withHeaders(['Authorization' => 'Bearer ' . $token]);

        if ($body !== null) {
            return $test->withBodyFormat('json')->call($method, $route, $body);
        }

        return $test->call($method, $route);
    }

    /**
     * Decode the canonical `{success, data, errors, meta}` envelope.
     *
     * @return array<string, mixed>
     */
    protected function envelope(\CodeIgniter\Test\TestResponse $response): array
    {
        $decoded = json_decode($response->getJSON() ?: 'null', true);

        $this->assertIsArray($decoded, 'Response body was not a JSON object: ' . (string) $response->getJSON());
        $this->assertArrayHasKey('success', $decoded, 'Response is missing the `success` envelope key.');
        $this->assertArrayHasKey('data', $decoded, 'Response is missing the `data` envelope key.');
        $this->assertArrayHasKey('errors', $decoded, 'Response is missing the `errors` envelope key.');

        return $decoded;
    }

    /**
     * Assert the response is a failure envelope carrying `$code`.
     */
    protected function assertErrorCode(string $code, \CodeIgniter\Test\TestResponse $response): void
    {
        $body = $this->envelope($response);

        $this->assertFalse($body['success'], 'Expected a failure envelope.');
        $this->assertIsArray($body['errors'], 'Failure envelope must carry an `errors` array.');

        $codes = array_column($body['errors'], 'code');
        $this->assertContains($code, $codes, sprintf(
            'Expected error code "%s", got [%s].',
            $code,
            implode(', ', $codes),
        ));
    }
}
