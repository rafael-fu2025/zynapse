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
}
