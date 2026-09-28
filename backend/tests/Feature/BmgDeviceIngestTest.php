<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\RegisterBmgDevice;

/**
 * BMG device ingest — `POST /api/v1/devices/bmg/turn-sessions`.
 *
 * Drives the mechanized-tumbler path end-to-end through the real
 * `device_auth` filter pipeline: static token → machine user binding →
 * unit binding → active-batch resolution → process-log insert → alert
 * engine. Pins the two properties the whole design rests on:
 *
 *   - the device surface is DISJOINT from the human JWT surface (a user
 *     token in the Authorization header never authenticates a device);
 *   - a turning session is IDEMPOTENT per (tenant, session_uid) — the
 *     device's offline-queue replays collapse onto the first insert.
 *
 * Fixtures (device + machine user rows) are written directly so the
 * suite pins the FILTER + SERVICE behaviour; the register command has
 * its own happy-path test at the bottom.
 */
final class BmgDeviceIngestTest extends FeatureTestCase
{
    /** @var array{id:int, email:string} */
    private array $bmgAdmin = [];

    private string $tokenValue = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bmgAdmin = $this->login(['bmg_admin']);
    }

    private function token(): string
    {
        if ($this->tokenValue === '') {
            $res = $this->withBodyFormat('json')->call('post', 'api/v1/auth/login', [
                'email'    => $this->bmgAdmin['email'],
                'password' => $this->testPassword(),
            ]);
            $res->assertStatus(200);
            $token = $this->envelope($res)['data']['access_token'] ?? null;
            $this->assertIsString($token);
            $this->tokenValue = $token;
        }
        return $this->tokenValue;
    }

    /**
     * Category → unit → active batch (processing), mirroring
     * BmgWorkflowTest::runLifecycle.
     *
     * @return array{suffix:string, categoryId:int, unitId:int, batchId:int}
     */
    private function runLifecycle(): array
    {
        $post = function (string $route, array $body): array {
            $res = $this->authed($this->token(), 'post', $route, $body);
            $res->assertStatus(201);
            return $this->envelope($res);
        };

        $suffix   = bin2hex(random_bytes(4));
        $category = $post('api/v1/facilities/waste-categories', [
            'code' => "cat-{$suffix}",
            'name' => "Device Test Category {$suffix}",
        ]);
        $categoryId = (int) $category['data']['id'];

        $unit = $post('api/v1/facilities/units', [
            'code'                => "unit-{$suffix}",
            'display_name'        => "Device Test Drum {$suffix}",
            'default_category_id' => $categoryId,
        ]);
        $unitId = (int) $unit['data']['id'];

        $batch = $post("api/v1/facilities/units/{$unitId}/start", [
            'total_input_weight_kg' => 100,
            'composition'           => [['category_id' => $categoryId, 'weight_kg' => 100]],
        ]);
        $batchId = (int) $batch['data']['id'];

        return compact('suffix', 'categoryId', 'unitId', 'batchId');
    }

    /**
     * Create a device + its machine user directly (same shape the
     * register command writes). Returns the plaintext token, which the
     * filter hashes on the wire.
     *
     * @return array{deviceId:int, userId:int, token:string, code:string}
     */
    private function createDevice(?int $unitId, string $status = 'active', ?string $code = null): array
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $code ??= 'dev-' . bin2hex(random_bytes(4));

        $db->table('users')->insert([
            'username'   => 'dev-' . bin2hex(random_bytes(5)),
            'status'     => 'active',
            'active'     => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) $db->insertID();

        $db->table('auth_identities')->insert([
            'user_id'     => $userId,
            'type'        => 'email_password',
            'secret'      => 'device.' . $code . '@devices.local',
            'secret2'     => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'force_reset' => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $group = $db->table('auth_groups')->where('name', 'bmg_device')->get()->getRowArray();
        $this->assertNotNull($group, 'bmg_device group must exist (seeder).');
        $db->table('auth_groups_users')->insert([
            'group_id'   => (int) $group['id'],
            'user_id'    => $userId,
            'created_at' => $now,
        ]);

        $token = 'dev_' . bin2hex(random_bytes(32));
        $db->table('facilities_bmg_devices')->insert([
            'tenant_id'      => 1,
            'unit_id'        => $unitId,
            'code'           => $code,
            'display_name'   => 'BMG Device ' . $code,
            'token_hash'     => hash('sha256', $token),
            'token_prefix'   => substr($token, 0, 12),
            'status'         => $status,
            'linked_user_id' => $userId,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        return ['deviceId' => (int) $db->insertID(), 'userId' => $userId, 'token' => $token, 'code' => $code];
    }

    /** @param array<string, mixed> $body */
    private function devicePost(string $token, array $body): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders(['X-Device-Token' => $token])
            ->withBodyFormat('json')
            ->call('post', 'api/v1/devices/bmg/turn-sessions', $body);
    }

    /**
     * Six random hex byte pairs, colon-separated. The shared test
     * schema persists rows across suite runs ($refresh = false), so
     * device fixtures must be unique per run just like unit codes.
     */
    private function uniqueMac(): string
    {
        return implode(':', str_split(bin2hex(random_bytes(6)), 2));
    }

    /** @return array<string, mixed> */
    private function sessionPayload(): array
    {
        return [
            'session_uid'      => bin2hex(random_bytes(16)),
            'turns_count'      => 21,
            'sets_count'       => 3,
            'duration_seconds' => 56,
            'firmware'         => 'compost-1.1',
        ];
    }

    // --- auth surface ---------------------------------------------------

    public function testMissingTokenIs401(): void
    {
        $res = $this->withBodyFormat('json')->call('post', 'api/v1/devices/bmg/turn-sessions', $this->sessionPayload());
        $res->assertStatus(401);
        $this->assertErrorCode('device.unauthorized', $res);
    }

    public function testUnknownTokenIs401(): void
    {
        $res = $this->devicePost('dev_' . bin2hex(random_bytes(32)), $this->sessionPayload());
        $res->assertStatus(401);
        $this->assertErrorCode('device.unauthorized', $res);
    }

    public function testUserJwtInBearerHeaderDoesNotAuthenticateAsDevice(): void
    {
        // The surfaces are disjoint: a perfectly valid human token must
        // not open the device route (the Bearer fallback only accepts
        // the dev_ shape).
        $res = $this->authed($this->token(), 'post', 'api/v1/devices/bmg/turn-sessions', $this->sessionPayload());
        $res->assertStatus(401);
        $this->assertErrorCode('device.unauthorized', $res);
    }

    public function testDisabledDeviceIs403(): void
    {
        $ctx   = $this->runLifecycle();
        $device = $this->createDevice($ctx['unitId'], status: 'disabled');

        $res = $this->devicePost($device['token'], $this->sessionPayload());
        $res->assertStatus(403);
        $this->assertErrorCode('device.disabled', $res);
    }

    // --- ingest ----------------------------------------------------------

    public function testHappyPathCreatesTurningLogOnActiveBatch(): void
    {
        $ctx   = $this->runLifecycle();
        $device = $this->createDevice($ctx['unitId']);

        $res = $this->devicePost($device['token'], $this->sessionPayload());
        $res->assertStatus(201);
        $body = $this->envelope($res);

        $log = $body['data'];
        $this->assertTrue($log['created'] ?? false);
        $this->assertSame('turning', $log['event_type'] ?? null);
        $this->assertSame($device['code'], $log['device_id'] ?? null);
        $this->assertSame(21, $log['turns_count'] ?? null);
        $this->assertSame(56, $log['duration_seconds'] ?? null);
        $this->assertSame($device['userId'], $log['recorded_by_user_id'] ?? null);
        $this->assertStringContainsString('Automated turning session', (string) ($log['observation_note'] ?? ''));

        // The human read surface sees the device row with session fields.
        $list = $this->authed($this->token(), 'get', "api/v1/facilities/batches/{$ctx['batchId']}/logs");
        $list->assertStatus(200);
        $logs = $this->envelope($list)['data'];
        $mine = array_values(array_filter($logs, static fn ($r) => ($r['device_id'] ?? null) === $device['code']));
        $this->assertCount(1, $mine);
        $this->assertSame(21, $mine[0]['turns_count'] ?? null);
        $this->assertSame(56, $mine[0]['duration_seconds'] ?? null);
    }

    public function testReplayIsIdempotent(): void
    {
        $ctx   = $this->runLifecycle();
        $device = $this->createDevice($ctx['unitId']);

        $payload = $this->sessionPayload();
        $first   = $this->devicePost($device['token'], $payload);
        $first->assertStatus(201);
        $this->assertTrue($this->envelope($first)['data']['created'] ?? false);

        // Offline-queue replay: same session_uid → the ORIGINAL row.
        $second = $this->devicePost($device['token'], $payload);
        $second->assertStatus(200);
        $body = $this->envelope($second);
        $this->assertFalse($body['data']['created'] ?? true);
        $this->assertSame(
            $this->envelope($first)['data']['id'],
            $body['data']['id'],
            'Replay must return the original log row, not a new one.',
        );

        $this->assertSame(
            1,
            (int) db_connect()->table('facilities_bmg_process_logs')
                ->where('session_uid', $payload['session_uid'])
                ->countAllResults(),
        );
    }

    public function testNoActiveBatchIs409(): void
    {
        // Unit WITHOUT a batch: the device turns an empty drum.
        $suffix = bin2hex(random_bytes(4));
        $res    = $this->authed($this->token(), 'post', 'api/v1/facilities/units', [
            'code'         => "unit-{$suffix}",
            'display_name' => "Idle Drum {$suffix}",
        ]);
        $res->assertStatus(201);
        $unitId = (int) $this->envelope($res)['data']['id'];

        $device = $this->createDevice($unitId);
        $res    = $this->devicePost($device['token'], $this->sessionPayload());
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.bmg.log_terminal_batch', $res);
    }

    public function testUnboundDeviceIs409(): void
    {
        $device = $this->createDevice(null);
        $res    = $this->devicePost($device['token'], $this->sessionPayload());
        $res->assertStatus(409);
        $this->assertErrorCode('device.not_bound', $res);
    }

    public function testValidationFailureIs422(): void
    {
        $ctx   = $this->runLifecycle();
        $device = $this->createDevice($ctx['unitId']);

        $payload = $this->sessionPayload();
        unset($payload['session_uid']);
        $res = $this->devicePost($device['token'], $payload);
        $res->assertStatus(422);
    }

    // --- register command -------------------------------------------------

    public function testRegisterCommandMintsTokenOnceThenRefuses(): void
    {
        $ctx   = $this->runLifecycle();
        $mac   = $this->uniqueMac();
        $code  = str_replace(':', '-', $mac);

        $command = new RegisterBmgDevice(service('logger'), service('commands'));
        $this->assertSame(0, $command->run([
            'mac'  => $mac,
            'unit' => "unit-{$ctx['suffix']}",
        ]));

        $db     = db_connect();
        $device = $db->table('facilities_bmg_devices')->where('code', $code)->get()->getRowArray();
        $this->assertNotNull($device, 'Device row must exist after registration.');
        $this->assertSame($ctx['unitId'], (int) $device['unit_id']);
        $this->assertSame('active', $device['status']);

        // Machine user is in the bmg_device group.
        $group = $db->table('auth_groups')->where('name', 'bmg_device')->get()->getRowArray();
        $this->assertSame(
            1,
            (int) $db->table('auth_groups_users')
                ->where(['group_id' => (int) $group['id'], 'user_id' => (int) $device['linked_user_id']])
                ->countAllResults(),
        );

        // Idempotency: a plain re-run refuses; --regenerate rekeys.
        $this->assertSame(1, $command->run(['mac' => $mac]));

        $before = $device['token_hash'];
        $this->assertSame(0, $command->run(['mac' => $mac, 'regenerate' => null]));
        $after = $db->table('facilities_bmg_devices')->where('code', $code)->get()->getRowArray();
        $this->assertNotSame($before, $after['token_hash'], '--regenerate must mint a new token hash.');
        $this->assertSame((int) $device['linked_user_id'], (int) $after['linked_user_id'], 'Machine user must NOT churn on rekey.');
    }

    // --- admin API (the Facilities UI surface) ----------------------------

    public function testAdminRegistersDeviceViaApiAndItIngests(): void
    {
        $ctx = $this->runLifecycle();
        $mac = $this->uniqueMac();

        // Register through the same endpoint the UI dialog uses.
        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/devices', [
            'mac'          => $mac,
            'display_name' => 'Compost Tumbler 1',
            'unit_id'      => $ctx['unitId'],
        ]);
        $res->assertStatus(201);
        $body = $this->envelope($res);
        $this->assertStringStartsWith('dev_', (string) ($body['data']['token'] ?? ''), 'Registration must return the plaintext token once.');
        $this->assertSame($ctx['unitId'], $body['data']['device']['unit_id'] ?? null);
        $deviceCode = (string) $body['data']['device']['code'];

        // It appears in the admin list.
        $list = $this->authed($this->token(), 'get', 'api/v1/facilities/devices');
        $list->assertStatus(200);
        $rows = $this->envelope($list)['data'];
        $mine = array_values(array_filter($rows, static fn ($r) => ($r['code'] ?? null) === $deviceCode));
        $this->assertCount(1, $mine);
        $this->assertSame('Compost Tumbler 1', $mine[0]['display_name'] ?? null);

        // The UI-registered device ingests with the token it was given.
        $session = $this->devicePost((string) $body['data']['token'], $this->sessionPayload());
        $session->assertStatus(201);
    }

    public function testDeviceAdminRequiresManagePermission(): void
    {
        $op = $this->login(['facilities_op']);

        $res = $this->withHeaders(['Authorization' => 'Bearer ' . $op['token']])
            ->withBodyFormat('json')
            ->call('post', 'api/v1/facilities/devices', ['mac' => $this->uniqueMac()]);
        $res->assertStatus(403);
    }

    public function testDuplicateRegisterViaApiIs409(): void
    {
        $this->runLifecycle();
        $mac = $this->uniqueMac();

        $first = $this->authed($this->token(), 'post', 'api/v1/facilities/devices', [
            'mac' => $mac,
        ]);
        $first->assertStatus(201);

        $second = $this->authed($this->token(), 'post', 'api/v1/facilities/devices', [
            'mac' => $mac,
        ]);
        $second->assertStatus(409);
    }

    public function testRegenerateAndStatusViaApi(): void
    {
        $ctx   = $this->runLifecycle();
        $first = $this->authed($this->token(), 'post', 'api/v1/facilities/devices', [
            'mac'     => $this->uniqueMac(),
            'unit_id' => $ctx['unitId'],
        ]);
        $first->assertStatus(201);
        $oldToken = (string) $this->envelope($first)['data']['token'];
        $deviceId = (int) $this->envelope($first)['data']['device']['id'];

        // Regenerate: new token works, old one is dead.
        $regen = $this->authed($this->token(), 'post', "api/v1/facilities/devices/{$deviceId}/regenerate-token");
        $regen->assertStatus(201);
        $newToken = (string) $this->envelope($regen)['data']['token'];
        $this->assertNotSame($oldToken, $newToken);
        $this->devicePost($oldToken, $this->sessionPayload())->assertStatus(401);

        // Disable: ingest refuses; re-enable: works again.
        $disable = $this->authed($this->token(), 'post', "api/v1/facilities/devices/{$deviceId}/status", ['status' => 'disabled']);
        $disable->assertStatus(200);
        $this->devicePost($newToken, $this->sessionPayload())->assertStatus(403);

        $enable = $this->authed($this->token(), 'post', "api/v1/facilities/devices/{$deviceId}/status", ['status' => 'active']);
        $enable->assertStatus(200);
        $this->devicePost($newToken, $this->sessionPayload())->assertStatus(201);
    }
}
