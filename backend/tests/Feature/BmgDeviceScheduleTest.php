<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Per-unit run schedules — the UI-managed configuration the ESP32s
 * pull.
 *
 * Contract pinned here:
 *  - `GET /api/v1/devices/bmg/schedule` is FLAT JSON (no human
 *    envelope) — the firmware parses `times` one level deep.
 *  - The version is the change channel: every publish bumps it, the
 *    device only re-applies when it differs.
 *  - Safe defaults (disabled, no times) are seeded lazily so a unit
 *    created before schedules existed still answers the pull.
 *  - Times normalize to sorted unique "HH:MM" 24-hour strings; at most
 *    8; invalid shapes are 422.
 *  - Publishing is ADMIN-only (`facilities.units.manage`).
 *  - An unbound device is a clean 409.
 */
final class BmgDeviceScheduleTest extends FeatureTestCase
{
    /** @var array{token:string, userId:int, email:string} */
    protected $session = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = $this->login(['bmg_admin']);
    }

    private function token(): string
    {
        return $this->session['token'];
    }

    /** @return array<string, mixed> */
    private function postJson(string $route, array $body, int $expect = 200): array
    {
        $res = $this->authed($this->token(), 'post', $route, $body);
        $res->assertStatus($expect);
        return $this->envelope($res);
    }

    private function drumWithBoard(): array
    {
        $device = $this->createBmgDevice();
        $suffix = bin2hex(random_bytes(4));
        $unit   = $this->postJson('api/v1/facilities/units', [
            'code'         => "sched-unit-{$suffix}",
            'display_name' => "Schedule Drum {$suffix}",
            'device_id'    => $device['deviceId'],
        ], 201);

        return ['unitId' => (int) $unit['data']['id']] + $device;
    }

    // --- device pull ---------------------------------------------------------

    public function testDeviceScheduleIsFlatJsonWithSafeDefaults(): void
    {
        $drum = $this->drumWithBoard();

        $res = $this->withHeaders(['X-Device-Token' => $drum['token']])
            ->call('get', 'api/v1/devices/bmg/schedule');
        $res->assertStatus(200);

        $body = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        // FLAT: no success/data envelope — the board parses this directly.
        $this->assertArrayNotHasKey('success', $body);
        $this->assertArrayNotHasKey('data', $body);
        $this->assertSame([], $body['times']);
        $this->assertSame(false, $body['enabled']);
        $this->assertSame(1, $body['version']);
        $this->assertIsInt($body['duration_seconds']);
        $this->assertIsInt($body['pwm']);
    }

    public function testUnboundDeviceScheduleIsRejected(): void
    {
        $device = $this->createBmgDevice(); // registered, not bound
        $res = $this->withHeaders(['X-Device-Token' => $device['token']])
            ->call('get', 'api/v1/devices/bmg/schedule');
        $res->assertStatus(409);
        $this->assertErrorCode('device.not_bound', $res);
    }

    // --- admin publish -------------------------------------------------------

    public function testPublishBumpsVersionAndNormalizesTimes(): void
    {
        $drum = $this->drumWithBoard();

        $first = $this->postJson("api/v1/facilities/units/{$drum['unitId']}/schedule", [
            'times'            => ['17:00', '09:00', '12:00'],
            'duration_seconds' => 10,
            'pwm'              => 220,
            'enabled'          => true,
        ], 200);

        $this->assertSame(['09:00', '12:00', '17:00'], $first['data']['times'], 'Times sort ascending.');
        $this->assertSame(2, $first['data']['version'], 'Seed at v1, publish bumps to v2.');
        $this->assertTrue($first['data']['enabled']);
        $this->assertSame(10, $first['data']['duration_seconds']);
        $this->assertSame(220, $first['data']['pwm']);
        $this->assertNotNull($first['data']['next_run_at'], 'An enabled schedule has a next run.');

        // The device sees the published copy with the SAME version.
        $res = $this->withHeaders(['X-Device-Token' => $drum['token']])
            ->call('get', 'api/v1/devices/bmg/schedule');
        $body = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $body['version']);
        $this->assertSame(['09:00', '12:00', '17:00'], $body['times']);
        $this->assertSame(10, $body['duration_seconds']);
        $this->assertSame(220, $body['pwm']);

        // Republish bumps again.
        $second = $this->postJson("api/v1/facilities/units/{$drum['unitId']}/schedule", [
            'times'            => ['09:00'],
            'duration_seconds' => 30,
            'pwm'              => 255,
            'enabled'          => true,
        ], 200);
        $this->assertSame(3, $second['data']['version']);
    }

    public function testPublishWritesHistoryWithTheActor(): void
    {
        $drum = $this->drumWithBoard();
        $this->postJson("api/v1/facilities/units/{$drum['unitId']}/schedule", [
            'times'            => ['06:30'],
            'duration_seconds' => 45,
            'pwm'              => 128,
            'enabled'          => true,
        ], 200);

        $row = db_connect()->table('facilities_bmg_schedule_history')
            ->where('unit_id', $drum['unitId'])
            ->orderBy('version', 'DESC')
            ->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row['version']);
        $this->assertSame(['06:30'], json_decode((string) $row['times'], true));
        $this->assertSame(45, (int) $row['duration_seconds']);
        $this->assertSame(128, (int) $row['pwm']);
        $this->assertSame($this->session['userId'], (int) $row['changed_by_user_id']);

        // The admin read returns current + history.
        $read = $this->authed($this->token(), 'get', "api/v1/facilities/units/{$drum['unitId']}/schedule");
        $read->assertStatus(200);
        $data = $this->envelope($read)['data'];
        $this->assertSame(2, $data['schedule']['version']);
        $this->assertNotEmpty($data['history']);
        $this->assertSame($this->session['userId'], $data['history'][0]['changed_by_user_id']);
    }

    public function testPublishValidatesShapes(): void
    {
        $drum = $this->drumWithBoard();
        $route = "api/v1/facilities/units/{$drum['unitId']}/schedule";

        $tooMany = $this->authed($this->token(), 'post', $route, [
            'times'            => ['01:00', '02:00', '03:00', '04:00', '05:00', '06:00', '07:00', '08:00', '09:00'],
            'duration_seconds' => 10,
            'pwm'              => 200,
            'enabled'          => true,
        ]);
        $tooMany->assertStatus(422);

        $badTime = $this->authed($this->token(), 'post', $route, [
            'times'            => ['9:00'],
            'duration_seconds' => 10,
            'pwm'              => 200,
            'enabled'          => true,
        ]);
        $badTime->assertStatus(422);

        $duplicate = $this->authed($this->token(), 'post', $route, [
            'times'            => ['09:00', '09:00'],
            'duration_seconds' => 10,
            'pwm'              => 200,
            'enabled'          => true,
        ]);
        $duplicate->assertStatus(422);

        $badDuration = $this->authed($this->token(), 'post', $route, [
            'times'            => [],
            'duration_seconds' => 0,
            'pwm'              => 200,
            'enabled'          => false,
        ]);
        $badDuration->assertStatus(422);

        $badPwm = $this->authed($this->token(), 'post', $route, [
            'times'            => [],
            'duration_seconds' => 10,
            'pwm'              => 256,
            'enabled'          => false,
        ]);
        $badPwm->assertStatus(422);

        $badEnabled = $this->authed($this->token(), 'post', $route, [
            'times'            => [],
            'duration_seconds' => 10,
            'pwm'              => 200,
            'enabled'          => 'yes',
        ]);
        $badEnabled->assertStatus(422);
    }

    public function testPublishingIsAdminOnly(): void
    {
        $drum  = $this->drumWithBoard();
        $operator = $this->login(['facilities_op']);

        $res = $this->authed($operator['token'], 'post', "api/v1/facilities/units/{$drum['unitId']}/schedule", [
            'times'            => ['09:00'],
            'duration_seconds' => 10,
            'pwm'              => 200,
            'enabled'          => true,
        ]);
        $res->assertStatus(403);

        // Reading stays open to anyone who can read units.
        $this->authed($operator['token'], 'get', "api/v1/facilities/units/{$drum['unitId']}/schedule")
            ->assertStatus(200);
    }
}
