<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * EquipmentWorkflowTest — end-to-end cover of the durable-asset catalog
 * (the third inventory catalog): catalog CRUD, per-unit status tracking
 * with its append-only log, and the reports/summary/narrative
 * integration that surfaces replacement needs to management.
 *
 * The suite does not truncate between cases, so every row is created
 * with unique names.
 */
final class EquipmentWorkflowTest extends FeatureTestCase
{
    /** @var array{token:string, userId:int, email:string} */
    private array $admin = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(['clinic_admin']);
    }

    // ---------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function postJson(string $route, array $body, int $expect = 200): array
    {
        $res = $this->authed($this->admin['token'], 'post', $route, $body);
        $res->assertStatus($expect);

        return $this->envelope($res);
    }

    private function uniqueName(string $prefix = 'Test Equipment'): string
    {
        return $prefix . ' ' . bin2hex(random_bytes(3));
    }

    /** @return array<string, mixed> the created equipment detail */
    private function makeEquipment(): array
    {
        return $this->postJson('api/v1/clinic/equipment', [
            'name'     => $this->uniqueName(),
            'category' => 'Diagnostic',
            'location' => 'Test Room',
            'notes'    => 'Feature-test equipment',
        ], 201)['data'];
    }

    // ------------------------------------------------------------ cases

    public function testCreateEquipment(): void
    {
        $name = $this->uniqueName();
        $body = $this->postJson('api/v1/clinic/equipment', [
            'name'     => $name,
            'category' => 'Mobility',
            'location' => 'Receiving Area',
            'notes'    => null,
        ], 201);

        $this->assertSame($name, $body['data']['name']);
        $this->assertSame('Mobility', $body['data']['category']);
        $this->assertFalse($body['data']['archived']);
        $this->assertSame(0, $body['data']['total_units']);
        $this->assertSame([], $body['data']['units']);
    }

    public function testCreateEquipmentValidation(): void
    {
        $res = $this->authed($this->admin['token'], 'post', 'api/v1/clinic/equipment', [
            'name' => '',
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);
    }

    public function testAddUnitsStartWorkingAndLogInitialState(): void
    {
        $equipment = $this->makeEquipment();

        $body = $this->postJson("api/v1/clinic/equipment/{$equipment['id']}/units", [
            'quantity'      => 3,
            'acquired_date' => '2025-01-15',
            'note'          => 'Procured batch',
        ], 201);

        $this->assertCount(3, $body['data']['units']);
        $this->assertSame(3, $body['data']['working']);
        $this->assertSame(3, $body['data']['total_units']);

        foreach ($body['data']['units'] as $unit) {
            $this->assertSame('working', $unit['status']);
            $this->assertSame('2025-01-15', $unit['acquired_date']);
        }

        // The initial working state is part of the history.
        $logs = $this->db->table('clinic_equipment_status_log')
            ->whereIn('unit_id', array_map(static fn (array $u): int => (int) $u['id'], $body['data']['units']))
            ->get()->getResultArray();
        $this->assertCount(3, $logs);
        foreach ($logs as $log) {
            $this->assertNull($log['from_status']);
            $this->assertSame('working', $log['to_status']);
            $this->assertSame('Procured batch', $log['note']);
        }
    }

    public function testAddUnitsRejectsZeroQuantity(): void
    {
        $equipment = $this->makeEquipment();

        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/equipment/{$equipment['id']}/units", [
            'quantity' => 0,
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);
    }

    public function testChangeUnitStatusWritesLogAndMirror(): void
    {
        $equipment = $this->makeEquipment();
        $body      = $this->postJson("api/v1/clinic/equipment/{$equipment['id']}/units", ['quantity' => 2], 201);
        $unitId    = (int) $body['data']['units'][0]['id'];

        $body = $this->postJson("api/v1/clinic/equipment/units/{$unitId}/status", [
            'status' => 'for_replacement',
            'note'   => 'Cuff leak beyond repair',
        ]);
        $this->assertSame(1, $body['data']['for_replacement']);
        $this->assertSame(1, $body['data']['working']);

        $unit = $this->db->table('clinic_equipment_units')->where('id', $unitId)->get()->getRowArray();
        $this->assertSame('for_replacement', $unit['status']);
        $this->assertNotNull($unit['status_changed_at']);

        $log = $this->db->table('clinic_equipment_status_log')
            ->where('unit_id', $unitId)->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('working', $log['from_status']);
        $this->assertSame('for_replacement', $log['to_status']);
        $this->assertSame('Cuff leak beyond repair', $log['note']);

        // No-op change (same status) must not append another log row.
        $before = $this->db->table('clinic_equipment_status_log')->where('unit_id', $unitId)->countAllResults();
        $this->postJson("api/v1/clinic/equipment/units/{$unitId}/status", ['status' => 'for_replacement']);
        $after = $this->db->table('clinic_equipment_status_log')->where('unit_id', $unitId)->countAllResults();
        $this->assertSame($before, $after, 'a same-status change must be a no-op');
    }

    public function testListAggregatesPerStatusCounts(): void
    {
        $equipment = $this->makeEquipment();
        $body      = $this->postJson("api/v1/clinic/equipment/{$equipment['id']}/units", ['quantity' => 4], 201);
        $unitIds   = array_map(static fn (array $u): int => (int) $u['id'], $body['data']['units']);

        $this->postJson("api/v1/clinic/equipment/units/{$unitIds[0]}/status", ['status' => 'for_repair', 'note' => 'rattling']);
        $this->postJson("api/v1/clinic/equipment/units/{$unitIds[1]}/status", ['status' => 'for_replacement', 'note' => 'rust']);
        $this->postJson("api/v1/clinic/equipment/units/{$unitIds[2]}/status", ['status' => 'retired', 'note' => 'disposed']);

        $res  = $this->authed($this->admin['token'], 'get', 'api/v1/clinic/equipment?q=' . rawurlencode($equipment['name']));
        $list = $this->envelope($res);
        $this->assertCount(1, $list['data']);
        $row = $list['data'][0];
        $this->assertSame(1, $row['working']);
        $this->assertSame(1, $row['for_repair']);
        $this->assertSame(1, $row['for_replacement']);
        $this->assertSame(1, $row['retired']);
        $this->assertSame(4, $row['total_units']);
    }

    public function testArchiveHidesFromDefaultListAndKeepsUnits(): void
    {
        $equipment = $this->makeEquipment();
        $this->postJson("api/v1/clinic/equipment/{$equipment['id']}/units", ['quantity' => 2], 201);

        $this->postJson("api/v1/clinic/equipment/{$equipment['id']}/archive", []);
        $res = $this->authed($this->admin['token'], 'get', 'api/v1/clinic/equipment?q=' . rawurlencode($equipment['name']));
        $this->assertCount(0, $this->envelope($res)['data'], 'archived equipment must leave the default list');

        $units = $this->db->table('clinic_equipment_units')->where('equipment_id', $equipment['id'])->countAllResults();
        $this->assertSame(2, $units, 'archiving must not touch units');

        // Status changes are refused for archived equipment (read-only).
        $unitId = (int) $this->db->table('clinic_equipment_units')->where('equipment_id', $equipment['id'])->orderBy('id', 'ASC')->get()->getRowArray()['id'];
        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/equipment/units/{$unitId}/status", ['status' => 'retired']);
        $res->assertStatus(404);

        $this->postJson("api/v1/clinic/equipment/{$equipment['id']}/unarchive", []);
        $res = $this->authed($this->admin['token'], 'get', 'api/v1/clinic/equipment?q=' . rawurlencode($equipment['name']));
        $this->assertCount(1, $this->envelope($res)['data'], 'restore returns the equipment');
    }

    public function testUpdateCatalogFields(): void
    {
        $equipment = $this->makeEquipment();
        $body      = $this->postJson("api/v1/clinic/equipment/{$equipment['id']}", [
            'name'     => $equipment['name'],
            'category' => 'Treatment',
            'location' => 'Treatment Room',
            'notes'    => 'Relocated',
        ]);
        $this->assertSame('Treatment', $body['data']['category']);
        $this->assertSame('Treatment Room', $body['data']['location']);
    }

    public function testUnknownEquipmentAndUnitReturn404(): void
    {
        $res = $this->authed($this->admin['token'], 'get', 'api/v1/clinic/equipment/99999999');
        $res->assertStatus(404);
        $this->assertErrorCode('resource.not_found', $res);

        $res = $this->authed($this->admin['token'], 'post', 'api/v1/clinic/equipment/units/99999999/status', ['status' => 'retired']);
        $res->assertStatus(404);
        $this->assertErrorCode('resource.not_found', $res);
    }

    public function testGrouplessUserCannotReadOrWriteEquipment(): void
    {
        $plain = $this->login([]);

        $res = $this->authed($plain['token'], 'post', 'api/v1/clinic/equipment', ['name' => 'Nope']);
        $res->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:clinic.inventory.write', $res);

        $res = $this->authed($plain['token'], 'get', 'api/v1/clinic/equipment');
        $res->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:clinic.inventory.read', $res);
    }

    /**
     * The payoff: the inventory report, summary, and narrative all carry
     * the equipment picture, so a clinic admin's routine report surfaces
     * replacement needs to management.
     */
    public function testReportsSurfaceEquipmentStatus(): void
    {
        $equipment = $this->makeEquipment();
        $body      = $this->postJson("api/v1/clinic/equipment/{$equipment['id']}/units", ['quantity' => 3], 201);
        $unitIds   = array_map(static fn (array $u): int => (int) $u['id'], $body['data']['units']);
        $this->postJson("api/v1/clinic/equipment/units/{$unitIds[0]}/status", ['status' => 'for_replacement', 'note' => 'dead']);
        $this->postJson("api/v1/clinic/equipment/units/{$unitIds[1]}/status", ['status' => 'for_repair', 'note' => 'wobbly']);

        // Module report.
        $res  = $this->authed($this->admin['token'], 'get', 'api/v1/reports/inventory');
        $res->assertStatus(200);
        $report = $this->envelope($res)['data'];
        $this->assertArrayHasKey('equipment', $report);

        $item = null;
        foreach ($report['equipment']['items'] as $row) {
            if ($row['name'] === $equipment['name']) {
                $item = $row;
                break;
            }
        }
        $this->assertNotNull($item, 'our equipment must appear in the report items');
        $this->assertSame(1, $item['working']);
        $this->assertSame(1, $item['for_repair']);
        $this->assertSame(1, $item['for_replacement']);

        $needs = array_values(array_filter(
            $report['equipment']['needs_replacement'],
            static fn (array $r): bool => $r['name'] === $equipment['name'],
        ));
        $this->assertCount(1, $needs);
        $this->assertSame(1, $needs[0]['units']);
        $this->assertNotNull($needs[0]['oldest_flagged']);

        // Summary counter.
        $res    = $this->authed($this->admin['token'], 'get', 'api/v1/reports/summary');
        $res->assertStatus(200);
        $summary = $this->envelope($res)['data'];
        $this->assertGreaterThanOrEqual(1, $summary['inventory']['equipment_for_replacement']);

        // Narrative mentions the equipment picture.
        $res = $this->authed($this->admin['token'], 'post', 'api/v1/reports/narratives/inventory', []);
        $res->assertStatus(201);
        $narrative = $this->envelope($res)['data']['narrative'];
        $this->assertStringContainsString('Equipment status:', $narrative);
        $this->assertStringContainsString('flagged for replacement', $narrative);
    }
}
