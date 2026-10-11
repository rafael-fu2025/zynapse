<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Per-unit monitoring aggregate — the drum dashboard's single read.
 *
 * Coverage that matters:
 *  - a drum with NO bound board still answers (the null-device path is
 *    a normal state: idle drum, board pulled for replacement — it must
 *    never 500);
 *  - liveness comes from the heartbeat, and stale heartbeats read
 *    offline;
 *  - runs and missed slots reflect what the board reported;
 *  - manual I/O totals are void-aware.
 */
final class BmgUnitMonitorTest extends FeatureTestCase
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

    /** A drum with a bound board, no batch. */
    private function drumWithBoard(): array
    {
        $device = $this->createBmgDevice();
        $suffix = bin2hex(random_bytes(4));
        $unit   = $this->postJson('api/v1/facilities/units', [
            'code'         => "mon-unit-{$suffix}",
            'display_name' => "Monitor Drum {$suffix}",
            'device_id'    => $device['deviceId'],
        ], 201);

        return ['unitId' => (int) $unit['data']['id']] + $device;
    }

    public function testMonitorAnswersForADrumWithNoBoundBoard(): void
    {
        // The replacement window: the board was pulled off the drum.
        // This must be a clean 200 with device: null, never a 500.
        $drum = $this->drumWithBoard();
        $this->postJson('api/v1/facilities/units/' . $drum['unitId'], ['device_id' => null]);

        $res = $this->authed($this->token(), 'get', "api/v1/facilities/units/{$drum['unitId']}/monitor");
        $res->assertStatus(200);

        $data = $this->envelope($res)['data'];
        $this->assertNull($data['device'], 'No board → device is null, not an error.');
        $this->assertSame($drum['unitId'], $data['unit']['id']);
        $this->assertSame([], $data['runs']['recent']);
        $this->assertSame(0, $data['runs']['missed_count']);
        $this->assertSame(0.0, (float) $data['totals']['input_kg']);
        $this->assertFalse($data['schedule']['enabled'], 'A never-published schedule reads disabled.');
    }

    public function testMonitorReportsLivenessFromTheHeartbeat(): void
    {
        $drum = $this->drumWithBoard();

        $this->withHeaders(['X-Device-Token' => $drum['token']])
            ->withBodyFormat('json')
            ->call('post', 'api/v1/devices/bmg/heartbeat', [
                'rtc_epoch' => time(),
                'rtc_valid' => 1,
                'motor_state' => 'idle',
            ])
            ->assertStatus(200);

        $res = $this->authed($this->token(), 'get', "api/v1/facilities/units/{$drum['unitId']}/monitor");
        $res->assertStatus(200);
        $device = $this->envelope($res)['data']['device'];

        $this->assertNotNull($device);
        $this->assertTrue($device['online'], 'A just-heartbeated board is online.');
        $this->assertTrue($device['rtc_valid']);
        $this->assertSame('idle', $device['motor_state']);
        $this->assertLessThanOrEqual(1, $device['heartbeat_age_minutes']);

        // Backdate the heartbeat past the threshold → offline.
        db_connect()->table('facilities_bmg_devices')
            ->where('id', $drum['deviceId'])
            ->update(['last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);

        $res = $this->authed($this->token(), 'get', "api/v1/facilities/units/{$drum['unitId']}/monitor");
        $device = $this->envelope($res)['data']['device'];
        $this->assertFalse($device['online'], 'An hour-old heartbeat is offline at the 20-min default.');
    }

    public function testMonitorSurfacesRunsAndVoidAwareTotals(): void
    {
        $drum = $this->drumWithBoard();

        // Two runs: one completed (no batch), one failed.
        foreach ([['run-a', 'completed'], ['run-b', 'failed']] as [$uid, $result]) {
            $this->withHeaders(['X-Device-Token' => $drum['token']])
                ->withBodyFormat('json')
                ->call('post', 'api/v1/devices/bmg/runs', [
                    'run_uid'          => $uid . '-' . bin2hex(random_bytes(4)),
                    'origin'           => 'scheduled',
                    'result'           => $result,
                    'duration_seconds' => 10,
                ])
                ->assertStatus(201);
        }

        // Manual I/O: one live input, one voided — totals count only live.
        $batch = $this->postJson("api/v1/facilities/units/{$drum['unitId']}/start", [
            'total_input_weight_kg' => 10,
            'composition'           => [],
        ], 201);
        $batchId = (int) $batch['data']['id'];

        $this->postJson("api/v1/facilities/batches/{$batchId}/inputs", ['weight_kg' => 6], 201);
        $voided = $this->postJson("api/v1/facilities/batches/{$batchId}/inputs", ['weight_kg' => 4], 201);
        $this->postJson("api/v1/facilities/batches/{$batchId}/inputs/{$voided['data']['id']}/void", [
            'reason' => 'Wrong weight',
        ], 200);

        $res = $this->authed($this->token(), 'get', "api/v1/facilities/units/{$drum['unitId']}/monitor");
        $res->assertStatus(200);
        $data = $this->envelope($res)['data'];

        $this->assertCount(2, $data['runs']['recent']);
        $this->assertSame(1, $data['runs']['failed_count'], 'The failed run is counted.');
        $this->assertEqualsWithDelta(6.0, (float) $data['totals']['input_kg'], 0.001, 'Voided input drops out of totals.');
        $this->assertNotNull($data['active_batch']);
        $this->assertSame($batchId, $data['active_batch']['id']);
    }

    public function testPublishingAScheduleDoesNotBlameTheBoardForSlotsBeforeIt(): void
    {
        // The reported bug: publish 23:00 / 23:05 / 23:10 at 22:57 and the
        // monitor instantly claimed yesterday's three slots were missed.
        // Clock-relative so the test never goes stale: slots 40/45/50 min
        // ago (Manila), i.e. past their grace window on BOTH look-back days.
        $drum  = $this->drumWithBoard();
        $manila = new \DateTimeZone('Asia/Manila');
        $now    = new \DateTimeImmutable('now', $manila);
        $times  = [
            $now->modify('-50 minutes')->format('H:i'),
            $now->modify('-45 minutes')->format('H:i'),
            $now->modify('-40 minutes')->format('H:i'),
        ];
        sort($times);

        $this->postJson("api/v1/facilities/units/{$drum['unitId']}/schedule", [
            'times'            => $times,
            'duration_seconds' => 10,
            'pwm'              => 200,
            'enabled'          => true,
        ]);

        $monitor = $this->envelope($this->authed($this->token(), 'get', "api/v1/facilities/units/{$drum['unitId']}/monitor"))['data'];
        $this->assertSame([], $monitor['runs']['missed_times'], 'Slots that fell before publish were never due.');
        $this->assertSame(0, $monitor['runs']['missed_count']);

        // The same schedule, but published three days ago and never run:
        // now those slots genuinely were missed and must still surface.
        db_connect()->table('facilities_bmg_unit_schedules')
            ->where('unit_id', $drum['unitId'])
            ->update(['updated_at' => gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);

        $monitor = $this->envelope($this->authed($this->token(), 'get', "api/v1/facilities/units/{$drum['unitId']}/monitor"))['data'];
        $this->assertGreaterThan(0, $monitor['runs']['missed_count'], 'Real misses after the schedule was live still show.');
    }

    public function testMonitorIsReadGated(): void
    {
        $drum = $this->drumWithBoard();

        // A user whose groups carry no facilities read permission cannot
        // poll the drum's automation state.
        $outsider = $this->login(['clinic_staff']);
        $res      = $this->authed($outsider['token'], 'get', "api/v1/facilities/units/{$drum['unitId']}/monitor");
        $res->assertStatus(403);
    }
}
