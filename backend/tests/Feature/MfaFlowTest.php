<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Mfa\MfaService;
use App\Auth\Totp\TotpService;
use App\Services\CurrentTenant;

/**
 * End-to-end TOTP MFA for the LOCAL identity path (2026-10 audit G-1).
 *
 * TOTP notes that shape this file:
 *   - A timestep is single-use (replay rejection): ENROLLMENT consumes the
 *     confirming step, so the first login code must come from the NEXT
 *     step (the ±1 drift window accepts it while "now" is still inside
 *     the enrolling step).
 *   - Two consecutive VALID codes cannot be accepted within one 30s step;
 *     the disable test therefore waits for the next window before its
 *     final attempt — exactly what a real user does.
 *
 * The harness shares one Response object across calls inside a test, so
 * cookie assertions are only meaningful on the FIRST HTTP call of a test
 * (test 1 enrolls via the service, not HTTP, for that reason).
 */
final class MfaFlowTest extends FeatureTestCase
{
    private TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new TotpService();
    }

    /** Current-step code for a secret, with drift. */
    private function code(string $secret, int $drift = 0): string
    {
        return $this->totp->codeAt($secret, intdiv(time(), TotpService::STEP_SECONDS) + $drift);
    }

    /** Seconds until the next TOTP window (plus 1s safety). */
    private function secondsToNextWindow(): int
    {
        return TotpService::STEP_SECONDS - (time() % TotpService::STEP_SECONDS) + 1;
    }

    /**
     * Enroll via the SERVICE (no HTTP login) so the test's first HTTP
     * response is the MFA challenge itself — clean cookie assertions.
     * Confirms with a valid code, so enabled_at is set.
     */
    private function enrollDirect(int $userId, string $email): string
    {
        $service = new MfaService();
        $enrollment = $service->setup($userId, ['email' => $email]);

        $this->assertTrue(
            $service->confirm($userId, $this->code($enrollment['secret'])),
            'Enrollment confirmation code must verify',
        );

        return $enrollment['secret'];
    }

    /** @return array{status:int, mfa_token:?string, body:array<string, mixed>, set_refresh_cookie:?string} */
    private function loginStep(string $email): array
    {
        $res = $this->withBodyFormat('json')->call('post', 'api/v1/auth/login', [
            'email' => $email,
            'password' => $this->testPassword(),
        ]);
        $body = json_decode((string) $res->getJSON(), true) ?: [];
        $cookie = $res->response()->getCookie('synapse_rt');

        return [
            'status'            => (int) $res->response()->getStatusCode(),
            'mfa_token'         => $body['data']['mfa_token'] ?? null,
            'body'              => $body,
            'set_refresh_cookie' => $cookie instanceof \CodeIgniter\Cookie\Cookie ? (string) $cookie->getValue() : null,
        ];
    }

    /**
     * The suite-wide shared Response cookie store's synapse_rt value
     * (string, or null when none). Compared across a call to prove that
     * the call did not touch the cookie.
     */
    private function currentResponseCookie(): ?string
    {
        $cookie = service('response')->getCookie('synapse_rt');

        return $cookie instanceof \CodeIgniter\Cookie\Cookie ? (string) $cookie->getValue() : null;
    }

    public function testEnrolledLoginRequiresCodeAndVerifyLoginCompletesIt(): void
    {
        $email = $this->uniqueEmail('mfa');
        $user = $this->createUser(['student'], $email);
        $secret = $this->enrollDirect((int) $user['id'], $email);

        // First HTTP call of this test: the MFA challenge itself. The
        // harness shares one Response cookie store across the whole suite,
        // so "no cookie" is proven by the store being UNCHANGED by this
        // call (a set cookie would rotate the value).
        $cookieBefore = $this->currentResponseCookie();
        $first = $this->loginStep($email);
        $this->assertSame(401, $first['status']);
        $this->assertSame('auth.mfa_required', $first['body']['errors'][0]['code'] ?? null);
        $this->assertIsString($first['mfa_token']);
        $this->assertSame($cookieBefore, $this->currentResponseCookie(), 'The MFA challenge step must not set a refresh cookie.');

        // The challenge token is NOT an access token.
        $this->authed((string) $first['mfa_token'], 'get', 'api/v1/auth/me')->assertStatus(401);

        // Wrong code → 401 mfa_invalid.
        $this->withBodyFormat('json')->call('post', 'api/v1/auth/mfa/verify-login', [
            'mfa_token' => $first['mfa_token'],
            'code'      => '000000',
        ])->assertStatus(401);

        // Correct code (next step — enrollment consumed the current one) →
        // full session.
        $done = $this->withBodyFormat('json')->call('post', 'api/v1/auth/mfa/verify-login', [
            'mfa_token' => $first['mfa_token'],
            'code'      => $this->code($secret, 1),
        ]);
        $done->assertStatus(200);
        $this->assertIsString($this->envelope($done)['data']['access_token'] ?? null);
    }

    public function testCodeReplayIsRejected(): void
    {
        $email = $this->uniqueEmail('mfareplay');
        $user = $this->createUser(['student'], $email);
        $secret = $this->enrollDirect((int) $user['id'], $email);

        $first = $this->loginStep($email);
        $this->assertSame(401, $first['status']);

        // Consume one code successfully.
        $ok = $this->withBodyFormat('json')->call('post', 'api/v1/auth/mfa/verify-login', [
            'mfa_token' => $first['mfa_token'],
            'code'      => $this->code($secret, 1),
        ]);
        $ok->assertStatus(200);

        // The SAME code against a fresh challenge: rejected (timestep used).
        $second = $this->loginStep($email);
        $this->assertSame(401, $second['status']);
        $replay = $this->withBodyFormat('json')->call('post', 'api/v1/auth/mfa/verify-login', [
            'mfa_token' => $second['mfa_token'],
            'code'      => $this->code($secret, 1),
        ]);
        $replay->assertStatus(401);
    }

    public function testPrivilegedUserWithoutEnrollmentIsGatedByDefault(): void
    {
        // The suite pins enforcement OFF for its admin logins; this test
        // asserts the production default, so it opts enforcement back on.
        putenv('MFA_ENFORCE_FOR_PRIVILEGED=true');
        try {
            $email = $this->uniqueEmail('mfaprov');
            $this->createUser(['clinic_admin'], $email); // privileged group, no enrollment

            $first = $this->loginStep($email);
            $this->assertSame(401, $first['status']);
            $this->assertSame('auth.mfa_required', $first['body']['errors'][0]['code'] ?? null);

            // Without an enrollment there is nothing to verify against: the
            // challenge cannot be completed — an operator must enroll first
            // (reset via CLI, then setup + confirm).
            $this->withBodyFormat('json')->call('post', 'api/v1/auth/mfa/verify-login', [
                'mfa_token' => $first['mfa_token'],
                'code'      => '123456',
            ])->assertStatus(401);
        } finally {
            putenv('MFA_ENFORCE_FOR_PRIVILEGED=0');
        }
    }

    public function testOrdinaryUnenrolledUserLogsInUnchanged(): void
    {
        $email = $this->uniqueEmail('mfaplain');
        $this->createUser(['student'], $email);

        $first = $this->loginStep($email);
        $this->assertSame(200, $first['status']);
        $this->assertNull($first['body']['errors'][0]['code'] ?? null);
    }

    public function testDisableRequiresBothFactorsAndAdminResetNeedsPrivilegedManage(): void
    {
        $email = $this->uniqueEmail('mfadis');
        $session = $this->login(['student'], $email);

        // Enroll over HTTP like a real user would.
        $setup = $this->authed($session['token'], 'post', 'api/v1/auth/mfa/setup');
        $setup->assertStatus(200);
        $secret = (string) $this->envelope($setup)['data']['secret'];
        $this->authed($session['token'], 'post', 'api/v1/auth/mfa/confirm', [
            'code' => $this->code($secret),
        ])->assertStatus(200);

        // Invalid code + right password → 401 (no timestep consumed).
        $this->authed($session['token'], 'post', 'api/v1/auth/mfa/disable', [
            'code' => '000000', 'current_password' => $this->testPassword(),
        ])->assertStatus(401);

        // Valid code + wrong password → 401 (timestep consumed).
        $this->authed($session['token'], 'post', 'api/v1/auth/mfa/disable', [
            'code' => $this->code($secret, 1), 'current_password' => 'WrongPassword999!',
        ])->assertStatus(401);

        // Both factors — with a code from the step AFTER the one the
        // attempt above consumed (real users wait for the next code; so
        // does the test). After the sleep, "now" sits in the consumed
        // step, so the acceptable fresh step is drift +1.
        sleep($this->secondsToNextWindow());
        $this->authed($session['token'], 'post', 'api/v1/auth/mfa/disable', [
            'code' => $this->code($secret, 1), 'current_password' => $this->testPassword(),
        ])->assertStatus(200);

        // Disabled → next login needs no code.
        $plain = $this->loginStep($email);
        $this->assertSame(200, $plain['status']);

        // Admin reset is privileged-only.
        $this->authed($session['token'], 'post', 'api/v1/auth/mfa/reset/' . $session['userId'], [])->assertStatus(403);
    }
}
