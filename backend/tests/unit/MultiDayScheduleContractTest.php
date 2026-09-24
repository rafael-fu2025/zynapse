<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MultiDayScheduleContractTest — the weekday-set contract for both schedule
 * surfaces (2026-09-23).
 *
 * Why: the desk asked for a checkbox per weekday when adding schedules, so one
 * submit can create a whole working week. That means two separate services now
 * accept a **set** of weekdays where they previously took a single `day_of_week`
 * — `Modules\Counselling\Services\ScheduleService` (counsellor availability) and
 * `Modules\Clinic\Services\StaffScheduleService` (staff shifts). They share no
 * code, so nothing but this test stops them from drifting apart.
 *
 * Four properties are load-bearing:
 *
 *   1. **All-or-nothing.** One transaction inserts every weekday, so a partial
 *      week is not a reachable state. An operator who ticked Mon/Wed/Fri and
 *      got only Mon would have no way to tell which days landed.
 *   2. **The old shape still works.** `day_of_week` is accepted and wrapped, so
 *      the Clinic edit dialog and any older caller keep functioning.
 *   3. **The set is normalised** — de-duplicated, range-checked and sorted, so
 *      "Fri, Mon, Mon" and "Mon, Fri" are the same request.
 *   4. **Per-day conflict checks survive.** The staff-shift overlap rule is a
 *      per-weekday rule; checking it once outside the loop would let a set that
 *      collides on Thursday through.
 *
 * Source assertions use single-line, alignment-free needles only: the backend
 * sources are CRLF and these fixtures are LF, so anything spanning a line break
 * or depending on column padding is unsafe. Mirrors
 * `GuidanceDayBoardContractTest`.
 */
final class MultiDayScheduleContractTest extends TestCase
{
    /** Both surfaces must offer the same capability, or the desk gets two different controls. */
    public function testBothScheduleSurfacesAcceptASetOfWeekdays(): void
    {
        foreach ([
            'counsellor availability' => 'app/Modules/Counselling/Services/ScheduleService.php',
            'clinic staff shifts'     => 'app/Modules/Clinic/Services/StaffScheduleService.php',
        ] as $surface => $path) {
            $source = $this->read($path);

            $this->assertStringContainsString(
                'private function normaliseDaysOfWeek(array $input): array',
                $source,
                "{$surface} must normalise the weekday set.",
            );
            $this->assertStringContainsString(
                '$days = $this->normaliseDaysOfWeek($input);',
                $source,
                "{$surface} must resolve the weekdays before it opens its transaction.",
            );
            $this->assertStringContainsString(
                "\$input['days_of_week']",
                $source,
                "{$surface} must read the `days_of_week` payload key.",
            );
        }
    }

    public function testTheWholeWeekIsInsertedInOneTransaction(): void
    {
        $availability = $this->read('app/Modules/Counselling/Services/ScheduleService.php');

        // The loop lives inside the txn closure, so a failure on the third
        // weekday rolls back the first two.
        $this->assertStringContainsString(
            'return $this->txn(function () use ($input, $userId, $counsellorId, $days): array {',
            $availability,
            'Availability inserts must be wrapped in one transaction.',
        );
        $this->assertStringContainsString('foreach ($days as $day) {', $availability);
        $this->assertStringContainsString("'ids' => \$ids", $availability, 'The caller needs every created id, not just the first.');

        $shifts = $this->read('app/Modules/Clinic/Services/StaffScheduleService.php');
        $this->assertStringContainsString(
            'return $this->txn(function () use ($input, $actor, $days): array {',
            $shifts,
            'Staff-shift inserts must be wrapped in one transaction.',
        );
        $this->assertStringContainsString('foreach ($days as $dow) {', $shifts);
        $this->assertStringContainsString("'created' => count(\$ids)", $shifts);
    }

    public function testEveryInsertedWeekdayIsAudited(): void
    {
        // The outbox is per-row everywhere else in the module; a multi-day add
        // must not collapse N rows into one audit event.
        $availability = $this->read('app/Modules/Counselling/Services/ScheduleService.php');
        $this->assertStringContainsString("'counselling.availability_added'", $availability);

        $shifts = $this->read('app/Modules/Clinic/Services/StaffScheduleService.php');
        $this->assertStringContainsString("'clinic.staff_schedule_created'", $shifts);
    }

    public function testTheStaffShiftOverlapRuleStaysPerDay(): void
    {
        $shifts = $this->read('app/Modules/Clinic/Services/StaffScheduleService.php');

        // Inside the loop: the set may span a weekday that already holds a
        // shift, and "no two active shifts overlap" is a per-weekday rule.
        $this->assertStringContainsString(
            '$this->assertNoOverlap($userId, $dow, $start, $end);',
            $shifts,
            'Overlap must be checked per weekday, inside the loop.',
        );
    }

    public function testTheLegacySingleDayShapeStillResolves(): void
    {
        foreach ([
            'app/Modules/Counselling/Services/ScheduleService.php',
            'app/Modules/Clinic/Services/StaffScheduleService.php',
        ] as $path) {
            $this->assertStringContainsString(
                "array_key_exists('day_of_week', \$input)",
                $this->read($path),
                "{$path} must keep accepting the single-day `day_of_week` shape.",
            );
        }
    }

    public function testAnEmptyOrMalformedSetIsRejectedNotSilentlyIgnored(): void
    {
        foreach ([
            'app/Modules/Counselling/Services/ScheduleService.php',
            'app/Modules/Clinic/Services/StaffScheduleService.php',
        ] as $path) {
            $source = $this->read($path);

            // Submitting nothing must fail loudly — silently creating zero
            // windows would look like success in the UI.
            $this->assertStringContainsString("'Pick at least one weekday.'", $source);
            $this->assertStringContainsString("'Weekdays must be numbers 0-6.'", $source);
            $this->assertStringContainsString('$day < 0 || $day > 6', $source, 'The range check is the guard against a bad weekday.');
        }
    }

    public function testBothControllersShapeCheckTheWeekdayArray(): void
    {
        // CI4's rule engine validates scalars, not arrays of ints, so
        // `days_of_week` cannot be expressed as a validation rule — the shape
        // check has to be explicit on the controller.
        foreach ([
            'app/Modules/Counselling/Controllers/ScheduleController.php',
            'app/Modules/Clinic/Controllers/StaffScheduleController.php',
        ] as $path) {
            $controller = $this->read($path);

            $this->assertStringContainsString(
                'private function assertWeekdaysPresent(array $payload): void',
                $controller,
                "{$path} must shape-check the weekday set.",
            );
            $this->assertStringContainsString('$this->assertWeekdaysPresent($payload);', $controller);
            // The array itself has to be refused, not just iterated.
            $this->assertStringContainsString('! is_array($raw)', $controller);
            $this->assertStringContainsString("'Weekdays must be a list of numbers 0-6.'", $controller);
        }
    }

    public function testDayOfWeekIsNoLongerRequiredByTheRuleEngine(): void
    {
        // Leaving `day_of_week` as `required` would 422 every multi-day submit
        // before `assertWeekdaysPresent` ever ran.
        foreach ([
            'app/Modules/Counselling/Controllers/ScheduleController.php',
            'app/Modules/Clinic/Controllers/StaffScheduleController.php',
        ] as $path) {
            $controller = $this->read($path);

            $this->assertStringContainsString(
                "'day_of_week'",
                $controller,
                "{$path} still validates the legacy single-day field.",
            );
            $this->assertStringContainsString(
                'permit_empty',
                $controller,
                "{$path} must not require `day_of_week` any more.",
            );
        }
    }

    private function read(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $this->assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
