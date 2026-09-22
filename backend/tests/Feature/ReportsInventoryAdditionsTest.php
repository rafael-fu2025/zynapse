<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * ReportsInventoryAdditionsTest — cover the two Reports-module inventory
 * endpoints added with the reports UI rework (2026-09-15):
 *
 *   - GET /api/v1/reports/inventory/forecast  — trailing 30-day usage
 *     model; only medicines whose predicted stockout falls inside the
 *     horizon are returned, soonest first;
 *   - GET /api/v1/reports/inventory/purchases — reorder-request activity
 *     for the range: totals, per-status pipeline, and a Manila-day
 *     bucketed daily trend.
 *
 * The suite does not truncate between cases (see FeatureTestCase), so
 * every row is created with unique names / SKUs.
 */
final class ReportsInventoryAdditionsTest extends FeatureTestCase
{
    /** @var array{token:string, userId:int, email:string} */
    private array $admin = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(['clinic_admin']);
    }

    // ---------------------------------------------------------- helpers

    private function makeMedicine(int $reorderThreshold = 5): int
    {
        $res = $this->authed($this->admin['token'], 'post', 'api/v1/clinic/medicines', [
            'generic_name'      => 'Forecastamine ' . bin2hex(random_bytes(3)),
            'reorder_threshold' => $reorderThreshold,
        ]);
        $res->assertStatus(201);

        return (int) $this->envelope($res)['data']['id'];
    }

    private function insertActiveBatch(int $medicineId, int $qty): int
    {
        $this->db->table('clinic_medicine_batches')->insert([
            'tenant_id'          => 1,
            'medicine_id'        => $medicineId,
            'batch_number'       => 'RPT-' . bin2hex(random_bytes(4)),
            'quantity_received'  => $qty,
            'quantity_remaining' => $qty,
            'expiration_date'    => date('Y-m-d', strtotime('+365 days')),
            'received_date'      => date('Y-m-d', strtotime('-7 days')),
            'status'             => 'active',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Record a dispense transaction directly, created_at pinned so tests
     * control whether it lands inside the trailing 30-day usage window.
     */
    private function insertDispense(int $medicineId, int $batchId, int $qty, string $createdAt): void
    {
        $this->db->table('clinic_medicine_transactions')->insert([
            'tenant_id'            => 1,
            'medicine_id'          => $medicineId,
            'batch_id'             => $batchId,
            'type'                 => 'dispensed',
            'quantity'             => $qty,
            'performed_by_user_id' => $this->admin['userId'],
            'created_at'           => $createdAt,
        ]);
    }

    /** Create a reorder request directly at the given status + created_at. */
    private function insertReorder(int $medicineId, int $qty, string $status, string $createdAt): int
    {
        $this->db->table('clinic_reorder_requests')->insert([
            'tenant_id'          => 1,
            'medicine_id'        => $medicineId,
            'requested_quantity' => $qty,
            'current_stock'      => 0,
            'reorder_level'      => 5,
            'urgency'            => 'medium',
            'status'             => $status,
            'auto_triggered'     => 0,
            'requested_by_user_id' => $this->admin['userId'],
            'created_at'         => $createdAt,
            'updated_at'         => $createdAt,
        ]);

        return (int) $this->db->insertID();
    }

    /** @return array<string, mixed> */
    private function getJson(string $route, int $expect = 200): array
    {
        $res = $this->authed($this->admin['token'], 'get', $route);
        $res->assertStatus($expect);

        return $this->envelope($res);
    }

    // ------------------------------------------------------- forecast

    public function testForecastListsOnlyMedicinesStockingOutWithinHorizon(): void
    {
        // Depleting medicine: 10 units on hand, 300 dispensed in the last
        // 30 days -> ~10 units/day -> stockout in ~1 day.
        $depleting = $this->makeMedicine();
        $batch = $this->insertActiveBatch($depleting, 10);
        $this->insertDispense($depleting, $batch, 300, date('Y-m-d H:i:s', strtotime('-2 days')));

        // Healthy medicine: 10,000 units on hand, same usage -> far beyond
        // any horizon.
        $healthy = $this->makeMedicine();
        $batch = $this->insertActiveBatch($healthy, 10_000);
        $this->insertDispense($healthy, $batch, 300, date('Y-m-d H:i:s', strtotime('-2 days')));

        $body = $this->getJson('api/v1/reports/inventory/forecast?within_days=90');

        $this->assertSame(90, $body['data']['within_days']);
        $names = array_column($body['data']['items'], 'generic_name');
        $this->assertContains($this->medicineName($depleting), $names);
        $this->assertNotContains($this->medicineName($healthy), $names);

        // Item shape for the UI cards/table.
        foreach ($body['data']['items'] as $item) {
            $this->assertArrayHasKey('predicted_daily_usage', $item);
            $this->assertArrayHasKey('stockout_date', $item);
            $this->assertArrayHasKey('reorder_date', $item);
        }
    }

    public function testForecastItemsSortSoonestStockoutFirst(): void
    {
        $sooner = $this->makeMedicine();
        $batch = $this->insertActiveBatch($sooner, 5);
        $this->insertDispense($sooner, $batch, 300, date('Y-m-d H:i:s', strtotime('-1 day')));

        $later = $this->makeMedicine();
        $batch = $this->insertActiveBatch($later, 150);
        $this->insertDispense($later, $batch, 300, date('Y-m-d H:i:s', strtotime('-1 day')));

        $body = $this->getJson('api/v1/reports/inventory/forecast?within_days=90');

        $dates = array_column($body['data']['items'], 'stockout_date');
        $sorted = $dates;
        sort($sorted);
        // Only assert on OUR two medicines' relative order — other rows
        // from previous cases share the tenant.
        $soonerIdx = array_search($this->medicineName($sooner), array_column($body['data']['items'], 'generic_name'), true);
        $laterIdx  = array_search($this->medicineName($later), array_column($body['data']['items'], 'generic_name'), true);
        $this->assertNotFalse($soonerIdx);
        $this->assertNotFalse($laterIdx);
        $this->assertLessThan($laterIdx, $soonerIdx);
    }

    public function testForecastUsageWindowIgnoresDispensesOlderThan30Days(): void
    {
        // Old usage only: 300 units dispensed 60 days ago. The trailing
        // 30-day window sees zero, so the baseline rate (0.25/day) applies
        // and 100 units last ~400 days — outside a 90-day horizon.
        $medicine = $this->makeMedicine();
        $batch = $this->insertActiveBatch($medicine, 100);
        $this->insertDispense($medicine, $batch, 300, date('Y-m-d H:i:s', strtotime('-60 days')));

        $body = $this->getJson('api/v1/reports/inventory/forecast?within_days=90');
        $names = array_column($body['data']['items'], 'generic_name');
        $this->assertNotContains($this->medicineName($medicine), $names);
    }

    public function testForecastValidatesWithinDays(): void
    {
        $res = $this->authed($this->admin['token'], 'get', 'api/v1/reports/inventory/forecast?within_days=0');
        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);

        $res = $this->authed($this->admin['token'], 'get', 'api/v1/reports/inventory/forecast?within_days=366');
        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);
    }

    // ------------------------------------------------------ purchases

    public function testPurchasesAggregatesTotalsStatusesAndTrend(): void
    {
        $medicine = $this->makeMedicine();

        $start = date('Y-m-d', strtotime('-3 days'));
        $end   = date('Y-m-d');
        // The suite never truncates, so the range may also contain rows
        // from earlier runs. Track exactly the ids created here and assert
        // against those only.
        $mine[] = $this->insertReorder($medicine, 10, 'received', date('Y-m-d H:i:s', strtotime('-2 days')));
        $mine[] = $this->insertReorder($medicine, 20, 'pending', date('Y-m-d H:i:s', strtotime('-1 day')));
        // Outside the range — must not be counted.
        $this->insertReorder($medicine, 99, 'cancelled', date('Y-m-d H:i:s', strtotime('-10 days')));

        $body = $this->getJson("api/v1/reports/inventory/purchases?start={$start}&end={$end}");

        // Fetch this test's own rows back so the assertions are scoped to
        // the ids created above, regardless of what earlier runs left.
        $mineRows = $this->db->table('clinic_reorder_requests')
            ->whereIn('id', $mine)
            ->select('status, requested_quantity')->get()->getResultArray();
        $mineCount = count($mineRows);
        $mineUnits = array_sum(array_map(static fn (array $r): int => (int) $r['requested_quantity'], $mineRows));

        $this->assertGreaterThanOrEqual($mineCount, $body['data']['total_purchases']);
        $this->assertGreaterThanOrEqual($mineUnits, $body['data']['total_units']);

        // Both of my rows fall on distinct Manila days inside the range:
        // the trend must contain their two buckets (computed in Manila time).
        $trendDays = array_column($body['data']['daily_trend'], 'day');
        $manilaTz = new \DateTimeZone('Asia/Manila');
        $this->assertContains((new \DateTimeImmutable('-2 days', $manilaTz))->format('Y-m-d'), $trendDays);
        $this->assertContains((new \DateTimeImmutable('-1 day', $manilaTz))->format('Y-m-d'), $trendDays);

        // Status pipeline rows for my statuses exist with correct shapes.
        $byStatus = [];
        foreach ($body['data']['by_status'] as $row) {
            $byStatus[$row['status']] = $row;
        }
        $this->assertArrayHasKey('received', $byStatus);
        $this->assertArrayHasKey('pending', $byStatus);
        $this->assertGreaterThanOrEqual(10, $byStatus['received']['qty']);
        $this->assertGreaterThanOrEqual(20, $byStatus['pending']['qty']);
    }

    public function testPurchasesEmptyRangeReturnsZeros(): void
    {
        $start = date('Y-m-d', strtotime('+100 days'));
        $end   = date('Y-m-d', strtotime('+101 days'));

        $body = $this->getJson("api/v1/reports/inventory/purchases?start={$start}&end={$end}");

        $this->assertSame(0, $body['data']['total_purchases']);
        $this->assertSame(0, $body['data']['total_units']);
        $this->assertSame([], $body['data']['by_status']);
        $this->assertSame([], $body['data']['daily_trend']);
    }

    // ---------------------------------------------------------- rbac

    public function testEndpointsRequireReportsRead(): void
    {
        $plain = $this->login(['student']);

        $res = $this->authed($plain['token'], 'get', 'api/v1/reports/inventory/forecast');
        $res->assertStatus(403);

        $res = $this->authed($plain['token'], 'get', 'api/v1/reports/inventory/purchases');
        $res->assertStatus(403);
    }

    // ------------------------------------------------------- internal

    private function medicineName(int $medicineId): string
    {
        return (string) $this->db->table('clinic_medicines')
            ->select('generic_name')->where('id', $medicineId)
            ->get()->getRowArray()['generic_name'];
    }
}
