<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Facilities\Services\BmgAlertEngine;
use PHPUnit\Framework\TestCase;

/**
 * BmgAlertEngine — pure-function SPC rules. Edge cases for every
 * rule plus suppression interactives (oxygen + calibration, moisture
 * gating, joined fires).
 *
 * The engine is stateless; the only seam we cover here is `evaluate`
 * and the `daysSinceLastLog` helper. Persistence-level duplicate
 * suppression is exercised in the integration suite.
 */
final class BmgAlertEngineTest extends TestCase
{
    private BmgAlertEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new BmgAlertEngine();
    }

    // -----------------------------------------------------------------
    // No-log short circuit
    // -----------------------------------------------------------------

    public function testNoLogYieldsNoAlerts(): void
    {
        // Young batch (started 2 days ago): no logs yet AND inside the
        // turning cadence — the no-log short circuit yields nothing.
        // (An OLD never-turned batch legitimately fires TURNING_DUE —
        // see the TURNING_DUE cases below.)
        $startedAt = (new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $result = $this->engine->evaluate(
            ['id' => 1, 'status' => BMG_STATE_PROCESSING, 'started_at' => $startedAt, 'archived_at' => null],
            null,
            null,
        );
        $this->assertSame([], $result);
    }

    public function testInactiveBatchProducesNoAlerts(): void
    {
        $result = $this->engine->evaluate(
            ['id' => 1, 'status' => BMG_STATE_IDLE, 'started_at' => '2026-01-01', 'archived_at' => null],
            ['temperature_celsius' => 110.0, 'moisture_level' => 'high', 'oxygen_pct' => 50.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertSame([], $result, 'idle batches must not generate alerts');
    }

    // -----------------------------------------------------------------
    // TEMP_PFRP_LOW / HIGH
    // -----------------------------------------------------------------

    public function testTempBelowPfrpFiresCritical(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 39.9, 'moisture_level' => 'normal', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertCount(1, $result);
        $this->assertSame(BmgAlertEngine::CODE_TEMP_PFRP_LOW, $result[0]['code']);
        $this->assertSame(BmgAlertEngine::SEVERITY_CRITICAL, $result[0]['severity']);
        $this->assertStringContainsString('39.9', $result[0]['message']);
    }

    public function testTempAtPfrpFloorDoesNotFire(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 40.0, 'moisture_level' => 'normal', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertSame([], $result, 'boundary temp 40.0 sits inside the PFRP window');
    }

    public function testTempAbovePfrpCeilingFiresWarning(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 65.1, 'moisture_level' => 'normal', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertCount(1, $result);
        $this->assertSame(BmgAlertEngine::CODE_TEMP_PFRP_HIGH, $result[0]['code']);
        $this->assertSame(BmgAlertEngine::SEVERITY_WARNING, $result[0]['severity']);
    }

    public function testTempAtPfrpCeilingDoesNotFire(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 65.0, 'moisture_level' => 'normal', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertSame([], $result, 'boundary temp 65.0 sits inside the PFRP window');
    }

    public function testNullTempDoesNotFireTempRules(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => null, 'moisture_level' => 'normal', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertSame([], $result);
    }

    // -----------------------------------------------------------------
    // MOISTURE_HIGH (combined with low temperature)
    // -----------------------------------------------------------------

    public function testMoistureHighWithLowTempFires(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 35.0, 'moisture_level' => 'high', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertCount(2, $result, 'low temp + high moisture should fire both TEMP_PFRP_LOW and MOISTURE_HIGH');
        $codes = array_column($result, 'code');
        $this->assertContains(BmgAlertEngine::CODE_TEMP_PFRP_LOW, $codes);
        $this->assertContains(BmgAlertEngine::CODE_MOISTURE_HIGH, $codes);
    }

    public function testMoistureHighWithHotTempDoesNotFire(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 55.0, 'moisture_level' => 'high', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertSame([], $result, 'high moisture is fine above the PFRP floor');
    }

    public function testNormalMoistureDoesNotFire(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 35.0, 'moisture_level' => 'normal', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        // Only the TEMP_PFRP_LOW fires — moisture_level normal skips the rule.
        $this->assertCount(1, $result);
        $this->assertSame(BmgAlertEngine::CODE_TEMP_PFRP_LOW, $result[0]['code']);
    }

    // -----------------------------------------------------------------
    // OXYGEN_OUT
    // -----------------------------------------------------------------

    public function testOxygenBelowOperationalFiresWarning(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => 4.9, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertCount(1, $result);
        $this->assertSame(BmgAlertEngine::CODE_OXYGEN_OUT, $result[0]['code']);
        $this->assertSame(BmgAlertEngine::SEVERITY_WARNING, $result[0]['severity']);
    }

    public function testOxygenBelowCriticalEscalates(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => 1.5, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertCount(1, $result);
        $this->assertSame(BmgAlertEngine::SEVERITY_CRITICAL, $result[0]['severity']);
    }

    public function testOxygenAboveOperationalFiresWarning(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => 20.1, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertCount(1, $result);
        $this->assertSame(BmgAlertEngine::CODE_OXYGEN_OUT, $result[0]['code']);
    }

    public function testOxygenAtBoundaryDoesNotFire(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => 5.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertSame([], $result);
    }

    public function testOxygenReadingSuppressedWhenCalibrationOverdue(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => 1.0, 'log_date' => '2026-01-02', 'calibration_status' => 'overdue'],
            0,
        );
        $this->assertSame([], $result, 'overdue calibration readings are unreliable and must not generate OXYGEN_OUT');
    }

    public function testNullOxygenDoesNotFire(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => null, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            0,
        );
        $this->assertSame([], $result);
    }

    // -----------------------------------------------------------------
    // STALLED
    // -----------------------------------------------------------------

    public function testStalledFiresWhenProcessingAndSilent14Days(): void
    {
        $result = $this->engine->evaluate(
            ['id' => 1, 'status' => BMG_STATE_PROCESSING, 'started_at' => '2026-01-01', 'archived_at' => null],
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            15,
        );
        // The batch started long ago and (daysSinceLastTurning omitted)
        // has never been turned — an old, unturned, log-silent batch
        // legitimately fires STALLED *and* TURNING_DUE together.
        $codes = array_column($result, 'code');
        $this->assertContains(BmgAlertEngine::CODE_STALLED, $codes);
        $this->assertContains(BmgAlertEngine::CODE_TURNING_DUE, $codes);
        $stalled = $result[array_search(BmgAlertEngine::CODE_STALLED, $codes, true)];
        $this->assertSame(BmgAlertEngine::SEVERITY_WARNING, $stalled['severity']);
        $this->assertStringContainsString('15', $stalled['message']);
    }

    public function testStalledDoesNotFireAt14Days(): void
    {
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            14,
        );
        $this->assertSame([], $result, 'STALLED threshold is strictly > 14 days');
    }

    public function testStalledDoesNotFireForAwaitingOutputBatch(): void
    {
        // A batch that has already yielded is off the turning cadence, so
        // STALLED never applies to it — but it is still monitored for SPC
        // breaches. The 30 °C reading below the PFRP window must STILL
        // fire TEMP_PFRP_LOW: that separation is the whole point of having
        // a distinct STALLED rule.
        $result = $this->engine->evaluate(
            ['id' => 1, 'status' => BMG_STATE_AWAITING_OUTPUT, 'started_at' => '2026-01-01', 'archived_at' => null],
            ['temperature_celsius' => 30.0, 'moisture_level' => 'low', 'oxygen_pct' => 10.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            30,
        );
        $codes = array_column($result, 'code');
        $this->assertNotContains(
            BmgAlertEngine::CODE_STALLED,
            $codes,
            'an awaiting-output batch is past the turning cadence'
        );
        $this->assertContains(
            BmgAlertEngine::CODE_TEMP_PFRP_LOW,
            $codes,
            'PFRP temperature rule still applies once output is recorded'
        );
    }

    public function testStalledDoesNotFireWhenLastLogNull(): void
    {
        // Young batch: no logs and inside the turning cadence — nothing
        // fires. (The old-batch variant fires TURNING_DUE; see above.)
        $startedAt = (new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $result = $this->engine->evaluate(
            ['id' => 1, 'status' => BMG_STATE_PROCESSING, 'started_at' => $startedAt, 'archived_at' => null],
            null,
            null,
        );
        $this->assertSame([], $result);
    }

    // -----------------------------------------------------------------
    // Multiple alerts can fire simultaneously
    // -----------------------------------------------------------------

    public function testMultipleRulesFireTogether(): void
    {
        $result = $this->engine->evaluate(
            ['id' => 1, 'status' => BMG_STATE_PROCESSING, 'started_at' => '2025-12-01', 'archived_at' => null],
            ['temperature_celsius' => 35.0, 'moisture_level' => 'high', 'oxygen_pct' => 1.0, 'log_date' => '2026-01-02', 'calibration_status' => 'ok'],
            20,
        );
        $codes = array_column($result, 'code');
        $this->assertContains(BmgAlertEngine::CODE_TEMP_PFRP_LOW, $codes);
        $this->assertContains(BmgAlertEngine::CODE_MOISTURE_HIGH, $codes);
        $this->assertContains(BmgAlertEngine::CODE_OXYGEN_OUT, $codes);
        $this->assertContains(BmgAlertEngine::CODE_STALLED, $codes);
    }

    // -----------------------------------------------------------------
    // daysSinceLastLog helper
    // -----------------------------------------------------------------

    public function testDaysSinceLastLogReturnsNullForNullLog(): void
    {
        $this->assertNull($this->engine->daysSinceLastLog(null));
    }

    public function testDaysSinceLastLogReturnsNullWhenLogDateMissing(): void
    {
        $this->assertNull($this->engine->daysSinceLastLog(['temperature_celsius' => 50.0]));
    }

    public function testDaysSinceLastLogReturnsPositiveForPastDate(): void
    {
        $date = (new \DateTimeImmutable('-30 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $this->assertGreaterThanOrEqual(30, $this->engine->daysSinceLastLog(['log_date' => $date]));
    }

    // -----------------------------------------------------------------
    // TURNING_DUE — aeration cadence slipped (> TURNING_DUE_DAYS), and
    // only while the batch is in processing (mirrors STALLED's gate).
    // -----------------------------------------------------------------

    /** PROCESSING variant of the activeBatch() helper. */
    private function processingBatch(string $startedAt = '2026-01-01'): array
    {
        return [
            'id' => 1,
            'status' => BMG_STATE_PROCESSING,
            'started_at' => $startedAt,
            'archived_at' => null,
        ];
    }

    public function testTurningDueFiresPastTheCadence(): void
    {
        $result = $this->engine->evaluate(
            $this->processingBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => null, 'log_date' => '2026-01-02', 'calibration_status' => null],
            1,
            5,
        );
        $codes = array_column($result, 'code');
        $this->assertContains(BmgAlertEngine::CODE_TURNING_DUE, $codes);
    }

    public function testTurningDueDoesNotFireAtExactlyTheCadence(): void
    {
        $result = $this->engine->evaluate(
            $this->processingBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => null, 'log_date' => '2026-01-02', 'calibration_status' => null],
            1,
            BmgAlertEngine::TURNING_DUE_DAYS,
        );
        $this->assertNotContains(BmgAlertEngine::CODE_TURNING_DUE, array_column($result, 'code'));
    }

    public function testTurningDueDoesNotFireForFreshTurning(): void
    {
        // A device session just landed (0 days since turning) — silent.
        $result = $this->engine->evaluate(
            $this->processingBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => null, 'log_date' => '2026-01-02', 'calibration_status' => null],
            1,
            0,
        );
        $this->assertNotContains(BmgAlertEngine::CODE_TURNING_DUE, array_column($result, 'code'));
    }

    public function testTurningDueIsSuppressedOutsideProcessing(): void
    {
        // awaiting_output drums are not on the turning cadence.
        $result = $this->engine->evaluate(
            $this->activeBatch(),
            ['temperature_celsius' => 50.0, 'moisture_level' => 'normal', 'oxygen_pct' => null, 'log_date' => '2026-01-02', 'calibration_status' => null],
            1,
            20,
        );
        $this->assertNotContains(BmgAlertEngine::CODE_TURNING_DUE, array_column($result, 'code'));
    }

    public function testTurningDueFallsBackToBatchAgeWhenNeverTurned(): void
    {
        // null turning + batch started 5 days ago → the batch age IS the
        // turning age; fires even before any log exists.
        $startedAt = (new \DateTimeImmutable('-5 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $result = $this->engine->evaluate(
            $this->processingBatch($startedAt),
            null,
            null,
            null,
        );
        $this->assertContains(BmgAlertEngine::CODE_TURNING_DUE, array_column($result, 'code'));
    }

    public function testTurningDueSilentForYoungNeverTurnedBatch(): void
    {
        $startedAt = (new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $result = $this->engine->evaluate(
            $this->processingBatch($startedAt),
            null,
            null,
            null,
        );
        $this->assertSame([], $result);
    }

    // -----------------------------------------------------------------
    // daysSinceDate helper
    // -----------------------------------------------------------------

    public function testDaysSinceDateHandlesNullAndEmpty(): void
    {
        $this->assertNull($this->engine->daysSinceDate(null));
        $this->assertNull($this->engine->daysSinceDate(''));
    }

    public function testDaysSinceDateReturnsMagnitudeForPastDate(): void
    {
        $date = (new \DateTimeImmutable('-6 days', new \DateTimeZone('UTC')))->format('Y-m-d');
        $this->assertGreaterThanOrEqual(6, $this->engine->daysSinceDate($date));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function activeBatch(): array
    {
        return [
            'id' => 1,
            'status' => BMG_STATE_AWAITING_OUTPUT,
            'started_at' => '2026-01-01',
            'archived_at' => null,
        ];
    }
}
