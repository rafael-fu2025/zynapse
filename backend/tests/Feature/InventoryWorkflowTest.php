<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Shared\ManilaDay;

/**
 * InventoryWorkflowTest — end-to-end cover of the clinic inventory
 * invariants that live in DB-bound services (the unit suite only
 * reaches the pure policy/forecaster classes):
 *
 *   - supply ledger: single write path, negative-stock guard,
 *     encounter-anchored dispenses, procurement-gated receipts,
 *     cumulative partial deliveries;
 *   - medicine ledger: FEFO batch consumption, the Manila-day expiry
 *     boundary, batch write-off + URL medicine-id verification,
 *     quantity-0 receive rejection;
 *   - procurement: one open request per item, auto-check no-duplication;
 *   - RBAC boundary + per-tenant SKU namespace.
 *
 * The suite does not truncate between cases (see FeatureTestCase), so
 * every row is created with unique SKUs / batch numbers / emails.
 */
final class InventoryWorkflowTest extends FeatureTestCase
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

    private function uniqueSku(): string
    {
        return 'TST-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /** @return array<string, mixed> the created item (DTO shape) */
    private function makeSupply(int $reorderLevel = 0): array
    {
        return $this->postJson('api/v1/clinic/inventory', [
            'sku'           => $this->uniqueSku(),
            'name'          => 'Feature-test supply',
            'unit'          => 'pc',
            'reorder_level' => $reorderLevel,
        ], 201)['data'];
    }

    private function makeMedicine(): int
    {
        $body = $this->postJson('api/v1/clinic/medicines', [
            'generic_name'      => 'Testophyllin ' . bin2hex(random_bytes(3)),
            'reorder_threshold' => 0,
        ], 201);

        return (int) $body['data']['id'];
    }

    private function makeOpenEncounter(): int
    {
        $number = '2026' . random_int(100000, 999999);
        $this->postJson('api/v1/clinic/students', [
            'student_number' => $number,
            'first_name'     => 'Inventory',
            'last_name'      => 'Workflow',
            'course'         => 'BSIT',
            'year_level'     => 1,
        ], 201);

        $body = $this->postJson('api/v1/clinic/encounters', [
            'patient_school_id' => $number,
            'chief_complaint'   => 'Inventory feature test',
        ], 201);

        return (int) $body['data']['id'];
    }

    /**
     * Drive a reorder request through approve → order → receive and
     * return its id (status `received` = delivery arrived, stock not
     * yet entered).
     */
    private function makeReceivedReorder(string $itemType, int $itemId, int $quantity): int
    {
        $payload = [
            'item_type' => $itemType,
            'quantity'  => $quantity,
        ];
        $payload[$itemType === 'supply' ? 'supply_item_id' : 'medicine_id'] = $itemId;

        $id = (int) $this->postJson('api/v1/clinic/reorders', $payload, 201)['data']['id'];
        $this->postJson("api/v1/clinic/reorders/{$id}/transition", ['action' => 'approve']);
        $this->postJson("api/v1/clinic/reorders/{$id}/transition", ['action' => 'order']);
        $this->postJson("api/v1/clinic/reorders/{$id}/transition", ['action' => 'receive']);

        return $id;
    }

    /**
     * Insert an ACTIVE batch row directly — skips the reorder gate for
     * tests where the procurement chain is not what's under test.
     */
    private function insertBatch(int $medicineId, string $batchNumber, string $expirationDate, int $qty): int
    {
        $this->db->table('clinic_medicine_batches')->insert([
            'tenant_id'          => 1,
            'medicine_id'        => $medicineId,
            'batch_number'       => $batchNumber,
            'quantity_received'  => $qty,
            'quantity_remaining' => $qty,
            'expiration_date'    => $expirationDate,
            'received_date'      => date('Y-m-d', strtotime('-7 days')),
            'status'             => 'active',
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->insertID();
    }

    // ----------------------------------------------------- supply ledger

    public function testSupplyDispenseRequiresAnOpenEncounter(): void
    {
        $item = $this->makeSupply();
        $this->postJson("api/v1/clinic/inventory/{$item['id']}/move", [
            'qty_delta'   => 100,
            'reason_code' => 'adjustment',
            'note'        => 'opening stock',
        ]);

        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/inventory/{$item['id']}/move", [
            'qty_delta'   => -5,
            'reason_code' => 'dispense',
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);
    }

    public function testSupplyDispenseRejectsClosedEncounter(): void
    {
        $item = $this->makeSupply();
        $this->postJson("api/v1/clinic/inventory/{$item['id']}/move", [
            'qty_delta'   => 100,
            'reason_code' => 'adjustment',
        ]);

        $encounterId = $this->makeOpenEncounter();
        // Completion now requires the clinical record to exist first
        // (2026-09-25 meeting): record a diagnosis, then close.
        $this->postJson("api/v1/clinic/encounters/{$encounterId}/assessment", ['diagnosis' => 'Feature-test diagnosis']);
        $this->postJson("api/v1/clinic/encounters/{$encounterId}/close", []);

        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/inventory/{$item['id']}/move", [
            'qty_delta'    => -5,
            'reason_code'  => 'dispense',
            'encounter_id' => $encounterId,
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.clinic.encounter_closed', $res);
    }

    public function testSupplyAdjustmentCannotDriveStockNegative(): void
    {
        $item = $this->makeSupply();

        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/inventory/{$item['id']}/move", [
            'qty_delta'   => -1,
            'reason_code' => 'adjustment',
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.inventory.negative_stock', $res);
    }

    public function testSupplyMoveRejectsReceiveReasonAtValidation(): void
    {
        $item = $this->makeSupply();

        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/inventory/{$item['id']}/move", [
            'qty_delta'   => 10,
            'reason_code' => 'receive',
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);
    }

    public function testSupplyReceiveWithoutReorderIsRejected(): void
    {
        $item = $this->makeSupply();

        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/inventory/{$item['id']}/receive", []);
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.reorder.not_received', $res);
    }

    public function testSupplyPartialReceiveKeepsReorderOpen(): void
    {
        $item     = $this->makeSupply();
        $reorderId = $this->makeReceivedReorder('supply', (int) $item['id'], 50);

        $body = $this->postJson("api/v1/clinic/inventory/{$item['id']}/receive", [
            'quantity'      => 30,
            'shortage_note' => 'half shipment missing',
        ]);
        $this->assertSame(30, $body['data']['quantity_on_hand']);

        $movement = $this->db->table('clinic_inventory_movements')
            ->where('item_id', $item['id'])->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('receive', $movement['reason_code']);
        $this->assertSame(30, (int) $movement['qty_delta']);
        $this->assertSame(30, (int) $movement['balance_after']);
        $this->assertSame('reorder', $movement['reference_type']);
        $this->assertSame($reorderId, (int) $movement['reference_id']);
        $this->assertStringContainsString('short by 20', (string) $movement['note']);
        $this->assertStringContainsString('half shipment missing', (string) $movement['note']);

        $reorder = $this->db->table('clinic_reorder_requests')->where('id', $reorderId)->get()->getRowArray();
        $this->assertSame('received', $reorder['status'], 'partial delivery must keep the reorder open');
    }

    public function testSupplyFullReceiveCompletesReorder(): void
    {
        $item      = $this->makeSupply();
        $reorderId = $this->makeReceivedReorder('supply', (int) $item['id'], 25);

        $body = $this->postJson("api/v1/clinic/inventory/{$item['id']}/receive", ['note' => 'all good']);
        $this->assertSame(25, $body['data']['quantity_on_hand']);

        $reorder = $this->db->table('clinic_reorder_requests')->where('id', $reorderId)->get()->getRowArray();
        $this->assertSame('completed', $reorder['status']);
        $this->assertNotNull($reorder['fulfilled_at']);
    }

    public function testSupplyFollowUpReceiveOfRemainderClosesReorder(): void
    {
        $item      = $this->makeSupply();
        $reorderId = $this->makeReceivedReorder('supply', (int) $item['id'], 40);

        $this->postJson("api/v1/clinic/inventory/{$item['id']}/receive", ['quantity' => 25]);
        $body = $this->postJson("api/v1/clinic/inventory/{$item['id']}/receive", ['quantity' => 15]);
        $this->assertSame(40, $body['data']['quantity_on_hand']);

        $reorder = $this->db->table('clinic_reorder_requests')->where('id', $reorderId)->get()->getRowArray();
        $this->assertSame('completed', $reorder['status'], 'the receive that brings the booked total to the ordered amount must close the loop');
    }

    // ---------------------------------------------------- medicine ledger

    public function testMedicineBatchReceiveRejectsZeroQuantity(): void
    {
        $medicineId = $this->makeMedicine();
        $this->makeReceivedReorder('medicine', $medicineId, 40);

        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/medicines/{$medicineId}/batches", [
            'batch_number'    => 'B0-' . bin2hex(random_bytes(3)),
            'expiration_date' => date('Y-m-d', strtotime('+60 days')),
            'quantity'        => 0,
        ]);
        $res->assertStatus(422);
        $this->assertErrorCode('validation.field', $res);

        // Nothing was booked — a 0 quantity must not silently receive
        // the full ordered amount.
        $this->assertSame(0, $this->db->table('clinic_medicine_batches')
            ->where('medicine_id', $medicineId)->countAllResults());
    }

    public function testMedicinePartialThenRemainderCompletesReorder(): void
    {
        $medicineId = $this->makeMedicine();
        $reorderId  = $this->makeReceivedReorder('medicine', $medicineId, 40);

        // Partial: 30 of 40 — reorder stays `received`.
        $this->postJson("api/v1/clinic/medicines/{$medicineId}/batches", [
            'batch_number'    => 'BP-' . bin2hex(random_bytes(3)),
            'expiration_date' => date('Y-m-d', strtotime('+90 days')),
            'quantity'        => 30,
        ], 201);

        $reorder = $this->db->table('clinic_reorder_requests')->where('id', $reorderId)->get()->getRowArray();
        $this->assertSame('received', $reorder['status']);

        $txn = $this->db->table('clinic_medicine_transactions')
            ->where('medicine_id', $medicineId)->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame('received', $txn['type']);
        $this->assertSame(30, (int) $txn['quantity']);
        $this->assertSame(30, (int) $txn['balance_after']);
        $this->assertSame('reorder', $txn['reference_type'], 'received batches must carry their reorder reference');
        $this->assertSame($reorderId, (int) $txn['reference_id']);
        $this->assertStringContainsString('short by 10', (string) $txn['note']);

        // The remainder (10) closes the loop.
        $this->postJson("api/v1/clinic/medicines/{$medicineId}/batches", [
            'batch_number'    => 'BP2-' . bin2hex(random_bytes(3)),
            'expiration_date' => date('Y-m-d', strtotime('+120 days')),
            'quantity'        => 10,
        ], 201);

        $reorder = $this->db->table('clinic_reorder_requests')->where('id', $reorderId)->get()->getRowArray();
        $this->assertSame('completed', $reorder['status']);
    }

    public function testDispenseIsFefoAndDrainsEarliestBatchFirst(): void
    {
        $medicineId = $this->makeMedicine();
        $earlyId = $this->insertBatch($medicineId, 'FEFO-E-' . bin2hex(random_bytes(3)), date('Y-m-d', strtotime('+30 days')), 10);
        $lateId  = $this->insertBatch($medicineId, 'FEFO-L-' . bin2hex(random_bytes(3)), date('Y-m-d', strtotime('+60 days')), 10);
        $encounterId = $this->makeOpenEncounter();

        $body = $this->postJson("api/v1/clinic/medicines/{$medicineId}/dispense", [
            'quantity'     => 15,
            'encounter_id' => $encounterId,
        ]);
        $this->assertSame(5, $body['data']['quantity_on_hand']);

        $early = $this->db->table('clinic_medicine_batches')->where('id', $earlyId)->get()->getRowArray();
        $this->assertSame(0, (int) $early['quantity_remaining']);
        $this->assertSame('depleted', $early['status']);

        $late = $this->db->table('clinic_medicine_batches')->where('id', $lateId)->get()->getRowArray();
        $this->assertSame(5, (int) $late['quantity_remaining']);
        $this->assertSame('active', $late['status']);

        $txns = $this->db->table('clinic_medicine_transactions')
            ->where('medicine_id', $medicineId)->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $txns);
        $this->assertSame('dispensed', $txns[0]['type']);
        $this->assertSame(10, (int) $txns[0]['quantity']);
        $this->assertSame('encounter', $txns[0]['reference_type']);
        $this->assertSame($encounterId, (int) $txns[0]['reference_id']);
        $this->assertSame(5, (int) $txns[1]['quantity']);
    }

    /**
     * The Manila-day expiry boundary: a lot that expired YESTERDAY
     * (Manila calendar) is neither on-hand nor dispensable, while a lot
     * expiring TODAY still is. Between 16:00–24:00 UTC the Manila date
     * is ahead of UTC, which is exactly when the old UTC-based filters
     * resurrected expired stock (2026-09-13 inventory audit, P1).
     */
    public function testManilaDayExpiryBoundaryExcludesYesterdayAndIncludesToday(): void
    {
        $medicineId = $this->makeMedicine();
        $today      = ManilaDay::today();
        $yesterday  = date('Y-m-d', strtotime($today . ' -1 day'));
        $this->insertBatch($medicineId, 'MNL-Y-' . bin2hex(random_bytes(3)), $yesterday, 99);
        $this->insertBatch($medicineId, 'MNL-T-' . bin2hex(random_bytes(3)), $today, 10);

        // On-hand counts ONLY today's lot.
        $res  = $this->authed($this->admin['token'], 'get', "api/v1/clinic/medicines/{$medicineId}");
        $body = $this->envelope($res);
        $this->assertSame(10, $body['data']['quantity_on_hand']);
        $this->assertSame($today, $body['data']['earliest_expiry']);

        // Today's stock is dispensable...
        $encounterId = $this->makeOpenEncounter();
        $body = $this->postJson("api/v1/clinic/medicines/{$medicineId}/dispense", [
            'quantity'     => 10,
            'encounter_id' => $encounterId,
        ]);
        $this->assertSame(0, $body['data']['quantity_on_hand']);

        // ...but the Manila-expired lot is not.
        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/medicines/{$medicineId}/dispense", [
            'quantity'     => 1,
            'encounter_id' => $encounterId,
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('statemachine.inventory.insufficient_stock', $res);
    }

    public function testExpireBatchVerifiesMedicineIdInUrl(): void
    {
        $medicineA = $this->makeMedicine();
        $medicineB = $this->makeMedicine();
        $batchId   = $this->insertBatch($medicineA, 'WO-' . bin2hex(random_bytes(3)), date('Y-m-d', strtotime('+30 days')), 10);

        $res = $this->authed($this->admin['token'], 'post', "api/v1/clinic/medicines/{$medicineB}/batches/{$batchId}/expire", []);
        $res->assertStatus(404);
        $this->assertErrorCode('resource.not_found', $res);

        // Correct URL still works and writes the batch off.
        $body = $this->postJson("api/v1/clinic/medicines/{$medicineA}/batches/{$batchId}/expire", ['note' => 'lab recall']);
        $this->assertSame('expired', $body['data']['status']);
        $this->assertSame(10, (int) $body['data']['quantity_written_off']);
    }

    // --------------------------------------------------------- procurement

    public function testOneOpenReorderPerItemAndAutoCheckDoesNotDuplicate(): void
    {
        $item = $this->makeSupply(10); // reorder_level 10, on_hand 0 → below threshold
        $this->makeReceivedReorder('supply', (int) $item['id'], 20);

        $res = $this->authed($this->admin['token'], 'post', 'api/v1/clinic/reorders', [
            'item_type'      => 'supply',
            'supply_item_id' => $item['id'],
            'quantity'       => 5,
        ]);
        $res->assertStatus(409);
        $this->assertErrorCode('resource.conflict', $res);

        // Auto-check must respect the open (`received`) request.
        $this->postJson('api/v1/clinic/reorders/auto-check', []);
        $open = $this->db->table('clinic_reorder_requests')
            ->where('supply_item_id', $item['id'])
            ->whereIn('status', ['pending', 'approved', 'ordered', 'received'])
            ->countAllResults();
        $this->assertSame(1, $open);
    }

    // ------------------------------------------------------- RBAC + tenancy

    public function testGrouplessUserCannotReadOrWriteInventory(): void
    {
        $plain = $this->login([]);

        $res = $this->authed($plain['token'], 'post', 'api/v1/clinic/inventory', [
            'sku'  => $this->uniqueSku(),
            'name' => 'Nope',
        ]);
        $res->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:clinic.inventory.write', $res);

        $res = $this->authed($plain['token'], 'get', 'api/v1/clinic/inventory');
        $res->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:clinic.inventory.read', $res);
    }

    /**
     * The SKU namespace is per tenant: tenant 2 may reuse tenant 1's
     * SKU. Under the old GLOBAL UNIQUE(sku) this insert died as a raw
     * 500 after passing the tenant-scoped pre-check.
     */
    public function testSkuNamespaceIsPerTenant(): void
    {
        $sku = $this->uniqueSku();
        $this->postJson('api/v1/clinic/inventory', [
            'sku'  => $sku,
            'name' => 'Tenant 1 item',
        ], 201);

        if ($this->db->table('tenants')->where('id', 2)->countAllResults() === 0) {
            $now = date('Y-m-d H:i:s');
            $this->db->table('tenants')->insert([
                'id'         => 2,
                'name'       => 'Second University',
                'slug'       => 'tenant2-' . bin2hex(random_bytes(3)),
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $user = $this->createUser(['clinic_admin']);
        $this->db->table('users')->where('id', $user['id'])->update(['tenant_id' => 2]);

        $login = $this->withBodyFormat('json')->call('post', 'api/v1/auth/login', [
            'email'    => $user['email'],
            'password' => $user['password'],
        ]);
        $login->assertStatus(200);
        $token = json_decode($login->getJSON() ?: '{}', true)['data']['access_token'];
        $this->assertIsString($token);

        $res = $this->authed($token, 'post', 'api/v1/clinic/inventory', [
            'sku'  => $sku,
            'name' => 'Tenant 2 item',
        ]);
        $res->assertStatus(201);

        // Tenant 2's catalog shows only its own row for that SKU.
        $res  = $this->authed($token, 'get', "api/v1/clinic/inventory?q={$sku}");
        $body = $this->envelope($res);
        $this->assertCount(1, $body['data']);
        $this->assertSame('Tenant 2 item', $body['data'][0]['name']);

        // PrivilegedRoleAssignmentTest sweeps every clinic_admin holder in
        // this stateful schema and demotes them through the tenant-1 admin
        // route — this tenant-2 holder is unreachable there (404) and
        // would break that sweep. Drop the group rows so no privileged
        // holder leaks across tenants between tests.
        $this->db->table('auth_groups_users')->where('user_id', $user['id'])->delete();
    }
}
