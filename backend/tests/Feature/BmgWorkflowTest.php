<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * BMG batch lifecycle, end-to-end through the real routes: create waste
 * category → create unit → start batch (with structured composition) →
 * structured I/O → losses → curing → finish, plus analytics reads.
 *
 * This is the only net the BmgService refactor has, and it is also the
 * ONLY place the DB-level mass invariants are observable: they are
 * enforced by MySQL triggers (`trg_bmg_batches_mass_invariant_*`) using
 * `SIGNAL SQLSTATE '45000'` — unreachable without a real server.
 */
final class BmgWorkflowTest extends FeatureTestCase
{
    /** @var array{id:int, email:string} */
    private array $admin = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(['admin']);
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

    private string $tokenValue = '';

    private function token(): string
    {
        if ($this->tokenValue === '') {
            $res = $this->withBodyFormat('json')->call('post', 'api/v1/auth/login', [
                'email'    => $this->admin['email'],
                'password' => self::TEST_PASSWORD,
            ]);
            $res->assertStatus(200);
            $token = $this->envelope($res)['data']['access_token'] ?? null;
            $this->assertIsString($token);
            $this->tokenValue = $token;
        }
        return $this->tokenValue;
    }

    /**
     * Runs the whole happy path once and returns the artefacts so
     * multiple tests can assert on different slices without repeating
     * the flow (each test gets a FRESH unit/category via unique codes).
     *
     * @return array<string, mixed>
     */
    private function runLifecycle(): array
    {
        $suffix = bin2hex(random_bytes(4));

        // 1. Waste category — with yield/duration so analytics have data.
        $category = $this->postJson('api/v1/facilities/waste-categories', [
            'code'                    => "cat-{$suffix}",
            'name'                    => "Feature Category {$suffix}",
            'expected_yield_pct'      => 40,
            'reference_duration_days' => 21,
        ], 201);
        $categoryId = (int) ($category['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $categoryId);

        // 2. Unit (drum).
        $unit = $this->postJson('api/v1/facilities/units', [
            'code'                => "unit-{$suffix}",
            'display_name'        => "Feature Drum {$suffix}",
            'spec_capacity_kg'    => 500,
            'default_category_id' => $categoryId,
        ], 201);
        $unitId = (int) ($unit['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $unitId);

        // 3. Start batch — structured composition must sum to the total
        //    input (mass invariant, ±0.01 kg tolerance).
        $batch = $this->postJson("api/v1/facilities/units/{$unitId}/start", [
            'total_input_weight_kg' => 100,
            'composition'           => [
                ['category_id' => $categoryId, 'weight_kg' => 100],
            ],
        ], 201);
        $batchId = (int) ($batch['data']['id'] ?? 0);
        $this->assertGreaterThan(0, $batchId);
        $this->assertSame('processing', $batch['data']['status'] ?? null);

        return compact('suffix', 'categoryId', 'unitId', 'batchId') + ['batch' => $batch['data']];
    }

    public function testFullLifecycleFromCategoryToFinishedBatch(): void
    {
        $ctx = $this->runLifecycle();
        $batchId = $ctx['batchId'];
        $unitId  = $ctx['unitId'];

        // 4. Structured input while processing.
        $this->postJson("api/v1/facilities/batches/{$batchId}/inputs", [
            'weight_kg' => 5,
            'note'      => 'feature-test top-up',
        ], 201);

        // 5. Mass invariant: output above total input must be rejected —
        //    enforced by the DB trigger, surfacing as the state-machine
        //    mass-invariant error code.
        $this->postJson("api/v1/facilities/batches/{$batchId}/output", [
            'output_weight_kg' => 999,
        ], 422);

        // 6. Legitimate output (batch: 100 in + 5 topped up). Recording
        //    output is what transitions the batch to awaiting_output.
        $output = $this->postJson("api/v1/facilities/batches/{$batchId}/output", [
            'output_weight_kg' => 20,
        ]);
        $this->assertSame('awaiting_output', $output['data']['status'] ?? null);

        // 7. A loss entry recomputes the batch's denormalised total.
        $this->postJson("api/v1/facilities/batches/{$batchId}/losses", [
            'category_code' => 'evaporation',
            'weight_kg'     => 2.5,
        ], 201);

        // 8. Process log (drives the alert engine).
        $this->postJson("api/v1/facilities/batches/{$batchId}/logs", [
            'event_type'          => 'turning',
            'temperature_celsius' => 55,
            'moisture_level'      => 'normal',
        ], 201);

        // 9. → curing.
        $curing = $this->postJson("api/v1/facilities/batches/{$batchId}/curing", [
            'accumulated_in_process_kg' => 3,
        ]);
        $this->assertSame('curing', $curing['data']['status'] ?? null);

        // 10. Finish (graded release from curing).
        $finished = $this->postJson("api/v1/facilities/batches/{$batchId}/finish", [
            'quality_grade'  => 'good',
            'maturity_level' => 'mature',
            'notes'          => 'feature-test finish',
        ]);
        $this->assertSame('released', $finished['data']['status'] ?? null);

        // 11. Unit returns to idle.
        $units = $this->getJson('api/v1/facilities/units');
        $rows  = $units['data'] ?? [];
        $this->assertIsArray($rows);
        $mine = array_values(array_filter($rows, static fn ($r) => (int) ($r['id'] ?? 0) === $unitId));
        $this->assertNotEmpty($mine, 'Created unit should appear in the list.');
        $this->assertSame('idle', $mine[0]['status'] ?? null);
    }

    public function testBatchAnalyticsAndComplianceReads(): void
    {
        $ctx     = $this->runLifecycle();
        $batchId = $ctx['batchId'];

        $analytics = $this->getJson("api/v1/facilities/batches/{$batchId}/analytics");
        $this->assertIsArray($analytics['data']);

        $compliance = $this->getJson("api/v1/facilities/batches/{$batchId}/compliance");
        $this->assertIsArray($compliance['data']);

        $active = $this->getJson('api/v1/facilities/batches/active');
        $this->assertIsArray($active['data']);
    }

    public function testCompositionSumMismatchIsRejected(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $category = $this->postJson('api/v1/facilities/waste-categories', [
            'code' => "cat-{$suffix}",
            'name' => "Mismatch Category {$suffix}",
        ], 201);
        $categoryId = (int) ($category['data']['id'] ?? 0);

        $unit = $this->postJson('api/v1/facilities/units', [
            'code'         => "unit-{$suffix}",
            'display_name' => "Mismatch Drum {$suffix}",
        ], 201);
        $unitId = (int) ($unit['data']['id'] ?? 0);

        // Composition sum (60) ≠ declared total (100) — PHP-side
        // normalizeComposition enforces ±0.01 kg.
        $res = $this->authed($this->token(), 'post', "api/v1/facilities/units/{$unitId}/start", [
            'total_input_weight_kg' => 100,
            'composition'           => [
                ['category_id' => $categoryId, 'weight_kg' => 60],
            ],
        ]);

        $res->assertStatus(422);
        $body = $this->envelope($res);
        $this->assertFalse($body['success']);
    }

    public function testUnitBusyStateMachineRejectsSecondBatch(): void
    {
        $ctx     = $this->runLifecycle();
        $unitId  = $ctx['unitId'];

        // The unit is now processing — a second start must be refused
        // with the BMG unit-busy state-machine code. The composition is
        // valid so the request reaches the state machine (validation
        // would otherwise 422 first).
        $res = $this->authed($this->token(), 'post', "api/v1/facilities/units/{$unitId}/start", [
            'total_input_weight_kg' => 50,
            'composition'           => [
                ['category_id' => $ctx['categoryId'], 'weight_kg' => 50],
            ],
        ]);

        $res->assertStatus(409);
        // The service throws the generic state-machine transition code
        // for a busy unit (the dedicated bmg.unit_busy code exists but
        // startBatch's Idle→Processing guard uses the generic one) —
        // pinning the ACTUAL contract here.
        $this->assertErrorCode('statemachine.invalid_transition', $res);
    }
}
