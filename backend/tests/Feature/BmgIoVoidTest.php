<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Manual waste-in / compost-out entry with void semantics.
 *
 * Staff type these by hand, so rows are VOIDED (never edited, never
 * deleted): the void marks who/why, every aggregation drops voided
 * rows, and a void that would invert the mass ledger (outputs >
 * remaining inputs) is refused. Entry is staff-wide — an operator who
 * can record I/O does not need to have STARTED the batch.
 */
final class BmgIoVoidTest extends FeatureTestCase
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
    private function postJson(string $route, array $body, int $expect = 200, ?string $token = null): array
    {
        $res = $this->authed($token ?? $this->token(), 'post', $route, $body);
        $res->assertStatus($expect);
        return $this->envelope($res);
    }

    /** A drum with a board and a running batch. */
    private function drumWithBatch(): array
    {
        $device = $this->createBmgDevice();
        $suffix = bin2hex(random_bytes(4));
        $unit   = $this->postJson('api/v1/facilities/units', [
            'code'         => "io-unit-{$suffix}",
            'display_name' => "I/O Drum {$suffix}",
            'device_id'    => $device['deviceId'],
        ], 201);

        $unitId = (int) $unit['data']['id'];
        $batch  = $this->postJson("api/v1/facilities/units/{$unitId}/start", [
            'total_input_weight_kg' => 10,
            'composition'           => [],
        ], 201);

        return ['unitId' => $unitId, 'batchId' => (int) $batch['data']['id']] + $device;
    }

    public function testInputListExposesVoidStateAndRunningTotal(): void
    {
        $drum   = $this->drumWithBatch();
        $route  = "api/v1/facilities/batches/{$drum['batchId']}";

        $first = $this->postJson("{$route}/inputs", [
            'weight_kg'   => 6.5,
            'recorded_at' => '2026-10-06 08:30:00',
            'note'        => 'Morning load',
        ], 201);
        $this->postJson("{$route}/inputs", ['weight_kg' => 3.5], 201);

        $list = $this->authed($this->token(), 'get', "{$route}/inputs");
        $list->assertStatus(200);
        $rows = $this->envelope($list)['data'];
        $this->assertCount(2, $rows);
        $this->assertSame(6.5, $rows[0]['weight_kg']);
        // JSON drops the zero fraction, so compare numerically.
        $this->assertEqualsWithDelta(10.0, $rows[1]['running_total_kg'], 0.001);
        $this->assertNull($rows[0]['voided_at']);

        // Staff-entered Manila timestamp lands in UTC (8h offset).
        $stored = db_connect()->table('facilities_bmg_inputs')
            ->where('id', (int) $first['data']['id'])->get()->getRowArray();
        $this->assertSame('2026-10-06 00:30:00', $stored['recorded_at']);

        // Voiding requires a reason and stamps the actor; totals drop the row.
        $noReason = $this->authed($this->token(), 'post', "{$route}/inputs/{$first['data']['id']}/void", []);
        $noReason->assertStatus(422);

        $void = $this->postJson("{$route}/inputs/{$first['data']['id']}/void", ['reason' => 'Wrong drum'], 200);
        $this->assertSame((int) $first['data']['id'], $void['data']['id']);

        $rows = $this->envelope($this->authed($this->token(), 'get', "{$route}/inputs"))['data'];
        $this->assertNotNull($rows[0]['voided_at'], 'Voided rows stay visible with their mark.');
        $this->assertSame('Wrong drum', $rows[0]['void_reason']);
        $this->assertSame(3.5, $rows[1]['running_total_kg'], 'Running total excludes voided rows.');

        // Double void is refused.
        $again = $this->authed($this->token(), 'post', "{$route}/inputs/{$first['data']['id']}/void", ['reason' => 'again']);
        $again->assertStatus(409);
    }

    public function testOutputVoidAndVoidAwareTotals(): void
    {
        $drum  = $this->drumWithBatch();
        $route = "api/v1/facilities/batches/{$drum['batchId']}";

        $this->postJson("{$route}/inputs", ['weight_kg' => 10], 201);
        $out   = $this->postJson("{$route}/outputs", [
            'output_weight_kg' => 4,
            'quality_grade'    => 'good',
        ], 201);

        $rows = $this->envelope($this->authed($this->token(), 'get', "{$route}/outputs"))['data'];
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(4.0, $rows[0]['running_total_kg'], 0.001);

        $this->postJson("{$route}/outputs/{$out['data']['id']}/void", ['reason' => 'Tipped back in'], 200);

        $rows = $this->envelope($this->authed($this->token(), 'get', "{$route}/outputs"))['data'];
        $this->assertEqualsWithDelta(0.0, $rows[0]['running_total_kg'], 0.001, 'Voided harvest drops out of totals.');
    }

    public function testVoidRefusesToInvertTheMassLedger(): void
    {
        $drum  = $this->drumWithBatch();
        $route = "api/v1/facilities/batches/{$drum['batchId']}";

        $big = $this->postJson("{$route}/inputs", ['weight_kg' => 8], 201);
        $this->postJson("{$route}/inputs", ['weight_kg' => 2], 201);
        $this->postJson("{$route}/outputs", ['output_weight_kg' => 7], 201);

        // Removing the 8 kg leaves 2 kg of inputs against 7 kg of outputs.
        $res = $this->authed($this->token(), 'post', "{$route}/inputs/{$big['data']['id']}/void", [
            'reason' => 'Was actually 8 not 12',
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('statemachine.bmg.mass_invariant', $res);
    }

    public function testIoEntryIsStaffWideNotStarterOnly(): void
    {
        $drum = $this->drumWithBatch();

        // A DIFFERENT operator (batch started by bmg_admin above) can
        // enter manual I/O — the mechanized-scope rule.
        $operator = $this->login(['facilities_op']);
        $this->postJson(
            "api/v1/facilities/batches/{$drum['batchId']}/inputs",
            ['weight_kg' => 5],
            201,
            $operator['token'],
        );

        $rows = $this->envelope($this->authed($this->token(), 'get', "api/v1/facilities/batches/{$drum['batchId']}/inputs"))['data'];
        $this->assertCount(1, $rows);
        $this->assertSame($operator['userId'], $rows[0]['recorded_by_user_id']);

        // The operator must be able to READ BACK the ledger they just
        // wrote to — a write-only ledger is a trap (the read gate used
        // to be the starter-only `logs_read`, so a non-starter's own
        // entry 403'd the moment they submitted it).
        $operatorRead = $this->authed(
            $operator['token'],
            'get',
            "api/v1/facilities/batches/{$drum['batchId']}/inputs",
        );
        $operatorRead->assertStatus(200);
        $this->assertCount(1, $this->envelope($operatorRead)['data']);

        $operatorReadOut = $this->authed(
            $operator['token'],
            'get',
            "api/v1/facilities/batches/{$drum['batchId']}/outputs",
        );
        $operatorReadOut->assertStatus(200);
    }

    public function testInputAcceptsAWasteCategory(): void
    {
        $drum  = $this->drumWithBatch();
        $route = "api/v1/facilities/batches/{$drum['batchId']}";

        $cat = $this->postJson('api/v1/facilities/waste-categories', [
            'code' => 'io-cat-' . bin2hex(random_bytes(4)),
            'name' => 'I/O Category',
        ], 201);

        $this->postJson("{$route}/inputs", [
            'weight_kg'   => 2.5,
            'category_id' => (int) $cat['data']['id'],
        ], 201);

        $rows = $this->envelope($this->authed($this->token(), 'get', "{$route}/inputs"))['data'];
        $this->assertSame((int) $cat['data']['id'], $rows[0]['category_id']);
        $this->assertSame('I/O Category', $rows[0]['category_name']);

        // Unknown category id is a clean 422, not a FK crash.
        $bad = $this->authed($this->token(), 'post', "{$route}/inputs", [
            'weight_kg'   => 2.5,
            'category_id' => 999999,
        ]);
        $bad->assertStatus(422);
    }
}
