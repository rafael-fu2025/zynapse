<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Unit start through the integrated ESP32 — the outbound half of the
 * device integration:
 *
 *  - Starting a batch COMMANDS the drum's bound board (`start` command
 *    queued for the device's poll). A drum without a live board, or
 *    with a disabled one, cannot start a batch at all — the machine
 *    has no controller.
 *  - The board fetches its pending commands (device-token auth) and
 *    ACKs after actuating. Delivery is at-least-once: fetching marks
 *    `delivered_at` but the row stays `pending` until the ACK, so a
 *    crashed board re-receives the command and dedupes by id.
 *  - Device identity scopes every lookup: a board never sees or acks
 *    another board's commands.
 */
final class BmgDeviceCommandTest extends FeatureTestCase
{
    /** @var array{token:string, userId:int, email:string} */
    // Untyped + protected: CI4's CIUnitTestCase declares $session and a
    // redeclaration may not narrow visibility or add a type (fatal).
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

    /** Category + drum with a bound board. Returns the board's ingest token. */
    private function createDrumWithBoard(): array
    {
        $device = $this->createBmgDevice();
        $suffix = bin2hex(random_bytes(4));
        $unit   = $this->postJson('api/v1/facilities/units', [
            'code'         => "cmd-unit-{$suffix}",
            'display_name' => "Command Drum {$suffix}",
            'device_id'    => $device['deviceId'],
        ], 201);
        return [
            'unitId'      => (int) $unit['data']['id'],
            'deviceId'    => $device['deviceId'],
            'deviceCode'  => $device['code'],
            'deviceToken' => $device['token'],
        ];
    }

