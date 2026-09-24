<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\TestResponse;

/**
 * CounsellingAvailabilityUpdateTest — the availability edit path
 * (2026-09-24).
 *
 * Why this exists: the desk could not edit a window. The only way to change
 * one was to remove it and add another, which left the original row behind —
 * so "editing" a schedule produced duplicates. Three properties are load
 * bearing, and none of them is reachable from the unit suite because all
 * three are database behaviour:
 *
 *   1. **In place.** An edit writes to the addressed row and nothing else:
 *      same id afterwards, same row count. Restoring remove-then-add would
 *      fail this.
 *   2. **No duplicates.** An edit that would land on another active window of
 *      the same counsellor (same weekday, same hours) is refused with
 *      `resource.conflict` rather than written. Adding one directly is refused
 *      the same way.
 *   3. **Capacity is gone.** The payload no longer carries `max_slots`, and
 *      the column no longer exists.
 *
 * The suite does not truncate between cases, so every test works on its own
 * freshly created counsellor.
 */
final class CounsellingAvailabilityUpdateTest extends FeatureTestCase
{
    private const ROUTE = 'api/v1/counselling/availability';

    /** @var array{token:string, userId:int, email:string} */
    private array $admin = [];

    private int $counsellorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // guidance_admin holds counselling.schedule.team_manage, so the tests
        // can address a counsellor other than the caller — which is what the
        // desk does when it sets up someone else's week.
        $this->admin        = $this->login(['guidance_admin']);
        $this->counsellorId = $this->createUser(['counsellor'])['id'];
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> the envelope
     */
    private function postJson(string $route, array $body, int $expect = 200): array
    {
        $res = $this->authed($this->admin['token'], 'post', $route, $body);
        $res->assertStatus($expect);

        return $this->envelope($res);
    }

    /** @param array<int, int> $daysOfWeek */
    private function addWindow(array $daysOfWeek, string $start, string $end, int $expect = 201): array
    {
        return $this->postJson(self::ROUTE, [
            'days_of_week'       => $daysOfWeek,
            'start_time'         => $start,
            'end_time'           => $end,
            'counsellor_user_id' => $this->counsellorId,
        ], $expect)['data'];
    }

    /** @return list<array<string, mixed>> active windows for the test counsellor */
    private function windowRows(): array
    {
        return db_connect()->table('counselling_availability')
            ->where('counsellor_user_id', $this->counsellorId)
            ->where('is_active', 1)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
    }

    private function update(int $id, array $body, int $expect = 200): TestResponse
    {
        // Not chained: CI4's TestResponse::assertStatus() returns null (its
        // assertions are not fluent like PHPUnit's).
        $res = $this->authed($this->admin['token'], 'post', self::ROUTE . "/{$id}/update", $body);
        $res->assertStatus($expect);

        return $res;
    }

    // -------------------------------------------------------------- tests

    public function testTheAvailabilityPayloadCarriesNoCapacity(): void
    {
        $created = $this->addWindow([1], '09:00', '10:00');

        $this->assertArrayNotHasKey('max_slots', $created, 'Capacity has left the availability payload.');
    }

    public function testAnEditUpdatesTheSameRowAndAddsNothing(): void
    {
        $created = $this->addWindow([2], '08:00', '09:00');
        $id      = (int) $created['id'];

        $before = $this->windowRows();
        $this->assertCount(1, $before, 'The counsellor starts with exactly the window just added.');

        $updated = $this->postJson(self::ROUTE . "/{$id}/update", [
            'start_time' => '08:30',
            'end_time'   => '09:30',
        ])['data'];

        $this->assertSame($id, (int) $updated['id'], 'An edit keeps the window id.');

        $after = $this->windowRows();
        $this->assertCount(1, $after, 'An edit must not leave a second row behind.');
        $this->assertSame($id, (int) $after[0]['id'], 'The same row is the one that changed.');
        $this->assertSame('08:30:00', (string) $after[0]['start_time']);
        $this->assertSame('09:30:00', (string) $after[0]['end_time']);
        $this->assertSame(2, (int) $after[0]['day_of_week'], 'An omitted field keeps its value.');
    }

    public function testAnEditMovingAWindowToAnotherWeekdayKeepsTheSameRow(): void
    {
        $created = $this->addWindow([5], '13:00', '14:00');
        $id      = (int) $created['id'];

        $this->update($id, ['day_of_week' => 6]);

        $rows = $this->windowRows();
        $this->assertCount(1, $rows, 'Moving a window must not clone it.');
        $this->assertSame($id, (int) $rows[0]['id']);
        $this->assertSame(6, (int) $rows[0]['day_of_week']);
    }

    public function testAnEditThatWouldDuplicateAnotherWindowIsRefused(): void
    {
        $monday    = $this->addWindow([1], '09:00', '10:00');
        $wednesday = $this->addWindow([3], '09:00', '10:00');
        $this->assertNotSame((int) $monday['id'], (int) $wednesday['id']);

        // Wednesday 09:00–10:00 moved onto Monday 09:00–10:00 is the same
        // cover written twice, so it must not be written at all.
        $res = $this->update((int) $wednesday['id'], ['day_of_week' => 1], 409);
        $this->assertErrorCode('resource.conflict', $res);

        $rows = $this->windowRows();
        $this->assertCount(2, $rows, 'A refused edit adds and removes nothing.');
        $this->assertSame(3, (int) $rows[1]['day_of_week'], 'The refused row kept its weekday.');
    }

    public function testAddingTheSameWindowTwiceIsRefused(): void
    {
        $this->addWindow([4], '11:00', '12:00');

        $res = $this->authed($this->admin['token'], 'post', self::ROUTE, [
            'days_of_week'       => [4],
            'start_time'         => '11:00',
            'end_time'           => '12:00',
            'counsellor_user_id' => $this->counsellorId,
        ]);
        $this->assertErrorCode('resource.conflict', $res);

        $this->assertCount(1, $this->windowRows(), 'The duplicate was not inserted.');
    }

    public function testEditingAMissingWindowIs404(): void
    {
        $res = $this->update(999_999_999, ['start_time' => '09:00'], 404);
        $this->assertErrorCode('resource.not_found', $res);
    }

    public function testAnEditThatReversesTheTimesIsRefused(): void
    {
        $created = $this->addWindow([0], '15:00', '16:00');
        $id      = (int) $created['id'];

        $this->update($id, ['start_time' => '17:00', 'end_time' => '16:00'], 422);

        $rows = $this->windowRows();
        $this->assertCount(1, $rows);
        $this->assertSame('15:00:00', (string) $rows[0]['start_time'], 'The stored window is untouched.');
    }
}
