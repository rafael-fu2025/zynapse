<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Drum ↔ ESP32 integration (1:1) — the registry-level invariants:
 *
 *  - Creating a drum REQUIRES a registered ESP32 (`device_id`); a drum
 *    is refused when the payload omits it, names an unknown board, or
 *    names a board another drum already holds — so a fleet whose
 *    devices are all bound cannot grow (register a board first).
 *  - The bound board rides back on every unit payload
 *    (`device_code` / `device_status` …) for the drum screens.
 *  - Reassignment from the drum swaps boards: the old board returns to
 *    the available pool. An explicit null unbinds.
 *  - Device-side rebinding refuses a drum another board already holds
 *    (registration and patch paths alike) instead of silently stealing
 *    the binding.
 *  - Archiving a drum releases its board for reuse.
 */
final class BmgDeviceBindingTest extends FeatureTestCase
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

    /** @return array<string, mixed> */
    private function getJson(string $route): array
    {
        $res = $this->authed($this->token(), 'get', $route);
        $res->assertStatus(200);
        return $this->envelope($res);
    }

    /** Create a drum bound to `$deviceId` with a unique code. */
    private function createUnit(int $deviceId, string $name = 'Binding Drum'): array
    {
        $suffix = bin2hex(random_bytes(4));
        return $this->postJson('api/v1/facilities/units', [
            'code'         => "bnd-unit-{$suffix}",
            'display_name' => "{$name} {$suffix}",
            'device_id'    => $deviceId,
        ], 201);
    }

    private function deviceRow(int $deviceId): ?array
    {
        return db_connect()->table('facilities_bmg_devices')
            ->where('id', $deviceId)
            ->get()->getRowArray();
    }

    // --- creation ---------------------------------------------------------

    public function testCreateUnitWithoutDeviceIs422(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units', [
            'code'         => "bnd-nodev-{$suffix}",
            'display_name' => "No Device Drum {$suffix}",
        ]);
        $res->assertStatus(422);
        $body = $this->envelope($res);
        $fields = array_column($body['errors'], 'field');
        $this->assertContains('device_id', $fields, 'The refusal must name the device_id field.');
    }

    public function testCreateUnitBindsDeviceAndSurfacesItOnTheUnit(): void
    {
        $device = $this->createBmgDevice();
        $unit   = $this->createUnit($device['deviceId']);

        $data = $unit['data'];
        $this->assertSame($device['deviceId'], $data['device_id']);
        $this->assertSame($device['code'], $data['device_code']);
        $this->assertSame('active', $data['device_status']);

        // The registry agrees: the device now points at the drum.
        $row = $this->deviceRow($device['deviceId']);
        $this->assertSame((int) $data['id'], (int) $row['unit_id']);

        // ...and the units list carries the same join for the drum grid.
        $units = $this->getJson('api/v1/facilities/units?limit=100');
        $mine  = array_values(array_filter(
            $units['data'],
            static fn (array $u): bool => (int) $u['id'] === (int) $data['id'],
        ));
        $this->assertCount(1, $mine);
        $this->assertSame($device['code'], $mine[0]['device_code']);
    }

    public function testCreateUnitWithUnknownDeviceIs422(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units', [
            'code'         => "bnd-ghost-{$suffix}",
            'display_name' => "Ghost Device Drum {$suffix}",
            'device_id'    => 999999999,
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.invalid', $res);
    }

    public function testCreateUnitWithAlreadyBoundDeviceIs409(): void
    {
        $device = $this->createBmgDevice();
        $first  = $this->createUnit($device['deviceId']);

        $suffix = bin2hex(random_bytes(4));
        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units', [
            'code'         => "bnd-second-{$suffix}",
            'display_name' => "Second Drum {$suffix}",
            'device_id'    => $device['deviceId'],
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('resource.conflict', $res);

        // The refusal must name the drum holding the board, so the
        // operator knows where it went.
        $message = (string) ($this->envelope($res)['errors'][0]['message'] ?? '');
        $this->assertStringContainsString((string) $first['data']['code'], $message);
    }

    public function testExhaustedFleetCannotCreateDrums(): void
    {
        // The whole point of the rule: one board, one drum. With the
        // only device bound, BOTH escape hatches are closed — reusing
        // the bound board is a 409, omitting it is a 422. Registering a
        // new board is the only way forward.
        $device = $this->createBmgDevice();
        $this->createUnit($device['deviceId']);

        $suffix = bin2hex(random_bytes(4));
        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units', [
            'code'         => "bnd-full-{$suffix}",
            'display_name' => "Fleet Full Drum {$suffix}",
            'device_id'    => $device['deviceId'],
        ]);
        $res->assertStatus(409);

        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units', [
            'code'         => "bnd-full2-{$suffix}",
            'display_name' => "Fleet Full Drum 2 {$suffix}",
        ]);
        $res->assertStatus(422);
    }

    // --- reassignment from the drum side -----------------------------------

    public function testReassigningSwapsBoardsAndFreesTheOldOne(): void
    {
        $first  = $this->createBmgDevice();
        $second = $this->createBmgDevice();
        $unit   = $this->createUnit($first['deviceId']);

        $updated = $this->postJson('api/v1/facilities/units/' . $unit['data']['id'], [
            'device_id' => $second['deviceId'],
        ]);

        $this->assertSame($second['deviceId'], $updated['data']['device_id']);
        $this->assertSame($second['code'], $updated['data']['device_code']);
        $this->assertNull($this->deviceRow($first['deviceId'])['unit_id'], 'The swapped-out board must return to the available pool.');
        $this->assertSame($unit['data']['id'], (int) $this->deviceRow($second['deviceId'])['unit_id']);
    }

    public function testReassigningToABoardHeldElsewhereIs409(): void
    {
        $board  = $this->createBmgDevice();
        $other  = $this->createBmgDevice();
        $held   = $this->createUnit($other['deviceId']);
        $unit   = $this->createUnit($board['deviceId']);

        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units/' . $unit['data']['id'], [
            'device_id' => $other['deviceId'],
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('resource.conflict', $res);

        // Unchanged: the refused board stays on its original drum.
        $this->assertSame($held['data']['id'], (int) $this->deviceRow($other['deviceId'])['unit_id']);
    }

    public function testExplicitNullUnbindsTheDrum(): void
    {
        $device = $this->createBmgDevice();
        $unit   = $this->createUnit($device['deviceId']);

        $updated = $this->postJson('api/v1/facilities/units/' . $unit['data']['id'], [
            'device_id' => null,
        ]);

        $this->assertNull($updated['data']['device_id']);
        $this->assertNull($this->deviceRow($device['deviceId'])['unit_id']);
    }

    // --- device-side guard --------------------------------------------------

    public function testDevicePatchIntoAHeldDrumIs409(): void
    {
        $board = $this->createBmgDevice();
        $unit  = $this->createUnit($board['deviceId']);
        $other = $this->createBmgDevice();

        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/devices/' . $other['deviceId'], [
            'unit_id' => (int) $unit['data']['id'],
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('resource.conflict', $res);
    }

    public function testDeviceRegistrationIntoAHeldDrumIs409(): void
    {
        $board = $this->createBmgDevice();
        $unit  = $this->createUnit($board['deviceId']);

        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/devices', [
            'code'    => 'dev-reg-' . bin2hex(random_bytes(4)),
            'unit_id' => (int) $unit['data']['id'],
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('resource.conflict', $res);
    }

    // --- lifecycle ------------------------------------------------------------

    public function testArchivedDrumReleasesItsBoard(): void
    {
        $device = $this->createBmgDevice();
        $unit   = $this->createUnit($device['deviceId']);

        $res = $this->authed($this->token(), 'delete', 'api/v1/facilities/units/' . $unit['data']['id']);
        $res->assertStatus(200);

        $this->assertNull($this->deviceRow($device['deviceId'])['unit_id'], 'Archiving a drum must free its ESP32 for reuse.');

        // ...and the freed board can integrate a new drum right away.
        $next = $this->createUnit($device['deviceId'], 'Reuse Drum');
        $this->assertSame($device['deviceId'], $next['data']['device_id']);
    }

    // --- relocation at archive time -------------------------------------------

    /** A live drum with a FREE device slot: created with a board, then unbound. */
    private function createFreeSlotDrum(string $name): array
    {
        $board = $this->createBmgDevice();
        $unit  = $this->createUnit($board['deviceId'], $name);
        $this->postJson('api/v1/facilities/units/' . $unit['data']['id'], ['device_id' => null]);
        return $unit;
    }

    public function testArchiveRelocatesTheBoardToTheTargetDrum(): void
    {
        $board  = $this->createBmgDevice();
        $unit   = $this->createUnit($board['deviceId'], 'Retire Drum');
        $target = $this->createFreeSlotDrum('Relocation Target');

        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units/' . $unit['data']['id'] . '/archive', [
            'relocate_device_to_unit_id' => (int) $target['data']['id'],
        ]);
        $res->assertStatus(200);

        // The board moved as part of the archive; the retiring drum is gone.
        $this->assertSame((int) $target['data']['id'], (int) $this->deviceRow($board['deviceId'])['unit_id']);
        $archived = db_connect()->table('facilities_bmg_units')->where('id', $unit['data']['id'])->get()->getRowArray();
        $this->assertNotNull($archived['archived_at']);
    }

    public function testArchiveRelocateToAHeldDrumIs409AndRollsBack(): void
    {
        $board  = $this->createBmgDevice();
        $unit   = $this->createUnit($board['deviceId'], 'Retire Drum');
        $held   = $this->createBmgDevice();
        $target = $this->createUnit($held['deviceId'], 'Held Drum');

        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units/' . $unit['data']['id'] . '/archive', [
            'relocate_device_to_unit_id' => (int) $target['data']['id'],
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('resource.conflict', $res);

        // Atomicity: the failed relocation must not have archived the drum.
        $row = db_connect()->table('facilities_bmg_units')->where('id', $unit['data']['id'])->get()->getRowArray();
        $this->assertNull($row['archived_at']);
        $this->assertSame($unit['data']['id'], (int) $this->deviceRow($board['deviceId'])['unit_id']);
    }

    public function testArchiveRelocateToItsOwnDrumIs422(): void
    {
        $board = $this->createBmgDevice();
        $unit  = $this->createUnit($board['deviceId'], 'Retire Drum');

        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units/' . $unit['data']['id'] . '/archive', [
            'relocate_device_to_unit_id' => (int) $unit['data']['id'],
        ]);
        $res->assertStatus(422);

        $row = db_connect()->table('facilities_bmg_units')->where('id', $unit['data']['id'])->get()->getRowArray();
        $this->assertNull($row['archived_at']);
    }

    public function testArchiveRelocateOnADevicelessDrumIs422(): void
    {
        $board = $this->createBmgDevice();
        $unit  = $this->createUnit($board['deviceId'], 'Deviceless Drum');
        $this->postJson('api/v1/facilities/units/' . $unit['data']['id'], ['device_id' => null]);
        $target = $this->createFreeSlotDrum('Target Drum');

        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units/' . $unit['data']['id'] . '/archive', [
            'relocate_device_to_unit_id' => (int) $target['data']['id'],
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.invalid', $res);
    }
}