    /** Start a batch on a drum (unprofiled → any category). */
    private function startBatch(int $unitId): array
    {
        $suffix = bin2hex(random_bytes(4));
        $cat    = $this->postJson('api/v1/facilities/waste-categories', [
            'code' => "cmd-cat-{$suffix}",
            'name' => "Command Category {$suffix}",
        ], 201);

        return $this->postJson("api/v1/facilities/units/{$unitId}/start", [
            'total_input_weight_kg' => 10,
            'composition'           => [['category_id' => (int) $cat['data']['id'], 'weight_kg' => 10]],
        ], 201);
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

    private function commandsFor(int $deviceId): array
    {
        return db_connect()->table('facilities_bmg_device_commands')
            ->where('device_id', $deviceId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    // --- the start gate ----------------------------------------------------

    public function testStartBatchRequiresABoundDevice(): void
    {
        $drum = $this->createDrumWithBoard();
        // Replacement window: the board was pulled off the drum.
        $this->postJson('api/v1/facilities/units/' . $drum['unitId'], ['device_id' => null]);

        $cat   = $this->postJson('api/v1/facilities/waste-categories', [
            'code' => 'cmd-cat-' . bin2hex(random_bytes(4)),
            'name' => 'Gate Category',
        ], 201);
        $res = $this->authed($this->token(), 'post', "api/v1/facilities/units/{$drum['unitId']}/start", [
            'total_input_weight_kg' => 10,
            'composition'           => [['category_id' => (int) $cat['data']['id'], 'weight_kg' => 10]],
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.bmg.unit_has_no_device', $res);
    }

    public function testStartBatchRefusesADisabledBoard(): void
    {
        $drum = $this->createDrumWithBoard();
        db_connect()->table('facilities_bmg_devices')
            ->where('id', $drum['deviceId'])
            ->update(['status' => 'disabled']);

        $cat   = $this->postJson('api/v1/facilities/waste-categories', [
            'code' => 'cmd-cat-' . bin2hex(random_bytes(4)),
            'name' => 'Gate Category 2',
        ], 201);
        $res = $this->authed($this->token(), 'post', "api/v1/facilities/units/{$drum['unitId']}/start", [
            'total_input_weight_kg' => 10,
            'composition'           => [['category_id' => (int) $cat['data']['id'], 'weight_kg' => 10]],
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.bmg.unit_device_disabled', $res);
    }

    // --- the command channel -------------------------------------------------

    public function testStartBatchQueuesAStartCommandForTheBoard(): void
    {
        $drum  = $this->createDrumWithBoard();
        $batch = $this->startBatch($drum['unitId']);

        $commands = $this->commandsFor($drum['deviceId']);
        $this->assertCount(1, $commands);
        $this->assertSame('start', $commands[0]['command']);
        $this->assertSame('pending', $commands[0]['status']);
        $this->assertNull($commands[0]['delivered_at']);
        $payload = json_decode((string) $commands[0]['payload'], true);
        $this->assertSame($batch['data']['reference_code'], $payload['batch_reference']);
    }

    public function testWeightOnlyStartStartsTheBatchAndCommandsTheBoard(): void
    {
        // Starting the drum IS starting the batch: no waste categories
        // here — the mix was designated on the drum, so the operator
        // sends only the loaded weight. The board still gets its start
        // command, and the batch's ETA falls back to the drum's first
        // designated category.
        $c1    = $this->postJson('api/v1/facilities/waste-categories', [
            'code'                    => 'cmd-wonly-' . bin2hex(random_bytes(4)),
            'name'                    => 'Weight Only Category',
            'reference_duration_days' => 21,
        ], 201);
        $drum  = $this->createDrumWithBoard();
        $this->postJson('api/v1/facilities/units/' . $drum['unitId'], [
            'category_ids' => [(int) $c1['data']['id']],
        ]);

        $batch = $this->postJson("api/v1/facilities/units/{$drum['unitId']}/start", [
            'total_input_weight_kg' => 8,
        ], 201);
        $this->assertSame('processing', $batch['data']['status']);

        // The board was commanded, and the drum's designation drove the ETA.
        $commands = $this->commandsFor($drum['deviceId']);
        $this->assertCount(1, $commands);
        $this->assertSame('start', $commands[0]['command']);
        $row = db_connect()->table('facilities_bmg_batches')->where('id', (int) $batch['data']['id'])->get()->getRowArray();
        $this->assertNotNull($row['expected_completion_date'], "ETA falls back to the drum's designated category.");
    }

    public function testDevicePollsCommandsThenAcks(): void
    {
        $drum  = $this->createDrumWithBoard();
        $batch = $this->startBatch($drum['unitId']);

        // First poll delivers.
        $poll = $this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands');
        $poll->assertStatus(200);
        $feed = $this->envelope($poll)['data'];
        $this->assertCount(1, $feed);
        $this->assertSame('start', $feed[0]['command']);
        $this->assertSame('pending', $feed[0]['status']);
        $this->assertSame($batch['data']['reference_code'], $feed[0]['payload']['batch_reference']);
        $this->assertNotNull($feed[0]['delivered_at'], 'Polling must stamp delivery.');
        $this->assertNull($this->commandsFor($drum['deviceId'])[0]['acknowledged_at']);

        // At-least-once: still pending (and re-fetched) until ACKed.
        $repoll = $this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands');
        $this->assertCount(1, $this->envelope($repoll)['data']);

        $ack = $this->devicePost($drum['deviceToken'], 'api/v1/devices/bmg/commands/ack', [
            'command_id' => (int) $feed[0]['id'],
        ]);
        $ack->assertStatus(200);
        $this->assertSame('acknowledged', $this->envelope($ack)['data']['status']);

        // Acknowledged commands leave the feed; re-acking is idempotent.
        $after = $this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands');
        $this->assertCount(0, $this->envelope($after)['data']);
        $reack = $this->devicePost($drum['deviceToken'], 'api/v1/devices/bmg/commands/ack', [
            'command_id' => (int) $feed[0]['id'],
        ]);
        $reack->assertStatus(200);
        $this->assertSame('acknowledged', $this->envelope($reack)['data']['status']);
        $row = $this->commandsFor($drum['deviceId'])[0];
        $this->assertSame('acknowledged', $row['status']);
        $this->assertNotNull($row['acknowledged_at']);
    }

    public function testDeviceSeesOnlyItsOwnCommands(): void
    {
        $drumA = $this->createDrumWithBoard();
        $drumB = $this->createDrumWithBoard();
        $batch = $this->startBatch($drumA['unitId']);
        $commandId = (int) $this->commandsFor($drumA['deviceId'])[0]['id'];

        // B's feed is empty, and B cannot ack A's command.
        $poll = $this->deviceGet($drumB['deviceToken'], 'api/v1/devices/bmg/commands');
        $this->assertCount(0, $this->envelope($poll)['data']);

        $ack = $this->devicePost($drumB['deviceToken'], 'api/v1/devices/bmg/commands/ack', [
            'command_id' => $commandId,
        ]);
        $ack->assertStatus(404);
        $this->assertErrorCode('resource.not_found', $ack);

        // A's command is untouched.
        $this->assertSame('pending', $this->commandsFor($drumA['deviceId'])[0]['status']);
        $this->assertSame($batch['data']['reference_code'], json_decode((string) $this->commandsFor($drumA['deviceId'])[0]['payload'], true)['batch_reference']);
    }

    public function testCommandFeedRejectsNonDeviceTokens(): void
    {
        // The device surface is disjoint from the human JWT surface: an
        // operator's bearer token opens none of it.
        $res = $this->authed($this->token(), 'get', 'api/v1/devices/bmg/commands');
        $res->assertStatus(401);
        $this->assertErrorCode('device.unauthorized', $res);

        $res = $this->devicePost('dev_' . bin2hex(random_bytes(32)), 'api/v1/devices/bmg/commands/ack', ['command_id' => 1]);
        $res->assertStatus(401);
    }

    public function testFinishBatchQueuesStopCommandForBoard(): void
    {
        $drum  = $this->createDrumWithBoard();
        $batch = $this->startBatch($drum['unitId']);
        $batchId = (int) $batch['data']['id'];

        // Finish the batch
        $finish = $this->postJson("api/v1/facilities/batches/{$batchId}/finish", [
            'quality_grade'  => 'good',
            'maturity_level' => 'mature',
        ]);
        $this->assertSame('released', $finish['data']['status']);

        // Board must have a 'stop' command enqueued
        $commands = $this->commandsFor($drum['deviceId']);
        $this->assertCount(2, $commands);
        $this->assertSame('start', $commands[0]['command']);
        $this->assertSame('stop', $commands[1]['command']);
        $this->assertSame('pending', $commands[1]['status']);
    }

    public function testCancelBatchQueuesStopCommandAndFailsPendingStart(): void
    {
        $drum  = $this->createDrumWithBoard();
        $batch = $this->startBatch($drum['unitId']);
        $batchId = (int) $batch['data']['id'];

        // Cancel the batch
        $cancel = $this->postJson("api/v1/facilities/batches/{$batchId}/cancel", [
            'reason_code' => 'contamination',
        ]);
        $this->assertSame('cancelled', $cancel['data']['status']);

        $commands = $this->commandsFor($drum['deviceId']);
        $this->assertCount(2, $commands);
        // The pending start command must be marked failed
        $this->assertSame('start', $commands[0]['command']);
        $this->assertSame('failed', $commands[0]['status']);
        // And a new stop command is pending
        $this->assertSame('stop', $commands[1]['command']);
        $this->assertSame('pending', $commands[1]['status']);
    }

    public function testDevicePollingUpdatesLastSeenAt(): void
    {
        $drum = $this->createDrumWithBoard();
        $this->startBatch($drum['unitId']);

        $before = db_connect()->table('facilities_bmg_devices')
            ->where('id', $drum['deviceId'])->get()->getRowArray();
        $this->assertNull($before['last_seen_at']);

        // Poll commands
        $poll = $this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands');
        $poll->assertStatus(200);

        $after = db_connect()->table('facilities_bmg_devices')
            ->where('id', $drum['deviceId'])->get()->getRowArray();
        $this->assertNotNull($after['last_seen_at'], 'Polling must keep last_seen_at fresh.');
    }

    public function testAdminCanSendManualStartAndStopCommands(): void
    {
        $drum = $this->createDrumWithBoard();

        // Send manual start command
        $startRes = $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", [
            'command' => 'start',
        ], 201);
        $this->assertSame('start', $startRes['data']['command']);
        $this->assertTrue($startRes['data']['enqueued']);

        // Send manual stop command
        $stopRes = $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", [
            'command' => 'stop',
        ], 201);
        $this->assertSame('stop', $stopRes['data']['command']);

        // Command history
        $hist = $this->authed($this->token(), 'get', "api/v1/facilities/devices/{$drum['deviceId']}/commands");
        $hist->assertStatus(200);
        $items = $this->envelope($hist)['data'];
        $this->assertCount(2, $items);
        $this->assertSame('stop', $items[0]['command']);
        $this->assertSame('start', $items[1]['command']);
        // Stop failed the pending start
        $this->assertSame('failed', $items[1]['status']);
    }

    // --- run-now + command TTL ----------------------------------------------

    public function testRunNowIsQueuedAndDeliveredToTheBoard(): void
    {
        $drum = $this->createDrumWithBoard();

        $res = $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", [
            'command' => 'run_now',
        ], 201);
        $this->assertSame('run_now', $res['data']['command']);

        // The board sees it on its next poll, with a TTL stamped.
        $feed = $this->envelope($this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands'))['data'];
        $this->assertCount(1, $feed);
        $this->assertSame('run_now', $feed[0]['command']);
        $this->assertNotNull($feed[0]['expires_at'], 'Every queued command carries a deadline.');
        $this->assertTrue($feed[0]['payload']['manual']);
    }

    public function testStopSupersedesAPendingRunNow(): void
    {
        $drum = $this->createDrumWithBoard();

        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'run_now'], 201);
        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'stop'], 201);

        $feed = $this->envelope($this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands'))['data'];
        $this->assertCount(1, $feed, 'The superseded run-now must not be delivered.');
        $this->assertSame('stop', $feed[0]['command']);

        $commands = $this->commandsFor($drum['deviceId']);
        $this->assertSame('failed', $commands[0]['status']);
        $this->assertSame('pending', $commands[1]['status']);
    }

    public function testExpiredCommandsAreNotDelivered(): void
    {
        $drum = $this->createDrumWithBoard();

        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'run_now'], 201);

        // Age the command past its deadline — the board was offline and
        // comes back later; an old "run now" must not fire.
        db_connect()->table('facilities_bmg_device_commands')
            ->where('device_id', $drum['deviceId'])
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);

        $feed = $this->envelope($this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands'))['data'];
        $this->assertCount(0, $feed, 'Stale pending commands expire instead of delivering.');
        $this->assertSame('failed', $this->commandsFor($drum['deviceId'])[0]['status']);
    }

    // --- expiry tells staff -----------------------------------------------

    /** Age every pending command of a board past its deadline. */
    private function expireCommandsOf(int $deviceId): void
    {
        db_connect()->table('facilities_bmg_device_commands')
            ->where('device_id', $deviceId)
            ->where('status', 'pending')
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
    }

    /** Expiry notifications for one drum (the shared outbox accumulates across the suite). */
    private function expiryNoticesFor(string $unitCode): array
    {
        return db_connect()->table('notification_outbox')
            ->where('template_code', 'bmg.device_command_expired')
            ->like('context_json', '"' . $unitCode . '"')
            ->get()->getResultArray();
    }

    private function unitCode(int $unitId): string
    {
        return (string) db_connect()->table('facilities_bmg_units')->where('id', $unitId)->get()->getRowArray()['code'];
    }

    private function runWatchdog(): void
    {
        $command = new \App\Commands\BmgDeviceWatchdog(service('logger'), service('commands'));
        $this->assertSame(0, $command->run([]));
    }

    public function testWatchdogTellsStaffWhenAStartNeverReachedAnOfflineBoard(): void
    {
        $drum = $this->createDrumWithBoard();
        $code = $this->unitCode($drum['unitId']);
        $this->startBatch($drum['unitId']);

        // The board is offline: it never polls, so the lazy expiry in the
        // command feed cannot run. Only the watchdog can notice.
        $this->expireCommandsOf($drum['deviceId']);
        $this->runWatchdog();

        $this->assertSame('failed', $this->commandsFor($drum['deviceId'])[0]['status']);
        $notices = $this->expiryNoticesFor($code);
        $this->assertNotEmpty($notices, 'An expired start on an offline board must notify staff.');
        $context = json_decode((string) $notices[0]['context_json'], true);
        $this->assertSame('start', $context['next_status']);
        $this->assertSame('warning', $context['urgency']);

        // Settled: a second sweep must not notify again.
        $before = count($notices);
        $this->runWatchdog();
        $this->assertCount($before, $this->expiryNoticesFor($code));
    }

    public function testBoardPollThatFindsAnExpiredCommandNotifiesExactlyOnce(): void
    {
        $drum = $this->createDrumWithBoard();
        $code = $this->unitCode($drum['unitId']);

        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'run_now'], 201);
        $this->expireCommandsOf($drum['deviceId']);

        // The board reappears and polls first …
        $this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands')->assertStatus(200);
        $afterPoll = count($this->expiryNoticesFor($code));
        $this->assertGreaterThan(0, $afterPoll);

        // … so the watchdog finds the row already settled.
        $this->runWatchdog();
        $this->assertCount($afterPoll, $this->expiryNoticesFor($code), 'One expired command, one notification.');
    }

    public function testExpiredStopIsCriticalBecauseTheDrumMayStillBeTurning(): void
    {
        $drum = $this->createDrumWithBoard();
        $code = $this->unitCode($drum['unitId']);

        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'stop'], 201);
        $this->expireCommandsOf($drum['deviceId']);
        $this->runWatchdog();

        $notices = $this->expiryNoticesFor($code);
        $this->assertNotEmpty($notices);
        $context = json_decode((string) $notices[0]['context_json'], true);
        $this->assertSame('stop', $context['next_status']);
        $this->assertSame('critical', $context['urgency']);
    }

    public function testAcknowledgedAndSupersededCommandsNeverRaiseAnExpiryNotice(): void
    {
        $drum = $this->createDrumWithBoard();
        $code = $this->unitCode($drum['unitId']);

        // 1) acknowledged in time, deadline passes afterwards
        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'run_now'], 201);
        $feed = $this->envelope($this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands'))['data'];
        $this->devicePost($drum['deviceToken'], 'api/v1/devices/bmg/commands/ack', ['command_id' => $feed[0]['id']])->assertStatus(200);

        // 2) superseded by a later stop (the run_now is failed on purpose)
        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'run_now'], 201);
        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'stop'], 201);

        // The only still-pending command is the stop; settle it as acked too.
        $feed = $this->envelope($this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands'))['data'];
        $this->devicePost($drum['deviceToken'], 'api/v1/devices/bmg/commands/ack', ['command_id' => $feed[0]['id']])->assertStatus(200);

        db_connect()->table('facilities_bmg_device_commands')
            ->where('device_id', $drum['deviceId'])
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->runWatchdog();
        $this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands')->assertStatus(200);

        $this->assertSame([], $this->expiryNoticesFor($code), 'Settled commands must stay quiet.');
    }

    public function testStaleExpiredBacklogIsSettledSilently(): void
    {
        $drum = $this->createDrumWithBoard();
        $code = $this->unitCode($drum['unitId']);

        // A start that expired two days ago: old news, not an incident.
        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'start'], 201);
        db_connect()->table('facilities_bmg_device_commands')
            ->where('device_id', $drum['deviceId'])
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
        $this->runWatchdog();

        $this->assertSame('failed', $this->commandsFor($drum['deviceId'])[0]['status'], 'The backlog is still settled.');
        $this->assertSame([], $this->expiryNoticesFor($code), 'But staff are not told about it.');
    }

    public function testDisabledBoardsLeftoverCommandsAreNotAnIncident(): void
    {
        $drum = $this->createDrumWithBoard();
        $code = $this->unitCode($drum['unitId']);

        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/commands", ['command' => 'run_now'], 201);
        // Staff switched the board off on purpose.
        $this->postJson("api/v1/facilities/devices/{$drum['deviceId']}/status", ['status' => 'disabled']);
        $this->expireCommandsOf($drum['deviceId']);
        $this->runWatchdog();

        $this->assertSame('failed', $this->commandsFor($drum['deviceId'])[0]['status']);
        $this->assertSame([], $this->expiryNoticesFor($code));
    }

    public function testCommandsAndSchedulesAreAdminOnly(): void
    {
        $drum     = $this->createDrumWithBoard();
        $operator = $this->login(['facilities_op']);

        // Reaching a motor over the network is an administrative act.
        $send = $this->authed($operator['token'], 'post', "api/v1/facilities/devices/{$drum['deviceId']}/commands", [
            'command' => 'run_now',
        ]);
        $send->assertStatus(403);

        $schedule = $this->authed($operator['token'], 'post', "api/v1/facilities/units/{$drum['unitId']}/schedule", [
            'times'            => ['09:00'],
            'duration_seconds' => 10,
            'pwm'              => 200,
            'enabled'          => true,
        ]);
        $schedule->assertStatus(403);

        // The board's command feed itself is unaffected.
        $this->deviceGet($drum['deviceToken'], 'api/v1/devices/bmg/commands')->assertStatus(200);
    }
}
