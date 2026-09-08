<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\TestResponse;

/**
 * End-to-end coverage of the token lifecycle: login, refresh rotation,
 * replay detection, and logout revocation — driven through the real
 * routes, filters, and the HMAC-hashed `auth_refresh_tokens` table.
 *
 * The rotation chain this file pins down (see RefreshTokenService):
 *   login            → family minted, cookie `synapse_rt` set
 *   refresh #1 (old) → 'rotated': new pair minted, old hash marked replaced
 *   refresh #2 (old) → 'replayed': REPLAY DETECTED, whole family revoked
 *   refresh #3 (new) → also rejected — the family revocation killed it
 *
 * That last step is the invariant the replay-detection design exists to
 * guarantee, and it is only observable end-to-end: the unit suite never
 * boots the kernel.
 */
final class AuthenticationFlowTest extends FeatureTestCase
{
    private const COOKIE_NAME = 'synapse_rt';

    /**
     * Login over the real route and return both tokens plus the response,
     * so callers can assert on Set-Cookie themselves.
     *
     * @return array{token:string, refresh:string, userId:int, response:TestResponse}
     */
    private function loginFull(?string $email = null): array
    {
        $email ??= $this->uniqueEmail('auth');
        $user = $this->createUser(['admin'], $email);

        $session = $this->loginFor($email);
        $session['userId'] = $user['id'];

        return $session;
    }

    /**
     * Log in over the real route WITHOUT creating a user — for tests that
     * need several sessions on one pre-created account.
     *
     * @return array{token:string, refresh:string, userId:int, response:TestResponse}
     */
    private function loginFor(string $email): array
    {
        $result = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['email' => $email, 'password' => self::TEST_PASSWORD],
        );

        $result->assertStatus(200);
        $result->assertCookie(self::COOKIE_NAME);

        $body    = $this->envelope($result);
        $token   = $body['data']['access_token'] ?? null;
        $refresh = $this->refreshCookieValue($result);

        $this->assertIsString($token, 'Login response must carry data.access_token.');
        $this->assertNotSame('', $refresh, 'Login must set the refresh cookie with a value.');

