<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Regression cover for the invariants that were documented but not
 * enforced, plus the retirement of the `curing` state.
 *
 * Each of these was a real defect that shipped:
 *  - `recordOutput` advanced the BATCH to `awaiting_output` but never
 *    wrote the UNIT row, so the drum's badge reverted on the next
 *    refetch and the dashboard's awaiting-output counter could never
 *    leave zero.
 *  - `BmgAlertEngine` promised 24-hour repeat suppression for an
 *    unacknowledged alert, but neither call site implemented it — every
 *    out-of-range observation wrote a fresh alert and fired a fresh
 *    notification. The device ingest amplifies this.
 *  - `curing` was retired from the domain on 2026-09-29; the migration
 *    maps any surviving rows back to `awaiting_output`.
 */
final class BmgInvariantsTest extends FeatureTestCase
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

    /**
     * A live connection — `config('Database')` is the bare config object
     * and has no query methods (that mistake failed CI with "Call to
     * undefined method Config\Database::table()").
     */
    private function db(): \CodeIgniter\Database\BaseConnection
    {
        return db_connect();
    }

    /**
     * Category → drum → started batch. Returns the ids.
     *
     * @return array{category:int, unit:int, batch:int}
     */
    private function seedBatch(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $category = $this->postJson('api/v1/facilities/waste-categories', [
            'code'                    => "inv-cat-{$suffix}",
            'name'                    => "Invariant Category {$suffix}",
            'reference_duration_days' => 21,
        ], 201);

        // Every drum integrates exactly one ESP32 (device_id required).
        $device = $this->createBmgDevice();

        $unit = $this->postJson('api/v1/facilities/units', [
            'code'                => "inv-unit-{$suffix}",
            'display_name'        => "Invariant Drum {$suffix}",
            'spec_capacity_kg'    => 500,
            'default_category_id' => (int) $category['data']['id'],
            'device_id'           => $device['deviceId'],
        ], 201);

        $unitId = (int) $unit['data']['id'];
        $batch = $this->postJson("api/v1/facilities/units/{$unitId}/start", [
            'total_input_weight_kg' => 100,
            'composition'           => [
                ['category_id' => (int) $category['data']['id'], 'weight_kg' => 100],
            ],
        ], 201);

        return [
            'category' => (int) $category['data']['id'],
            'unit'     => $unitId,
            'batch'    => (int) $batch['data']['id'],
        ];
    }

    private function unitRow(int $unitId): array
    {
        $row = $this->db()->table('facilities_bmg_units')
            ->where('id', $unitId)
            ->get()
            ->getRowArray();
        $this->assertIsArray($row);
        return $row;
    }

    // -----------------------------------------------------------------
    // 1. The unit row tracks the batch's phase
    // -----------------------------------------------------------------

    public function testRecordOutputAdvancesTheUnitRowToo(): void
    {
        ['unit' => $unitId, 'batch' => $batchId] = $this->seedBatch();
        $this->assertSame('processing', $this->unitRow($unitId)['status']);

        $this->postJson("api/v1/facilities/batches/{$batchId}/output", [
            'output_weight_kg' => 40,
            'output_items'     => [],
        ]);

        $row = $this->unitRow($unitId);
        $this->assertSame(
            'awaiting_output',
            $row['status'],
            'the drum must follow its batch into awaiting_output, or the badge reverts and the dashboard counter stays at zero'
        );
    }

    public function testAddOutputUpdateAdvancesTheUnitRowToo(): void
    {
        // The UI reaches awaiting_output through the ledger "Add update"
        // path, not /output, so both writers need the correction.
        ['unit' => $unitId, 'batch' => $batchId] = $this->seedBatch();

        $this->postJson("api/v1/facilities/batches/{$batchId}/update", [
            'update_type'      => 'output',
            'output_weight_kg' => 40,
        ], 201);

        $this->assertSame('awaiting_output', $this->unitRow($unitId)['status']);
    }

    public function testFinishReturnsTheUnitToIdle(): void
    {
        ['unit' => $unitId, 'batch' => $batchId] = $this->seedBatch();

        $this->postJson("api/v1/facilities/batches/{$batchId}/finish", [
            'quality_grade'  => 'good',
            'maturity_level' => 'mature',
        ]);

        $this->assertSame('idle', $this->unitRow($unitId)['status']);
    }

    // -----------------------------------------------------------------
    // 2. Alert repeat suppression
    // -----------------------------------------------------------------

    public function testRepeatedProcessLogsDoNotRaiseTheSameAlertTwice(): void
    {
        ['batch' => $batchId] = $this->seedBatch();

        // Two consecutive sub-40 °C readings inside the 24h window. Each
        // individually breaches the PFRP rule (critical).
        foreach ([30.0, 31.0] as $temp) {
            $this->postJson("api/v1/facilities/batches/{$batchId}/logs", [
                'event_type'          => 'observation',
                'temperature_celsius' => $temp,
                'moisture_level'      => 'normal',
            ], 201);
        }

        $alerts = $this->getJson("api/v1/facilities/batches/{$batchId}/alerts");
        $count = 0;
        foreach (($alerts['data'] ?? []) as $a) {
            if (($a['code'] ?? '') === 'TEMP_PFRP_LOW') {
                $count++;
            }
        }

        $this->assertSame(
            1,
            $count,
            'an unacknowledged alert must suppress its own code for the dedupe window; only acknowledging re-arms it'
        );
    }

    public function testAcknowledgingAnAlertReArmsTheRule(): void
    {
        ['batch' => $batchId] = $this->seedBatch();

        $first = $this->postJson("api/v1/facilities/batches/{$batchId}/logs", [
            'event_type'          => 'observation',
            'temperature_celsius' => 30.0,
            'moisture_level'      => 'normal',
        ], 201);

        $alertId = (int) (($first['data']['alerts'][0]['id'] ?? 0));
        $this->assertGreaterThan(0, $alertId, 'the first breach must still raise an alert');

        // Acknowledge it — the operator has dealt with it, so the next
        // breach is new information and must be surfaced again.
        $this->postJson("api/v1/facilities/alerts/{$alertId}/acknowledge", []);

        $this->postJson("api/v1/facilities/batches/{$batchId}/logs", [
            'event_type'          => 'observation',
            'temperature_celsius' => 31.0,
            'moisture_level'      => 'normal',
        ], 201);

        $alerts = $this->getJson("api/v1/facilities/batches/{$batchId}/alerts");
        $codes = array_map(static fn (array $a): string => (string) ($a['code'] ?? ''), $alerts['data'] ?? []);
        $this->assertSame(
            2,
            count(array_keys($codes, 'TEMP_PFRP_LOW', true)),
            'acknowledging an alert must re-arm the rule immediately'
        );
    }

    // -----------------------------------------------------------------
    // 3. The retired `curing` state
    // -----------------------------------------------------------------

    public function testCuringIsNoLongerPartOfTheDomain(): void
    {
        $db = $this->db();

        $this->assertSame(
            0,
            (int) $db->table('facilities_bmg_batches')->where('status', 'curing')->countAllResults(),
            'no batch may remain in the retired state after the migration backfilled it'
        );
        $this->assertSame(
            0,
            (int) $db->table('facilities_bmg_units')->where('status', 'curing')->countAllResults(),
        );
    }

    public function testCuringUpdateTypeIsRejectedByTheApi(): void
    {
        ['batch' => $batchId] = $this->seedBatch();

        $this->postJson("api/v1/facilities/batches/{$batchId}/update", [
            'update_type' => 'curing',
            'curing_note' => 'should be refused',
        ], 422);
    }

    public function testTheCuringRouteIsGone(): void
    {
        ['batch' => $batchId] = $this->seedBatch();

        // A route the router has never heard of surfaces as
        // PageNotFoundException inside the call, not as a 404 response.
        $this->expectException(PageNotFoundException::class);
        $this->authed($this->token(), 'post', "api/v1/facilities/batches/{$batchId}/curing", []);
    }

    public function testHistoricalCuringLedgerRowsStayReadable(): void
    {
        // The append-only ledger keeps the `curing` member on its ENUM so
        // pre-retirement rows remain renderable. This inserts one directly
        // to prove the feed still returns it.
        $db = $this->db();
        ['batch' => $batchId] = $this->seedBatch();

        // Take the tenant from the batch the API just created, so the
        // row lands in the same tenant the read path filters on.
        $tenantId = $db->table('facilities_bmg_batches')
            ->select('tenant_id')
            ->where('id', $batchId)
            ->get()
            ->getRowArray()['tenant_id'] ?? null;
        $this->assertNotNull($tenantId);

        $db->table('facilities_bmg_batch_updates')->insert([
            'tenant_id'           => $tenantId,
            'batch_id'            => $batchId,
            'update_type'         => 'curing',
            'curing_note'         => 'legacy entry from before the retirement',
            'recorded_by_user_id' => $this->session['userId'],
            'created_at'          => '2026-01-01 00:00:00',
        ]);

        $updates = $this->getJson("api/v1/facilities/batches/{$batchId}/updates");
        $types = array_map(static fn (array $u): string => (string) ($u['update_type'] ?? ''), $updates['data'] ?? []);

        $this->assertContains(
            'curing',
            $types,
            'historical curing ledger rows must stay readable — the ledger is an immutable audit record'
        );
    }
}
