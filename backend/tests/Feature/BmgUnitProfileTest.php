<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Drum composition profile — the panel revision that moved the waste
 * mix from batch start to drum setup:
 *
 *  - A drum is created with MULTIPLE designated waste categories
 *    (`category_ids`, pivot-backed) and with its TWO drums' individual
 *    capacities. The unit's `spec_capacity_kg` is RECOMPUTED as the sum
 *    of the two drums — a client-supplied total never wins.
 *  - Each drum holds at least 4 kg, and the two capacities must arrive
 *    together (a lone value is a client bug).
 *  - `startBatch` on a designated drum only accepts compositions drawn
 *    from its configured set; unprofiled drums keep the free-form mix.
 *  - The first designated category seeds `default_category_id`, and a
 *    category still designated by any drum cannot be hard-deleted.
 */
final class BmgUnitProfileTest extends FeatureTestCase
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

    /** @return array{id:int} */
    private function createCategory(string $name): array
    {
        $suffix = bin2hex(random_bytes(4));
        $cat = $this->postJson('api/v1/facilities/waste-categories', [
            'code' => "prof-cat-{$suffix}",
            'name' => "{$name} {$suffix}",
        ], 201);
        return ['id' => (int) $cat['data']['id']];
    }

    /** @return array<string, mixed> the created unit payload */
    private function createUnit(array $body): array
    {
        $device = $this->createBmgDevice();
        $suffix = bin2hex(random_bytes(4));
        return $this->postJson('api/v1/facilities/units', [
            'code'         => "prof-unit-{$suffix}",
            'display_name' => "Profile Drum {$suffix}",
            'device_id'    => $device['deviceId'],
            ...$body,
        ], 201);
    }

    // --- creation profile --------------------------------------------------

    public function testCreateUnitProfilesCategoriesAndSumsTheDrums(): void
    {
        $c1 = $this->createCategory('Food Scraps');
        $c2 = $this->createCategory('Yard Waste');

        $unit = $this->createUnit([
            // A client-supplied total must never override the drums' sum.
            'spec_capacity_kg'      => 999,
            'drum_one_capacity_kg'  => 4,
            'drum_two_capacity_kg'  => 6.5,
            'category_ids'          => [$c1['id'], $c2['id']],
        ]);

        $data = $unit['data'];
        $this->assertSame(10.5, $data['spec_capacity_kg'], 'Unit capacity must be the sum of the two drums.');
        // JSON round-trips whole floats as ints — compare numerically.
        $this->assertSame(4.0, (float) $data['drum_one_capacity_kg']);
        $this->assertSame(6.5, (float) $data['drum_two_capacity_kg']);
        $this->assertSame(
            [['id' => $c1['id'], 'name' => $data['categories'][0]['name']], ['id' => $c2['id'], 'name' => $data['categories'][1]['name']]],
            $data['categories'],
        );
        // The first designated category seeds the default (suggest/fallback).
        $this->assertSame($c1['id'], $data['default_category_id']);

        // The list surface carries the same profile for the drum grid.
        $units = $this->getJson('api/v1/facilities/units?limit=100');
        $mine  = array_values(array_filter(
            $units['data'],
            static fn (array $u): bool => (int) $u['id'] === (int) $data['id'],
        ));
        $this->assertCount(1, $mine);
        $this->assertCount(2, $mine[0]['categories']);
        $this->assertSame(10.5, $mine[0]['spec_capacity_kg']);
    }

    public function testCreateUnitRejectsALoneDrumCapacity(): void
    {
        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units', [
            'code'                 => 'prof-lone-' . bin2hex(random_bytes(4)),
            'display_name'         => 'Lone Drum Capacity',
            'device_id'            => $this->createBmgDevice()['deviceId'],
            'drum_one_capacity_kg' => 4,
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.invalid', $res);
    }

    public function testCreateUnitRejectsASubFourKiloDrum(): void
    {
        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units', [
            'code'                 => 'prof-small-' . bin2hex(random_bytes(4)),
            'display_name'         => 'Small Drum',
            'device_id'            => $this->createBmgDevice()['deviceId'],
            'drum_one_capacity_kg' => 3,
            'drum_two_capacity_kg' => 6,
        ]);
        $res->assertStatus(422);
        $fields = array_column($this->envelope($res)['errors'], 'field');
        $this->assertContains('drum_one_capacity_kg', $fields);
    }

    public function testCreateUnitRejectsUnknownCategoryIds(): void
    {
        $res = $this->authed($this->token(), 'post', 'api/v1/facilities/units', [
            'code'         => 'prof-ghost-' . bin2hex(random_bytes(4)),
            'display_name' => 'Ghost Category Drum',
            'device_id'    => $this->createBmgDevice()['deviceId'],
            'category_ids' => [999999999],
        ]);
        $res->assertStatus(404);
        $fields = array_column($this->envelope($res)['errors'], 'field');
        $this->assertContains('category_ids', $fields);
    }

    // --- batch start restriction --------------------------------------------

    public function testStartBatchIsRestrictedToTheDesignatedCategories(): void
    {
        $c1   = $this->createCategory('Food Scraps');
        $c2   = $this->createCategory('Yard Waste');
        $c3   = $this->createCategory('Bones');
        $unit = $this->createUnit([
            'category_ids' => [$c1['id'], $c2['id']],
        ]);
        $unitId = (int) $unit['data']['id'];

        // A category outside the drum's profile is refused…
        $res = $this->authed($this->token(), 'post', "api/v1/facilities/units/{$unitId}/start", [
            'total_input_weight_kg' => 10,
            'composition'           => [['category_id' => $c3['id'], 'weight_kg' => 10]],
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.invalid', $res);

        // …while a mix drawn from the profile starts fine.
        $ok = $this->postJson("api/v1/facilities/units/{$unitId}/start", [
            'total_input_weight_kg' => 10,
            'composition'           => [
                ['category_id' => $c1['id'], 'weight_kg' => 6],
                ['category_id' => $c2['id'], 'weight_kg' => 4],
            ],
        ], 201);
        $this->assertSame('processing', $ok['data']['status']);
    }

    // --- re-profiling --------------------------------------------------------

    public function testUpdateUnitReprofilesCategoriesAndDrums(): void
    {
        $c1 = $this->createCategory('Food Scraps');
        $c2 = $this->createCategory('Yard Waste');
        $unit = $this->createUnit(['category_ids' => [$c1['id']]]);
        $unitId = (int) $unit['data']['id'];

        $updated = $this->postJson('api/v1/facilities/units/' . $unitId, [
            'category_ids'         => [$c2['id'], $c1['id']],
            'drum_one_capacity_kg' => 5,
            'drum_two_capacity_kg' => 5,
        ]);

        $this->assertSame(10.0, (float) $updated['data']['spec_capacity_kg']);
        $this->assertSame(5.0, (float) $updated['data']['drum_one_capacity_kg']);
        // First selected seeds the default — c2 is now first.
        $this->assertSame($c2['id'], $updated['data']['default_category_id']);
        $this->assertSame([$c2['id'], $c1['id']], array_map(
            static fn (array $c): int => $c['id'],
            $updated['data']['categories'],
        ));
    }

    // --- category delete guard ------------------------------------------------

    public function testDesignatedCategoryCannotBeHardDeleted(): void
    {
        $c1   = $this->createCategory('Food Scraps');
        $unit = $this->createUnit(['category_ids' => [$c1['id']]]);

        $res = $this->authed($this->token(), 'delete', 'api/v1/facilities/waste-categories/' . $c1['id']);
        $res->assertStatus(409);
        $this->assertErrorCode('resource.conflict', $res);

        // Clearing the drum's designation unlocks the delete.
        $this->postJson('api/v1/facilities/units/' . $unit['data']['id'], ['category_ids' => []]);
        $res = $this->authed($this->token(), 'delete', 'api/v1/facilities/waste-categories/' . $c1['id']);
        $res->assertStatus(200);
    }
}
