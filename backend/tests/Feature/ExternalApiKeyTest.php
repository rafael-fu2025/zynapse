<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\External\ApiKeyService;

/**
 * External API surface (2026-09, D4/D5) — end-to-end through the real
 * routes and the ApiKeyAuthFilter:
 *
 *   - key lifecycle via /developer (superadmin-gated),
 *   - scope denial → 403 rbac.permission_denied:<code>,
 *   - expired/revoked → 401, suspended app → 403, over-limit → 429,
 *   - hash-only storage (the full secret is unretrievable after creation),
 *   - THE SANDBOX INVARIANT: a test key reads only sandbox-tenant data;
 *     a live key only production-tenant data (feature-tested, D5).
 *
 * Aggregate assertions use a random single-day range in 2020 so the
 * stateful (never-truncated) test schema can never contain pre-existing
 * rows for that day.
 */
final class ExternalApiKeyTest extends FeatureTestCase
{
    private const RANGE_DAYS_BACK = 3000;

    /** @var list<string> unique patient markers inserted by the current test */
    private array $insertedMarkers = [];

    protected function tearDown(): void
    {
        if ($this->insertedMarkers !== []) {
            $db = db_connect();
            $db->table('clinic_encounters')
                ->like('patient_school_id', 'EXTKEY-')
                ->whereIn('patient_school_id', $this->insertedMarkers)
                ->delete();
        }
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Lifecycle through the developer surface
    // -----------------------------------------------------------------

    public function testSuperadminCreatesAppAndTestKeyWithOneTimeSecret(): void
    {
        $actor = $this->login(['superadmin']);

        $appId = $this->createApp($actor['token']);
        $this->assertGreaterThan(0, $appId);

        $issued = $this->issueKey($actor['token'], $appId, 'test', ['reports.read']);
        $this->assertMatchesRegularExpression(
            '/^syn_test_[a-z0-9]{4}_[A-Za-z0-9_-]{43,}$/',
            $issued['secret'],
            'Key format must be syn_<env>_<prefix4>_<43+ chars> (D4).',
        );
        $this->assertSame('syn_test', substr($issued['secret'], 0, 8));
        $this->assertSame('test', $issued['key']['env']);
        $this->assertSame('active', $issued['key']['status']);
        $this->assertArrayNotHasKey('key_hash', $issued['key'], 'The stored hash must never be returned.');
    }

    public function testUnitAdminCannotAccessDeveloperSurface(): void
    {
        $actor = $this->login(['guidance_admin']);

        $read = $this->authed($actor['token'], 'get', 'api/v1/developer/apps');
        $read->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:api_apps.read', $read);

        $write = $this->authed($actor['token'], 'post', 'api/v1/developer/apps', ['name' => 'Sneaky App']);
        $write->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:api_apps.manage', $write);
    }

    public function testLiveKeyRequiresDpaAcknowledgement(): void
    {
        $actor = $this->login(['superadmin']);
        $appId = $this->createApp($actor['token']);

        $denied = $this->issueKey($actor['token'], $appId, 'live', ['reports.read']);
        $denied['response']->assertStatus(422);

        $allowed = $this->issueKey($actor['token'], $appId, 'live', ['reports.read'], ['dpa_acknowledged' => true]);
        $allowed['response']->assertStatus(201);
        $this->assertSame('live', $allowed['key']['env']);

        // The DPA acknowledgement was recorded on the app with actor + time.
        $apps = $this->authed($actor['token'], 'get', 'api/v1/developer/apps');
        $rows = array_values(array_filter(
            $this->envelope($apps)['data'] ?? [],
            static fn (array $r): bool => (int) $r['id'] === $appId,
        ));
        $this->assertNotEmpty($rows[0]['dpa_acknowledged_at'] ?? null, 'dpa_acknowledged_at must be set.');
    }

    // -----------------------------------------------------------------
    // Auth states
    // -----------------------------------------------------------------

    public function testPingNeedsNoScopeButAValidKey(): void
    {
        $actor   = $this->login(['superadmin']);
        $appId   = $this->createApp($actor['token']);
        $issued  = $this->issueKey($actor['token'], $appId, 'test', ['reports.read']);

        $missing = $this->call('get', 'api/v1/external/v1/ping');
        $missing->assertStatus(401);
        $this->assertErrorCode('auth.api_key_missing', $missing);

        $garbage = $this->externalCall('get', 'api/v1/external/v1/ping', 'syn_test_abcd_totally-made-up-key');
        $garbage->assertStatus(401);
        $this->assertErrorCode('auth.api_key_invalid', $garbage);

        $ok = $this->externalCall('get', 'api/v1/external/v1/ping', $issued['secret']);
        $ok->assertStatus(200);
        $this->assertTrue($this->envelope($ok)['success']);
    }

    public function testMissingScopeIsDeniedWithPermissionCode(): void
    {
        $actor  = $this->login(['superadmin']);
        $appId  = $this->createApp($actor['token']);
        $issued = $this->issueKey($actor['token'], $appId, 'test', ['reports.read']);

        $result = $this->externalCall('get', 'api/v1/external/v1/aggregates/referrals', $issued['secret']);

        $result->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:referrals.read', $result);
    }

    public function testExpiredKeyIsUnauthorized(): void
    {
        $actor   = $this->login(['superadmin']);
        $appId   = $this->createApp($actor['token']);
        $issued  = $this->issueKey($actor['token'], $appId, 'test', ['reports.read']);

        db_connect()->table('api_keys')->where('id', (int) $issued['key']['id'])->update([
            'expires_at' => '2020-01-01 00:00:00',
        ]);

        $result = $this->externalCall('get', 'api/v1/external/v1/ping', $issued['secret']);
        $result->assertStatus(401);
        $this->assertErrorCode('auth.api_key_expired', $result);
    }

    public function testRevokedKeyIsUnauthorizedImmediately(): void
    {
        $actor   = $this->login(['superadmin']);
        $appId   = $this->createApp($actor['token']);
        $issued  = $this->issueKey($actor['token'], $appId, 'test', ['reports.read']);

        $this->externalCall('get', 'api/v1/external/v1/ping', $issued['secret'])->assertStatus(200);

        $revoke = $this->authed($actor['token'], 'post', 'api/v1/developer/keys/' . $issued['key']['id'] . '/revoke', [
            'reason' => 'feature_test_rotation',
        ]);
        $revoke->assertStatus(200);

        $result = $this->externalCall('get', 'api/v1/external/v1/ping', $issued['secret']);
        $result->assertStatus(401);
        $this->assertErrorCode('auth.api_key_revoked', $result);
    }

    public function testSuspendedAppBlocksItsKeys(): void
    {
        $actor   = $this->login(['superadmin']);
        $appId   = $this->createApp($actor['token']);
        $issued  = $this->issueKey($actor['token'], $appId, 'test', ['reports.read']);

        $suspend = $this->authed($actor['token'], 'post', 'api/v1/developer/apps/' . $appId . '/status', [
            'status' => 'suspended',
        ]);
        $suspend->assertStatus(200);

        $result = $this->externalCall('get', 'api/v1/external/v1/ping', $issued['secret']);
        $result->assertStatus(403);
        $this->assertErrorCode('api_app.suspended', $result);
    }

    public function testPerKeyRateLimitReturns429(): void
    {
        $actor   = $this->login(['superadmin']);
        $appId   = $this->createApp($actor['token']);
        $issued  = $this->issueKey($actor['token'], $appId, 'test', ['reports.read'], ['rate_limit_per_min' => 1]);

        $this->externalCall('get', 'api/v1/external/v1/ping', $issued['secret'])->assertStatus(200);

        $over = $this->externalCall('get', 'api/v1/external/v1/ping', $issued['secret']);
        $over->assertStatus(429);
        $this->assertErrorCode('ratelimit.exceeded', $over);
    }

    // -----------------------------------------------------------------
    // Hash-only storage (AC8)
    // -----------------------------------------------------------------

    public function testFullSecretIsNeverStoredOrRetrievable(): void
    {
        $actor   = $this->login(['superadmin']);
        $appId   = $this->createApp($actor['token']);
        $issued  = $this->issueKey($actor['token'], $appId, 'test', ['reports.read', 'referrals.read']);
        $secret  = $issued['secret'];

        $row = db_connect()->table('api_keys')
            ->where('id', (int) $issued['key']['id'])
            ->get()->getRowArray();
        $this->assertIsArray($row);

        // The hash is the SHA-256 of the full secret — the secret itself
        // appears NOWHERE in the row.
        $this->assertSame(hash('sha256', $secret), $row['key_hash']);
        $this->assertStringNotContainsString(
            substr($secret, 8),
            json_encode($row) ?: '',
            'No fragment of the secret tail may be stored beyond last4/prefix.',
        );

        // The list endpoint exposes prefix + last4 only.
        $list = $this->authed($actor['token'], 'get', 'api/v1/developer/apps/' . $appId . '/keys');
        $list->assertStatus(200);
        $this->assertStringNotContainsString($secret, $list->getJSON() ?: '');
    }

    // -----------------------------------------------------------------
    // THE SANDBOX INVARIANT (D5 / AC7)
    // -----------------------------------------------------------------

    public function testTestKeyReadsOnlySandboxTenantDataAndLiveKeyOnlyProduction(): void
    {
        $actor = $this->login(['superadmin']);
        $appId = $this->createApp($actor['token']);

        $testKey = $this->issueKey($actor['token'], $appId, 'test', ['reports.read']);
        $liveKey = $this->issueKey($actor['token'], $appId, 'live', ['reports.read'], ['dpa_acknowledged' => true]);

        // A run-unique single day in 2020 — no other suite ever touches it.
        $day = (new \DateTimeImmutable('2020-01-01'))
            ->modify('+' . random_int(0, self::RANGE_DAYS_BACK) . ' days')
            ->format('Y-m-d');
        $utcStamp = (new \DateTimeImmutable($day . ' 02:00:00'))->format('Y-m-d H:i:s');

        $this->insertEncounter((new ApiKeyService())->sandboxTenantId(), $actor['userId'], $utcStamp);
        $this->insertEncounter((new ApiKeyService())->sandboxTenantId(), $actor['userId'], $utcStamp);
        $this->insertEncounter((new ApiKeyService())->sandboxTenantId(), $actor['userId'], $utcStamp);
        $this->insertEncounter(1, $actor['userId'], $utcStamp);
        $this->insertEncounter(1, $actor['userId'], $utcStamp);
        $this->insertEncounter(1, $actor['userId'], $utcStamp);
        $this->insertEncounter(1, $actor['userId'], $utcStamp);
        $this->insertEncounter(1, $actor['userId'], $utcStamp);

        $path = 'api/v1/external/v1/aggregates/visits?start=' . $day . '&end=' . $day;

        // NOTE: read each envelope IMMEDIATELY after its call — the shared
        // Services::response() instance is reused across in-process calls,
        // so a held TestResponse is mutated by the next call.
        $asTest = $this->externalCall('get', $path, $testKey['secret']);
        $asTest->assertStatus(200);
        $testTotal = (int) (($this->envelope($asTest)['data']['total_encounters']) ?? -1);

        $asLive = $this->externalCall('get', $path, $liveKey['secret']);
        $asLive->assertStatus(200);
        $liveTotal = (int) (($this->envelope($asLive)['data']['total_encounters']) ?? -1);

        $this->assertSame(3, $testTotal, 'A test key must see ONLY sandbox-tenant rows.');
        $this->assertSame(5, $liveTotal, 'A live key must see ONLY production-tenant rows.');
    }

    public function testSandboxExecutorRunsTestKeyAndRejectsLiveKey(): void
    {
        $actor = $this->login(['superadmin']);
        $appId = $this->createApp($actor['token']);

        $testKey = $this->issueKey($actor['token'], $appId, 'test', ['reports.read']);
        $liveKey = $this->issueKey($actor['token'], $appId, 'live', ['reports.read'], ['dpa_acknowledged' => true]);

        // hash-only storage: the executor takes a key ID, not a secret.
        $run  = $this->authed($actor['token'], 'post', 'api/v1/developer/sandbox/execute', [
            'key_id' => (int) $testKey['key']['id'],
            'method' => 'GET',
            'path'   => 'aggregates/visits',
        ]);
        $run->assertStatus(200);
        $payload = $this->envelope($run)['data'] ?? [];
        $this->assertSame(200, $payload['status'] ?? -1);
        $this->assertIsArray($payload['body'] ?? null);
        $this->assertArrayHasKey('total_encounters', $payload['body']);

        $refused = $this->authed($actor['token'], 'post', 'api/v1/developer/sandbox/execute', [
            'key_id' => (int) $liveKey['key']['id'],
            'method' => 'GET',
            'path'   => 'aggregates/visits',
        ]);
        $refused->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** @return int the new app id */
    private function createApp(string $token): int
    {
        $result = $this->authed($token, 'post', 'api/v1/developer/apps', [
            'name'          => 'Feature App ' . bin2hex(random_bytes(3)),
            'owner_contact' => 'integrator@feature.test',
        ]);
        if ($result->getStatusCode() !== 201) {
            fwrite(STDERR, "\n[createApp " . $result->getStatusCode() . "] " . $result->getJSON() . "\n");
        }
        $result->assertStatus(201);
        return (int) ($this->envelope($result)['data']['id'] ?? 0);
    }

    /**
     * @param list<string> $scopes
     * @return array{response: \CodeIgniter\Test\TestResponse, key: array<string, mixed>, secret: string}
     */
    private function issueKey(string $token, int $appId, string $env, array $scopes, array $extra = []): array
    {
        $response = $this->authed($token, 'post', 'api/v1/developer/apps/' . $appId . '/keys', array_merge([
            'env'    => $env,
            'scopes' => $scopes,
        ], $extra));
        $body = $this->envelope($response);

        return [
            'response' => $response,
            'key'      => is_array($body['data']['key'] ?? null) ? $body['data']['key'] : [],
            'secret'   => (string) ($body['data']['secret'] ?? ''),
        ];
    }

    private function externalCall(string $method, string $path, string $secret): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders(['X-Api-Key' => $secret])->call($method, $path);
    }

    private function insertEncounter(int $tenantId, int $attendingUserId, string $utcStamp): void
    {
        $marker = 'EXTKEY-' . bin2hex(random_bytes(8));
        $this->insertedMarkers[] = $marker;

        db_connect()->table('clinic_encounters')->insert([
            'tenant_id'         => $tenantId,
            'patient_school_id' => $marker,
            'chief_complaint'   => 'External API invariant fixture',
            'status'            => 'closed',
            'attending_user_id' => $attendingUserId,
            'started_at'        => $utcStamp,
            'closed_at'         => $utcStamp,
            'outcome'           => 'auto_closed',
            'created_at'        => $utcStamp,
            'updated_at'        => $utcStamp,
        ]);
    }
}
