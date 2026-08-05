<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Clinic\Controllers\AppointmentController;
use Modules\Clinic\Services\AppointmentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * AppointmentTransitionsTest — `no_show` reachability contract.
 *
 * Why: the panel revision (August 2026) widens the appointment state
 * machine so a patient who walked in, was checked in, and then left
 * before being seen can still be marked `no_show` from the encounter
 * side (`ClinicService::markNoShow()`). The state-machine map and the
 * controller's validation whitelist must both allow this transition
 * from BOTH `scheduled` and `checked_in` — otherwise the cascade
 * silently throws a `StateMachineException` on the appointment half
 * and the encounter closes without the linked appointment reflecting
 * the no-show.
 *
 * This test is reflection-based so it stays pure (no DB / no
 * framework boot), mirroring `BmgLossCategoriesContractTest`.
 */
final class AppointmentTransitionsTest extends TestCase
{
    public function testNoShowIsReachableFromScheduled(): void
    {
        $transitions = $this->readTransitions();
        $this->assertContains(
            'scheduled',
            $transitions['no_show'] ?? [],
            '`no_show` must be reachable from `scheduled` (patient never arrived).',
        );
    }

    public function testNoShowIsReachableFromCheckedIn(): void
    {
        $transitions = $this->readTransitions();
        $this->assertContains(
            'checked_in',
            $transitions['no_show'] ?? [],
            '`no_show` must be reachable from `checked_in` (walked out before being seen).',
        );
    }

    public function testCheckedInIsOnlyReachableFromScheduled(): void
    {
        // Guards the other side of the widening: we did NOT broaden
        // `checked_in` itself — that would let a completed appointment
        // silently re-open. A drift here would let the auto-check-in
        // pass transition a `completed` row.
        $transitions = $this->readTransitions();
        $this->assertSame(['scheduled'], $transitions['checked_in'] ?? null);
    }

    public function testControllerWhitelistAcceptsNoShow(): void
    {
        // The CI4 validator reaches the service via the controller's
        // `in_list[...]` rule. A typo here (e.g. `no-show`, `noShow`)
        // would silently bounce at the controller and the cascade
        // would fail with a 422.
        $controller = file_get_contents(
            __DIR__ . '/../../app/Modules/Clinic/Controllers/AppointmentController.php',
        );
        $this->assertIsString($controller);
        $this->assertStringContainsString(
            "in_list[checked_in,completed,cancelled,no_show]",
            $controller,
            'AppointmentController status whitelist must include `no_show` for the cascade to reach the service.',
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function readTransitions(): array
    {
        $ref = new ReflectionClass(AppointmentService::class);
        $const = $ref->getReflectionConstant('TRANSITIONS');
        $this->assertNotNull($const, 'AppointmentService::TRANSITIONS const missing.');
        $value = $const->getValue();
        $this->assertIsArray($value);
        return $value;
    }
}