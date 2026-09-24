<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Counselling\Services\QueueService;
use Modules\Counselling\Services\ScheduleService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * GuidanceDayBoardContractTest — the Guidance day board's backend contract.
 *
 * Why: 2026-09-23 replaced the Counselling **Queue** tab's FIFO console with a
 * three-bucket calendar board (upcoming / today / archived) fed by the
 * appointments endpoint, and promoted **Appointments** out of Scheduling into
 * its own section. Four things in that arrangement are load-bearing and easy
 * to break silently, so they are pinned here rather than left to review:
 *
 *   1. The buckets are resolved against the **Manila** business day. This
 *      module has already shipped a bug where day bucketing used a raw UTC
 *      date and the 16:00–24:00 UTC window landed on the wrong day.
 *   2. The buckets stay **disjoint** — a row must not appear in two of them.
 *   3. Appointment pagination keys on `(appointment_date, start_time, id)`.
 *      The shared KeysetPaginator keys on `(created_at, id)`, which is the
 *      wrong axis for a schedule: page 2 would repeat or skip rows.
 *   4. `Start Session` is reachable from `waiting`. The board has no **Call
 *      next** button, and `callNext()` was the only writer of `called` — so
 *      without this a kiosk walk-in has no reachable transition at all.
 *
 * Mirrors `AppointmentTransitionsTest`: reflection for the state machine,
 * source assertions for the query shape, no DB and no framework boot. Source
 * assertions deliberately avoid multi-line and column-aligned needles — the
 * backend is CRLF and the test fixtures are LF, so only single-line,
 * alignment-free fragments are safe.
 */
final class GuidanceDayBoardContractTest extends TestCase
{
    public function testTheThreeBucketsAreTheDeclaredScopes(): void
    {
        $this->assertSame(
            ['upcoming', 'today', 'archived'],
            ScheduleService::APPOINTMENT_SCOPES,
            'The Queue board renders exactly these three buckets.',
        );
    }

    public function testBucketsResolveAgainstTheManilaBusinessDay(): void
    {
        $service = $this->read('app/Modules/Counselling/Services/ScheduleService.php');

        $this->assertStringContainsString(
            'ManilaDay::today()',
            $service,
            'Bucket boundaries must come from the Manila-day helper, never a raw UTC date.',
        );
        // `upcoming` — dated after today AND still live.
        $this->assertStringContainsString(
            "\$builder->where('a.appointment_date >', \$today)",
            $service,
            '`upcoming` must be bounded by the Manila day, strictly after it.',
        );
        // `today` — dated today AND still live.
        $this->assertStringContainsString(
            "\$builder->where('a.appointment_date', \$today)",
            $service,
            '`today` must be bounded by the Manila day exactly.',
        );
        // Both live buckets exclude resolved appointments, which is what keeps
        // them disjoint from `archived`.
        $this->assertStringContainsString(
            "whereIn('a.status', ['scheduled', 'confirmed'])",
            $service,
            'Live buckets must hold only scheduled/confirmed appointments.',
        );
    }

    public function testTheArchivedBucketIsResolvedOrPastDated(): void
    {
        $service = $this->read('app/Modules/Counselling/Services/ScheduleService.php');

        $this->assertStringContainsString(
            "whereIn('a.status', ['completed', 'cancelled', 'no_show'])",
            $service,
            '`archived` must include every resolved status.',
        );
        // The OR has to sit inside a group. Ungrouped, it would escape the
        // tenant/counsellor predicates and match the whole table.
        $this->assertStringContainsString(
            "->orWhere('a.appointment_date <', \$today)",
            $service,
            '`archived` must also sweep up stale past-dated rows.',
        );
        $this->assertStringContainsString(
            '->groupStart()',
            $service,
            'The archived OR must be grouped, or it escapes the tenant scope.',
        );
    }

    public function testAppointmentPaginationKeysOnTheCalendarNotCreatedAt(): void
    {
        $service = $this->read('app/Modules/Counselling/Services/ScheduleService.php');

        $this->assertStringContainsString(
            'applyAppointmentKeyset($builder, $cursor, $limit, $descending)',
            $service,
            'Appointments paginate on their own calendar axis.',
        );
        $this->assertStringNotContainsString(
            'KeysetPaginator::apply(',
            $service,
            'KeysetPaginator::apply keys on (created_at, id) — the wrong axis for a schedule. It must not come back.',
        );
        // The cursor still uses the shared opaque encoding, so the wire format
        // stays stable for clients.
        $this->assertStringContainsString('KeysetPaginator::encode(', $service);
        $this->assertStringContainsString('KeysetPaginator::decode(', $service);
    }

