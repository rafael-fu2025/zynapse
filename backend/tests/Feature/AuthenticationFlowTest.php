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
 *   refresh #2 (old) → within GRACE_WINDOW_SECONDS: treated as a tab race,
 *                      the SAME family is returned and the replacement stays
 *                      live — two concurrent tabs must not destroy each other
 *   refresh #2 (old) → outside the grace window: 'replayed', whole family
 *                      revoked and the user's token epoch advanced
 *   refresh #3 (new) → also rejected — the family revocation killed it
 *
 * That last step is the invariant the replay-detection design exists to
 * guarantee, and it is only observable end-to-end: the unit suite never
 * boots the kernel.
 *
 * NOTE: the grace-window path and the genuine-replay path are covered by two
 * separate tests. An earlier single test replayed the old token immediately and
 * asserted 401; it predated the grace window and had been failing since.
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
        $user = $this->createUser(['clinic_admin'], $email);

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
            ['email' => $email, 'password' => $this->testPassword()],
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

    /**
     * A replay arriving within GRACE_WINDOW_SECONDS of the rotation is treated
     * as a cross-tab race rather than a replay. Two browser tabs refreshing
     * concurrently present the same token; without a grace window the second
     * would revoke the family and destroy the first tab's session.
     *
     * So the old token is exchanged for the SAME family and the replacement
     * minted by the first refresh is left live.
     */
    public function testReplayWithinGraceWindowIsTreatedAsARace(): void
    {
        $session = $this->loginFull();
        $oldRefresh = $session['refresh'];

        $rotated = $this->refreshWith($oldRefresh);
        $rotated->assertStatus(200);
        $newRefresh = $this->refreshCookieValue($rotated);
        $this->assertNotSame($oldRefresh, $newRefresh, 'Rotation must issue a fresh refresh token.');

        // Present the OLD token again immediately — inside the grace window.
        $race = $this->refreshWith($oldRefresh);
        $race->assertStatus(200);

        // The family survived: the replacement from step 1 is still usable.
        $after = $this->refreshWith($newRefresh);
        $after->assertStatus(200);
    }

    /**
     * The invariant the replay-detection design exists to guarantee. Once the
     * grace window has elapsed, presenting an already-rotated token is a
     * genuine replay: the whole family is revoked AND the user's token epoch
     * advances, so every outstanding access token dies immediately rather than
     * at expiry.
     */
    public function testGenuineReplayAfterGraceWindowRevokesTheWholeFamily(): void
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

        // 2. Backdate the rotation so the next use of the old token falls
        //    OUTSIDE the grace window and is unambiguously a replay. Backdating
        //    is used rather than sleeping, so the test stays fast and does not
        //    depend on wall-clock timing.
        $grace = 5; // RefreshTokenService::GRACE_WINDOW_SECONDS
        \Config\Database::connect()
            ->table('auth_refresh_tokens')
            ->where('user_id', $session['userId'])
            ->where('replaced_by_hash IS NOT NULL', null, false)
            ->update(['revoked_at' => date('Y-m-d H:i:s', time() - $grace - 5)]);

        // 3. Replaying the OLD token: replay detected, family revoked.
        $replay = $this->refreshWith($oldRefresh);
        $replay->assertStatus(401);
        $this->assertErrorCode('auth.refresh_invalid_or_replayed', $replay);

        // 4. The replayed use must have killed the whole family — the NEW
        //    token minted in step 1 is also dead now.
        $afterReplay = $this->refreshWith($newRefresh);
        $afterReplay->assertStatus(401);
        $this->assertErrorCode('auth.refresh_invalid_or_replayed', $afterReplay);

        // 5. The replayed use also killed the rotated access token: replay
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
            'current_password' => $this->testPassword(),
            'new_password'     => $this->testPassword() . 'X',
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

    /**
     * MIS-provisioned shape: a users row with a student_number and NO
     * email_password identity (exactly what FuMisAuthService JIT-creates).
     * Minting a token directly keeps the test off the campus-only MIS API.
     */
    private function misProvisionedUser(string $studentNumber): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        // Unique per run — the feature bootstrap recreates the schema
        // only when missing, so fixed usernames AND student numbers
        // collide across runs (both carry unique keys).
        $unique = bin2hex(random_bytes(4));

        $db->table('users')->insert([
            'username'       => 'stu-' . $studentNumber . '-' . $unique,
            'student_number' => $studentNumber . '-' . $unique,
            'kind'           => 'student',
            'status'         => 'active',
            'active'         => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return (int) $db->insertID();
    }

    private function mintToken(int $userId): string
    {
        return \Config\Services::jwt()->sign($userId, 0);
    }

    public function testMeExposesHasLocalPasswordForBothAccountKinds(): void
    {
        // Local account (email_password identity) → true.
        $session = $this->loginFull();
        $me = $this->authed($session['token'], 'get', 'api/v1/auth/me');
        $me->assertStatus(200);
        $meBody = $this->envelope($me);
        $this->assertTrue($meBody['data']['has_local_password'] ?? null, 'Local accounts must report has_local_password=true.');

        // MIS-provisioned account (no identity) → false.
        $token = $this->mintToken($this->misProvisionedUser('MIS-9001'));
        $meMis = $this->authed($token, 'get', 'api/v1/auth/me');
        $meMis->assertStatus(200);
        $meMisBody = $this->envelope($meMis);
        $this->assertFalse($meMisBody['data']['has_local_password'] ?? null, 'MIS-provisioned accounts must report has_local_password=false.');
    }

    public function testChangePasswordRejectsMisProvisionedUserWithDedicatedError(): void
    {
        $userId = $this->misProvisionedUser('MIS-9002');
        $token  = $this->mintToken($userId);

        $change = $this->authed($token, 'post', 'api/v1/auth/change-password', [
            'current_password' => 'whatever-pass',
            'new_password'     => 'a-brand-new-password',
        ]);
        $change->assertStatus(403);
        $this->assertErrorCode('auth.password_managed_by_university', $change);
    }
}