        return ['token' => $token, 'refresh' => $refresh, 'userId' => 0, 'response' => $result];
    }

    private function refreshCookieValue(TestResponse $result): string
    {
        // Response::getCookie() returns a Cookie OBJECT from the response
        // cookie store (not the legacy array).
        $cookie = $result->response()->getCookie(self::COOKIE_NAME);

        if ($cookie instanceof \CodeIgniter\Cookie\Cookie) {
            return (string) $cookie->getValue();
        }
        if (is_array($cookie)) {
            return (string) ($cookie['value'] ?? '');
        }

        return '';
    }

    private function refreshWith(string $cookieValue): TestResponse
    {
        return $this->withCookies([self::COOKIE_NAME => $cookieValue])
            ->call('post', 'api/v1/auth/refresh');
    }

    public function testLoginRejectsMalformedPayloadWithValidationEnvelope(): void
    {
        $result = $this->withBodyFormat('json')->call('post', 'api/v1/auth/login', ['email' => 'not-an-email']);

        $result->assertStatus(422);
        $body = $this->envelope($result);
        $this->assertFalse($body['success']);
        $this->assertIsArray($body['errors']);
        $this->assertNotEmpty($body['errors']);
    }

    public function testLoginRejectsWrongPasswordWithCredentialsInvalid(): void
    {
        $email = $this->uniqueEmail('auth');
        $this->createUser(['student'], $email);

        $result = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['email' => $email, 'password' => 'WrongPassword999!'],
        );

        $result->assertStatus(401);
        $this->assertErrorCode('auth.credentials_invalid', $result);
    }

    public function testLoginSetsAccessAndRefreshTokens(): void
    {
        $session = $this->loginFull();

        $body = $this->envelope($session['response']);
        $this->assertSame('Bearer', $body['data']['token_type'] ?? null);
        $this->assertGreaterThan(0, (int) ($body['data']['expires_in'] ?? 0));

        $cookie = $session['response']->response()->getCookie(self::COOKIE_NAME);
        $this->assertInstanceOf(\CodeIgniter\Cookie\Cookie::class, $cookie);
        $this->assertSame('/', $cookie->getPath());
        $this->assertTrue($cookie->isHTTPOnly());
    }

    public function testRefreshWithoutCookieIsRejected(): void
    {
        $result = $this->call('post', 'api/v1/auth/refresh');

        $result->assertStatus(401);
        $this->assertErrorCode('auth.refresh_missing', $result);
    }

    public function testRefreshWithUnknownTokenIsRejected(): void
    {
        $result = $this->refreshWith('never-minted-token-value');

        $result->assertStatus(401);
        $this->assertErrorCode('auth.refresh_invalid_or_replayed', $result);
    }

    public function testRefreshRotatesAndDetectsReplayRevokingTheWholeFamily(): void
    {
        $session = $this->loginFull();
        $oldRefresh = $session['refresh'];

        // 1. First refresh: rotated — new pair minted in the same family.
        $rotated = $this->refreshWith($oldRefresh);
        $rotated->assertStatus(200);
        $rotatedBody = $this->envelope($rotated);
        $newToken = $rotatedBody['data']['access_token'] ?? null;
        $newRefresh = $this->refreshCookieValue($rotated);
        $this->assertIsString($newToken, 'Rotation must mint a new access token.');
        $this->assertNotSame('', $newRefresh, 'Rotation must set a new refresh cookie.');
        $this->assertNotSame($oldRefresh, $newRefresh, 'Rotation must issue a fresh refresh token, not re-issue the old one.');

        // 2. Replaying the OLD token: replay detected, family revoked.
        $replay = $this->refreshWith($oldRefresh);
        $replay->assertStatus(401);
        $this->assertErrorCode('auth.refresh_invalid_or_replayed', $replay);

        // 3. The replayed use must have killed the whole family — the NEW
        //    token minted in step 1 is also dead now.
        $afterReplay = $this->refreshWith($newRefresh);
        $afterReplay->assertStatus(401);
        $this->assertErrorCode('auth.refresh_invalid_or_replayed', $afterReplay);

        // 4. The replayed use also killed the rotated access token: replay
        //    revocation advances the user's token epoch, so every bearer
        //    token issued before the replay dies immediately (not at exp).
        $me = $this->authed($newToken, 'get', 'api/v1/auth/me');
        $me->assertStatus(401);
        $this->assertErrorCode('auth.token_revoked', $me);
    }

    public function testLogoutRevokesTheRefreshFamily(): void
    {
        $session = $this->loginFull();

        $logout = $this->authed($session['token'], 'post', 'api/v1/auth/logout');
        $logout->assertStatus(200);
        $this->assertTrue($this->envelope($logout)['data']['logged_out'] ?? false);

        // The refresh family AND the bearer token both die at the server:
        // logout revokes the family and advances the token epoch, so the
        // stolen-token window after logout is zero, not exp.
        $after = $this->refreshWith($session['refresh']);
        $after->assertStatus(401);
        $this->assertErrorCode('auth.refresh_invalid_or_replayed', $after);

        $me = $this->authed($session['token'], 'get', 'api/v1/auth/me');
        $me->assertStatus(401);
        $this->assertErrorCode('auth.token_revoked', $me);
    }

    /**
     * A bearer token issued before the epoch column existed carries no
     * `epoch` claim; while the user's epoch is still 0 it must keep
     * working (deployment compatibility — no forced re-login on migrate).
     *
     * The claim is overwritten to null, which encodes as JSON null; the
     * filter's (int) cast reads null as 0, exactly like a missing claim.
     */
    public function testLegacyTokenWithoutEpochClaimStillAuthenticatesAtEpochZero(): void
    {
        $session = $this->loginFull();
        $userId = (int) $session['userId'];

        $legacy = \Config\Services::jwt()->sign($userId, 0, ['epoch' => null]);

        $me = $this->authed($legacy, 'get', 'api/v1/auth/me');
        $me->assertStatus(200);
    }

    public function testChangePasswordKillsOtherSessions(): void
    {
        $email = $this->uniqueEmail('auth');
        $this->createUser(['student'], $email);

        // Two clients hold independent sessions on the SAME account.
        $clientA = $this->loginFor($email);
        $clientB = $this->loginFor($email);

        // Client A changes its password.
        $change = $this->authed($clientA['token'], 'post', 'api/v1/auth/change-password', [
            'current_password' => self::TEST_PASSWORD,
            'new_password'     => self::TEST_PASSWORD . 'X',
        ]);
        $change->assertStatus(200);
        $changeBody = $this->envelope($change);
        $this->assertIsString($changeBody['data']['access_token'] ?? null, 'Password change must return a fresh access token.');

        // Client B's access token is dead: the epoch check rejects it.
        $meB = $this->authed($clientB['token'], 'get', 'api/v1/auth/me');
        $meB->assertStatus(401);
        $this->assertErrorCode('auth.token_revoked', $meB);

        // Client A's fresh token from the change response still works.
        // (Assert this BEFORE replaying B's refresh cookie: replay
        // detection bumps the epoch again — by design — and would kill
        // A's token too.)
        $meA = $this->authed((string) $changeBody['data']['access_token'], 'get', 'api/v1/auth/me');
        $meA->assertStatus(200);

        // B's refresh family was revoked by the password change; replaying
        // it now triggers replay detection (and a further epoch bump).
        $refreshB = $this->refreshWith($clientB['refresh']);
        $refreshB->assertStatus(401);
        $this->assertErrorCode('auth.refresh_invalid_or_replayed', $refreshB);
    }
}