    public function testEveryRowDeclaresWhichSideBookedIt(): void
    {
        $service = $this->read('app/Modules/Counselling/Services/ScheduleService.php');

        // The board's whole point is that patient self-bookings and desk
        // bookings sit side by side, so the origin has to be on the row.
        $this->assertStringContainsString("'patient'", $service);
        $this->assertStringContainsString("'counsellor'", $service);
        $this->assertStringContainsString("'staff'", $service);
        $this->assertStringContainsString("'source'", $service);
        // Names come from the `users` join, so the board can render a patient
        // without a second lookup.
        $this->assertStringContainsString('patient_display_name', $service);
        $this->assertStringContainsString("join('users p'", $service);
        $this->assertStringContainsString("join('users c'", $service);
    }

    public function testTheControllerAcceptsScopeAndTypeFilters(): void
    {
        $controller = $this->read('app/Modules/Counselling/Controllers/ScheduleController.php');

        // Without the `scope` whitelist entry the board's buckets 422 and the
        // Queue tab renders an error on every load.
        $this->assertStringContainsString(
            'ScheduleService::APPOINTMENT_SCOPES',
            $controller,
            'ScheduleController must validate `scope` against the service constant.',
        );
        $this->assertStringContainsString("in_list[initial,follow_up,crisis,referral_based]", $controller);
        // Both filters have to reach the service, not just be validated.
        $this->assertStringContainsString("\$scope !== '' ? \$scope : null,", $controller);
        $this->assertStringContainsString("\$type !== '' ? \$type : null,", $controller);
    }

    public function testStartSessionIsReachableFromWaiting(): void
    {
        $transitions = $this->readQueueTransitions();

        // The board offers `Start Session` on a `waiting` entry. `callNext()`
        // was the only writer of `called`, and the board has no Call next
        // button, so without `waiting` here a kiosk walk-in is stranded.
        $this->assertContains('waiting', $transitions['start'] ?? [], '`start` must be reachable from `waiting`.');
        $this->assertContains('called', $transitions['start'] ?? [], 'The pre-existing `called` path must keep working.');
    }

    public function testQueueCompletionStillOnlyClosesAnActiveSession(): void
    {
        // Guards the other side of the widening: `complete` was NOT broadened.
        // Allowing it from `waiting`/`called` would close a queue entry whose
        // session was never opened.
        $transitions = $this->readQueueTransitions();
        $this->assertSame(['in_session'], $transitions['complete'] ?? null);
    }

    public function testCallNextSurvivesForThePublicBoard(): void
    {
        // The board dropped the *button*, not the capability: the public
        // lobby projection and QueueFifoContractTest both depend on it.
        $queue = $this->read('app/Modules/Counselling/Services/QueueService.php');
        $this->assertStringContainsString('public function callNext(): array', $queue);
        $this->assertStringContainsString("post('queue/call-next'", $this->read('app/Modules/Counselling/Routes.php'));
    }

    public function testQueueCompletionPromotesBothLiveAppointmentStatuses(): void
    {
        // Pinning only `confirmed` left an appointment that was never
        // explicitly confirmed stuck at `scheduled` for ever, so it kept
        // resurfacing on the day board as a live row for a patient who had
        // already been seen.
        $queue = $this->read('app/Modules/Counselling/Services/QueueService.php');
        $this->assertStringContainsString("whereIn('status', ['scheduled', 'confirmed'])", $queue);
    }

    /**
     * @return array<string, list<string>>
     */
    private function readQueueTransitions(): array
    {
        $ref = new ReflectionClass(QueueService::class);
        $const = $ref->getReflectionConstant('TRANSITIONS');
        $this->assertNotNull($const, 'QueueService::TRANSITIONS const missing.');
        $value = $const->getValue();
        $this->assertIsArray($value);
        return $value;
    }

    private function read(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $this->assertIsString($source, "Could not read {$path}.");
        return $source;
    }
}
