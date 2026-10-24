<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\TestResponse;

/**
 * Session inventory + selective logout over the real routes (G-5).
 *
 *   - listing is self-scoped and marks the presented cookie's family current
 *   - rotation stamps last_used_at and carries device provenance
 *   - revoking another family kills its refresh but leaves others alive
 *   - the current family cannot be revoked here (that is logout)
 *   - a foreign family id reads as 404 and does not disturb the victim
 */
final class SessionInventoryTest extends FeatureTestCase
{
    /**
     * Log in over the real route on a pre-created account (mirrors
     * AuthenticationFlowTest::loginFor, which is private).
     *
     * @return array{token:string, refresh:string, family_id:string}
     */
    private function loginFor(string $email): array
    {
        $result = $this->withBodyFormat('json')->call(
            'post',
            'api/v1/auth/login',
            ['email' => $email, 'password' => $this->testPassword()],
        );
        $result->assertStatus(200);

        $cookie = $result->response()->getCookie('synapse_rt');
        $this->assertInstanceOf(\CodeIgniter\Cookie\Cookie::class, $cookie);
        $body = $this->envelope($result);

        return [
            'token'     => (string) $body['data']['access_token'],
            'refresh'   => (string) $cookie->getValue(),
            'family_id' => $this->familyOf((string) $cookie->getValue()),
        ];
    }

    private function familyOf(string $refreshPlain): string
    {
        $hash = hash_hmac('sha256', $refreshPlain, (string) getenv('JWT_SECRET'));
        $row = db_connect()->table('auth_refresh_tokens')
            ->select('family_id')
            ->where('token_hash', $hash)
            ->get()->getRowArray();
        $this->assertNotNull($row, 'Presented refresh token must exist.');

        return (string) $row['family_id'];
    }

    /** @return array<int, array<string, mixed>> */
    private function listSessions(array $session): array
    {
        $result = $this->withCookies(['synapse_rt' => $session['refresh']])
            ->withHeaders(['Authorization' => 'Bearer ' . $session['token']])
            ->call('get', 'api/v1/auth/sessions');
        $result->assertStatus(200);

        return $this->envelope($result)['data'];
    }

    public function testListingIsSelfScopedAndMarksTheCurrentSession(): void
    {
        $email = $this->uniqueEmail('sessions');
        $this->createUser(['student'], $email);

        $a = $this->loginFor($email);
        $b = $this->loginFor($email);

        // Rotate family B once so its live row carries a last_used_at stamp.
        $this->withCookies(['synapse_rt' => $b['refresh']])
            ->call('post', 'api/v1/auth/refresh')
            ->assertStatus(200);

        $sessions = $this->listSessions($a);
        $this->assertCount(2, $sessions, 'Both of this user\'s families must be listed.');

        $current = array_values(array_filter($sessions, static fn (array $s): bool => (bool) $s['is_current']));
        $this->assertCount(1, $current);
        $this->assertSame($a['family_id'], $current[0]['family_id']);
        // The harness request carries a synthetic client IP (0.0.0.0 in CLI);
        // assert the value is that IP collapsed to its /24, not a literal.
        $expectedSubnet = preg_replace('/\.\d+$/', '.0', service('request')->getIPAddress()) . '/24';
        $this->assertSame($expectedSubnet, $current[0]['ip_subnet'], 'Client IP must be coarsened to its /24 subnet.');
        $this->assertNotSame('', (string) $current[0]['user_agent_hash']);

        // Only the caller's sessions are visible.
        $other = $this->login(['student']);
        $this->authed($other['token'], 'get', 'api/v1/auth/sessions')->assertStatus(200);
        $this->assertCount(1, $this->envelope($this->authed($other['token'], 'get', 'api/v1/auth/sessions'))['data']);
    }

    public function testRotationStampsLastUsedAt(): void
    {
        $email = $this->uniqueEmail('lastused');
        $this->createUser(['student'], $email);
        $a = $this->loginFor($email);

        $rowBefore = db_connect()->table('auth_refresh_tokens')
            ->where('family_id', $a['family_id'])->orderBy('id', 'DESC')->limit(1)
            ->get()->getRowArray();
        $this->assertNull($rowBefore['last_used_at'], 'A freshly minted token has never been used.');

        $this->withCookies(['synapse_rt' => $a['refresh']])
            ->call('post', 'api/v1/auth/refresh')
            ->assertStatus(200);

        $rowAfter = db_connect()->table('auth_refresh_tokens')
            ->where('token_hash', hash_hmac('sha256', $a['refresh'], (string) getenv('JWT_SECRET')))
            ->get()->getRowArray();
        $this->assertNotNull($rowAfter);
        $this->assertNotNull($rowAfter['last_used_at'], 'Presenting a refresh token must stamp last_used_at.');
    }

    public function testRevokingAnotherFamilyKillsOnlyThatFamily(): void
    {
        $email = $this->uniqueEmail('revoke');
        $this->createUser(['student'], $email);

        $a = $this->loginFor($email);
        $b = $this->loginFor($email);

        $this->withCookies(['synapse_rt' => $a['refresh']])
            ->authed($a['token'], 'post', 'api/v1/auth/sessions/revoke', ['family_id' => $b['family_id']])
            ->assertStatus(200);

        // B's refresh is dead...
        $this->withCookies(['synapse_rt' => $b['refresh']])
            ->call('post', 'api/v1/auth/refresh')
            ->assertStatus(401);
        // ...while A's still rotates fine.
        $this->withCookies(['synapse_rt' => $a['refresh']])
            ->call('post', 'api/v1/auth/refresh')
            ->assertStatus(200);
    }

    public function testCurrentFamilyCannotBeRevokedViaTheEndpoint(): void
    {
        $email = $this->uniqueEmail('curfam');
        $this->createUser(['student'], $email);
        $a = $this->loginFor($email);

        $this->withCookies(['synapse_rt' => $a['refresh']])
            ->authed($a['token'], 'post', 'api/v1/auth/sessions/revoke', ['family_id' => $a['family_id']])
            ->assertStatus(400);

        // And the session is still alive afterwards.
        $this->withCookies(['synapse_rt' => $a['refresh']])
            ->call('post', 'api/v1/auth/refresh')
            ->assertStatus(200);
    }

    public function testForeignFamilyIdIsIndistinguishableFromUnknown(): void
    {
        $mine = $this->login(['student']);
        $victimEmail = $this->uniqueEmail('victim');
        $this->createUser(['student'], $victimEmail);
        $victim = $this->loginFor($victimEmail);

        $res = $this->authed($mine['token'], 'post', 'api/v1/auth/sessions/revoke', [
            'family_id' => $victim['family_id'],
        ]);
        $res->assertStatus(404);

        // The victim's session survived the foreign revoke attempt.
        $this->withCookies(['synapse_rt' => $victim['refresh']])
            ->call('post', 'api/v1/auth/refresh')
            ->assertStatus(200);
    }
}
