<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\AuditClear;
use App\Services\Audit\AuditChainVerifier;
use App\Services\Audit\AuditDrainService;
use App\Services\Audit\AuditOutboxService;
use App\Services\CurrentTenant;

use const SYNAPSE_AUDIT_EVENTS;

/**
 * Per-tenant audit hash chains (v2) over the real drain + verifier.
 *
 * Covers the 2026-10 audit G-2/G-9 remediation:
 *   - interleaved outbox events from two tenants drain into two INDEPENDENT
 *     v2 chains and both verify;
 *   - tampering with a stored payload breaks verification for that tenant
 *     only, with a hash_mismatch divergence;
 *   - synapse:audit-clear leaves a wipe marker in audit_wipe_markers and
 *     plants a v2 genesis row.
 *
 * NOTE on ordering: the audit-clear test truncates the shared audit tables.
 * Tests in this file run in declaration order, so the verification tests
 * execute first; the suite does not re-migrate between tests
 * (FeatureTestCase::$refresh = false), and no other suite test asserts on
 * audit_events contents — the empty post-clear state is usable by all.
 */
final class AuditChainTest extends FeatureTestCase
{
    private int $tenantTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $now = date('Y-m-d H:i:s');
        $slug = 'audit-v2-' . bin2hex(random_bytes(5));
        db_connect()->table('tenants')->insert([
            'name' => 'Audit v2 tenant', 'slug' => $slug, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->tenantTwo = (int) db_connect()->insertID();
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function enqueueFor(int $tenantId, string $action, int $n): void
    {
        CurrentTenant::set($tenantId);
        for ($i = 0; $i < $n; $i++) {
            (new AuditOutboxService())->enqueue($action, 'audit_test_entity', 100 + $i, null, ['outcome' => 'success']);
        }
    }

    public function testInterleavedTenantsDrainIntoIndependentVerifiableChains(): void
    {
        // Interleave: 1, 2, 1, 2, 1 — the outbox is drained in global id
        // order, so a naive single-chain drain would tangle the tenants.
        // (The suite shares one outbox: earlier tests may have left
        // unprocessed rows, so the drained count is a lower bound.)
        $this->enqueueFor(1, 'audit.test_a', 1);
        $this->enqueueFor($this->tenantTwo, 'audit.test_b', 1);
        $this->enqueueFor(1, 'audit.test_a', 1);
        $this->enqueueFor($this->tenantTwo, 'audit.test_b', 1);
        $this->enqueueFor(1, 'audit.test_a', 1);

        $result = (new AuditDrainService())->drain();
        $this->assertGreaterThanOrEqual(5, $result['drained']);
        $this->assertSame(0, $result['failed']);

        $verifier = new AuditChainVerifier();
        $report = $verifier->verify();

        $this->assertTrue($report['ok'], 'Both tenant chains must verify after an interleaved drain.');
        $byTenant = [];
        foreach ($report['tenants'] as $t) {
            $byTenant[$t['tenant_id']] = $t;
        }
        $this->assertArrayHasKey(1, $byTenant);
        $this->assertArrayHasKey($this->tenantTwo, $byTenant);
        $this->assertGreaterThanOrEqual(3, $byTenant[1]['checked']);
        $this->assertGreaterThanOrEqual(2, $byTenant[$this->tenantTwo]['checked']);

        // Per-tenant prev_id linkage: every v2 row points at the previous
        // row of the SAME tenant (or null for its chain head).
        $prevById = db_connect()->table(SYNAPSE_AUDIT_EVENTS)
            ->select('id, tenant_id, prev_id')
            ->where('hash_version', 2)
            ->whereIn('tenant_id', [1, $this->tenantTwo])
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
        $lastPerTenant = [1 => null, $this->tenantTwo => null];
        foreach ($prevById as $row) {
            $tid = (int) $row['tenant_id'];
            $this->assertSame(
                $lastPerTenant[$tid],
                $row['prev_id'] !== null ? (int) $row['prev_id'] : null,
                'prev_id must chain within the tenant, not across tenants.',
            );
            $lastPerTenant[$tid] = (int) $row['id'];
        }
    }

    public function testTamperedPayloadBreaksOnlyThatTenantsChain(): void
    {
        $this->enqueueFor($this->tenantTwo, 'audit.test_tamper', 2);
        $this->enqueueFor(1, 'audit.test_tamper', 2);
        (new AuditDrainService())->drain();

        $victim = db_connect()->table(SYNAPSE_AUDIT_EVENTS)
            ->select('id')
            ->where('hash_version', 2)
            ->where('tenant_id', $this->tenantTwo)
            ->orderBy('id', 'ASC')
            ->limit(1)
            ->get()->getRowArray();
        $this->assertNotNull($victim);

        db_connect()->table(SYNAPSE_AUDIT_EVENTS)
            ->where('id', (int) $victim['id'])
            ->update(['payload_json' => '{"action_code":"audit.tampered"}']);

        $verifier = new AuditChainVerifier();
        $broken = $verifier->verifyTenant($this->tenantTwo);
        $this->assertFalse($broken['ok']);
        $this->assertSame('hash_mismatch', $broken['first_divergence']['reason']);
        $this->assertSame((int) $victim['id'], $broken['first_divergence']['id']);

        // The other tenant's chain is unaffected.
        $this->assertTrue($verifier->verifyTenant(1)['ok']);

        // And the merged report flags it.
        $this->assertFalse($verifier->verify()['ok']);
    }

    public function testAuditClearRecordsWipeMarkerAndPlantsV2Genesis(): void
    {
        $this->enqueueFor(1, 'audit.test_wipe', 1);
        $before = (int) db_connect()->table(SYNAPSE_AUDIT_EVENTS)->countAllResults();
        (new AuditDrainService())->drain();
        $this->assertGreaterThan($before, (int) db_connect()->table(SYNAPSE_AUDIT_EVENTS)->countAllResults());

        CurrentTenant::set(1);
        $exit = (new AuditClear(service('logger'), service('commands')))->run(['yes' => null, 'reason' => 'AuditChainTest wipe']);
        $this->assertSame(0, $exit);

        // The wipe marker survives the truncate with the pre-wipe tail.
        $marker = db_connect()->table('audit_wipe_markers')->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $this->assertNotNull($marker, 'audit-clear must record a wipe marker.');
        $this->assertSame('AuditChainTest wipe', $marker['reason']);
        $this->assertNotSame('', (string) $marker['last_commit_hash']);
        $this->assertGreaterThan(0, (int) $marker['event_count']);

        // The new chain is a single v2 genesis row that verifies.
        $rows = db_connect()->table(SYNAPSE_AUDIT_EVENTS)
            ->select('id, hash_version, tenant_id, action_code')
            ->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['hash_version']);
        $this->assertSame('audit.log_cleared', $rows[0]['action_code']);
        $this->assertTrue((new AuditChainVerifier())->verify()['ok']);
    }
}
