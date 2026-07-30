<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit-level guard for the `Maintenance` state of the BMG state
 * machine. We don't boot the full DB here — the rule under test is
 * purely "the constant exists and matches the wire format". The
 * schema-level test lives in the integration suite; this is a
 * cheap regression net so the constant cannot drift silently.
 */
final class BmgMaintenanceStateTest extends TestCase
{
    public function testMaintenanceConstantIsDefined(): void
    {
        $this->assertTrue(defined('BMG_STATE_MAINTENANCE'));
        // Panel revision (July 2026): status wire format is lowercase.
        $this->assertSame('maintenance', BMG_STATE_MAINTENANCE);
    }

    public function testMaintenanceIsDistinctFromOtherStates(): void
    {
        $all = [
            BMG_STATE_IDLE,
            BMG_STATE_PROCESSING,
            BMG_STATE_AWAITING_OUTPUT,
            BMG_STATE_CANCELLED,
            BMG_STATE_MAINTENANCE,
        ];
        $this->assertCount(5, $all, 'BMG has five lifecycle states');
        $this->assertSame($all, array_values(array_unique($all)), 'BMG states must be unique');
    }
}
