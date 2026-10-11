<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Device heartbeat + run ingest for the mechanized tumblers.
 *
 *  - Heartbeat stores liveness + telemetry, works for an UNBOUND board
 *    (liveness does not depend on a drum), measures RTC drift at
 *    receipt, and answers the flat `{"ok":true}` shape.
 *  - Runs are idempotent per `run_uid`; a run WITH an active batch
 *    attaches and mirrors a turning process log; a run with NO active
 *    batch is accepted (not 409'd) and flagged for staff — the whole
 *    point of the run surface over turn-sessions.
 *  - The RTC window sanity shared with turn-sessions applies: an
 *    out-of-range window falls back to receipt time, columns null.
 */
final class BmgDeviceHeartbeatTest extends FeatureTestCase
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
            'code'         => "hb-unit-{$suffix}",
            'display_name' => "Heartbeat Drum {$suffix}",
            'device_id'    => $device['deviceId'],
        ], 201);

        return ['unitId' => (int) $unit['data']['id']] + $device;
    }

    private function deviceGet(string $token, string $route): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders(['X-Device-Token' => $token])->call('get', $route);
    }

    private function devicePost(string $token, string $route, array $body): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders(['X-Device-Token' => $token])
            ->withBodyFormat('json')
            ->call('post', $route, $body);
    }

    /** Flat-JSON decode helper (device shape has no envelope). */
    private function flat(\CodeIgniter\Test\TestResponse $res): array
    {
        return json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    // --- heartbeat -----------------------------------------------------------

    public function testHeartbeatStoresTelemetryAndFlatOk(): void
    {
        $drum = $this->drumWithBoard();

        $res = $this->devicePost($drum['token'], 'api/v1/devices/bmg/heartbeat', [
            'firmware'       => 'bmg-2026.10',
            'uptime_seconds' => 12345,
            'rtc_epoch'      => time(),
            'rtc_valid'      => 1,
            'rssi_dbm'       => -61,
            'last_run_result' => 'completed',
            'motor_state'    => 'idle',
        ]);
        $res->assertStatus(200);
        $body = $this->flat($res);
        $this->assertSame(['ok' => true], $body, 'Heartbeat answers the flat {ok:true} shape.');

        $row = db_connect()->table('facilities_bmg_devices')
            ->where('id', $drum['deviceId'])->get()->getRowArray();
        $this->assertSame('bmg-2026.10', $row['firmware']);
        $this->assertSame(12345, (int) $row['last_uptime_seconds']);
        $this->assertSame(-61, (int) $row['last_rssi_dbm']);
        $this->assertSame(1, (int) $row['rtc_valid']);
        $this->assertSame('completed', $row['last_run_result']);
        $this->assertSame('idle', $row['motor_state']);
        $this->assertNotNull($row['last_heartbeat_at']);
        $this->assertNotNull($row['last_seen_at']);
        // Drift measured at receipt: |offset| within test execution noise.
        $this->assertNotNull($row['rtc_offset_seconds']);
        $this->assertLessThanOrEqual(5, abs((int) $row['rtc_offset_seconds']));
    }

    public function testHeartbeatWorksForAnUnboundBoard(): void
    {
        $device = $this->createBmgDevice(); // registered, not bound

        $res = $this->devicePost($device['token'], 'api/v1/devices/bmg/heartbeat', [
            'firmware' => 'unbound-1.0',
        ]);
        $res->assertStatus(200);
        $this->assertSame(['ok' => true], $this->flat($res));

        $row = db_connect()->table('facilities_bmg_devices')
            ->where('id', $device['deviceId'])->get()->getRowArray();
        $this->assertNotNull($row['last_heartbeat_at']);
    }

    public function testHeartbeatRejectsInvalidTelemetry(): void
    {
        $drum = $this->drumWithBoard();

        $badRssi = $this->devicePost($drum['token'], 'api/v1/devices/bmg/heartbeat', ['rssi_dbm' => 99]);
        $badRssi->assertStatus(422);

        $badRtc = $this->devicePost($drum['token'], 'api/v1/devices/bmg/heartbeat', ['rtc_valid' => 7]);
        $badRtc->assertStatus(422);
    }

    public function testInvalidRtcIsRecordedAndClearedByRecovery(): void
    {
        $drum = $this->drumWithBoard();

        $this->devicePost($drum['token'], 'api/v1/devices/bmg/heartbeat', [
            'rtc_epoch' => 0,
            'rtc_valid' => 0,
        ])->assertStatus(200);

        $db  = db_connect();
        $row = $db->table('facilities_bmg_devices')->where('id', $drum['deviceId'])->get()->getRowArray();
        $this->assertSame(0, (int) $row['rtc_valid']);
        $this->assertNull($row['rtc_offset_seconds'], 'No drift is claimed without a valid clock.');

        // Simulate the watchdog having notified once, then a recovery.
        $db->table('facilities_bmg_devices')->where('id', $drum['deviceId'])
            ->update(['rtc_notified_at' => gmdate('Y-m-d H:i:s')]);

        $this->devicePost($drum['token'], 'api/v1/devices/bmg/heartbeat', [
            'rtc_epoch' => time(),
            'rtc_valid' => 1,
        ])->assertStatus(200);

        $row = $db->table('facilities_bmg_devices')->where('id', $drum['deviceId'])->get()->getRowArray();
        $this->assertSame(1, (int) $row['rtc_valid']);
        $this->assertNull($row['rtc_notified_at'], 'A valid clock re-arms RTC alerting.');
    }

    // --- runs ----------------------------------------------------------------

    private function runPayload(array $overrides = []): array
    {
        return array_merge([
            'run_uid'          => 'run_' . bin2hex(random_bytes(8)),
            'origin'           => 'scheduled',
            'result'           => 'completed',
            'duration_seconds' => 10,
            'turns_count'      => 4,
            'started_at_epoch' => time() - 60,
            'ended_at_epoch'   => time() - 50,
        ], $overrides);
    }

    public function testRunWithoutABatchIsAcceptedAndFlagged(): void
    {
        $drum    = $this->drumWithBoard();
        $payload = $this->runPayload();

        $res = $this->devicePost($drum['token'], 'api/v1/devices/bmg/runs', $payload);
        $res->assertStatus(201);
        $body = $this->flat($res);
        $this->assertSame($payload['run_uid'], $body['run_uid']);
        $this->assertTrue($body['created']);
        $this->assertNull($body['batch_id'], 'No active batch → stored unattached, not rejected.');

        $row = db_connect()->table('facilities_bmg_device_runs')
            ->where('run_uid', $payload['run_uid'])->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertNull($row['batch_id']);
        $this->assertSame('scheduled', $row['origin']);
        $this->assertSame('completed', $row['result']);
        $this->assertNotNull($row['started_at']);

        // Staff are told: audit + notification land for the no-batch run.
        $audit = db_connect()->table('audit_outbox')
            ->where('action_code', 'bmg.device_run_no_batch')->countAllResults();
        $this->assertGreaterThan(0, $audit, 'A run outside any batch must be audited for follow-up.');

        // Idempotent replay: 200 + created=false, no duplicate row.
        $replay = $this->devicePost($drum['token'], 'api/v1/devices/bmg/runs', $payload);
        $replay->assertStatus(200);
        $this->assertFalse($this->flat($replay)['created']);
        $this->assertSame(
            1,
            db_connect()->table('facilities_bmg_device_runs')->where('run_uid', $payload['run_uid'])->countAllResults(),
        );
    }

    public function testRunWithAnActiveBatchAttachesAndMirrorsAProcessLog(): void
    {
        $drum  = $this->drumWithBoard();
        $batch = $this->postJson("api/v1/facilities/units/{$drum['unitId']}/start", [
            'total_input_weight_kg' => 10,
            'composition'           => [],
        ], 201);
        $batchId = (int) $batch['data']['id'];

        $payload = $this->runPayload(['origin' => 'manual', 'turns_count' => 6]);
        $res = $this->devicePost($drum['token'], 'api/v1/devices/bmg/runs', $payload);
        $res->assertStatus(201);
        $this->assertSame($batchId, $this->flat($res)['batch_id']);

        // Mirrored as a turning process log keyed by the run uid, so the
        // cadence alerts and analytics see the same fact.
        $log = db_connect()->table('facilities_bmg_process_logs')
            ->where('session_uid', $payload['run_uid'])->get()->getRowArray();
        $this->assertNotNull($log, 'An attached run must mirror a turning process log.');
        $this->assertSame('turning', $log['event_type']);
        $this->assertSame($batchId, (int) $log['batch_id']);
        $this->assertSame(6, (int) $log['turns_count']);
        $this->assertSame(10, (int) $log['duration_seconds']);
        $this->assertNotNull($log['session_started_at']);
    }

    public function testRunWithAnInsaneRtcWindowFallsBackToReceipt(): void
    {
        $drum = $this->drumWithBoard();

        $payload = $this->runPayload([
            'started_at_epoch' => time() - 2592000,
            'ended_at_epoch'   => time() - 2591950,
        ]);
        $res = $this->devicePost($drum['token'], 'api/v1/devices/bmg/runs', $payload);
        $res->assertStatus(201);

        $row = db_connect()->table('facilities_bmg_device_runs')
            ->where('run_uid', $payload['run_uid'])->get()->getRowArray();
        $this->assertNull($row['started_at'], 'Out-of-window RTC stamps are dropped, not trusted.');
        $this->assertNull($row['ended_at']);
        $this->assertNotNull($row['created_at'], 'Receipt time always remains.');
    }

    public function testRunValidationAndBinding(): void
    {
        $drum = $this->drumWithBoard();

        $badOrigin = $this->devicePost($drum['token'], 'api/v1/devices/bmg/runs', $this->runPayload(['origin' => 'guess']));
        $badOrigin->assertStatus(422);

        $badResult = $this->devicePost($drum['token'], 'api/v1/devices/bmg/runs', $this->runPayload(['result' => 'maybe']));
        $badResult->assertStatus(422);

        $unbound = $this->createBmgDevice();
        $res = $this->devicePost($unbound['token'], 'api/v1/devices/bmg/runs', $this->runPayload());
        $res->assertStatus(409);
        $this->assertErrorCode('device.not_bound', $res);
    }
}
