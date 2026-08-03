<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CurrentTenant;
use PHPUnit\Framework\TestCase;

/**
 * BmgTenantFilterTest — guard against cross-tenant data leaks.
 *
 * Scope: every BmgService query now prepends
 *     `->where('u.tenant_id', CurrentTenant::id())`
 * to its read path, and the row-level ownership check runs the
 * same call. A bug in `CurrentTenant::id()` (silent fallback to
 * 1, sticky state across tests, env-var leak) would silently
 * re-open the cross-tenant hole.
 *
 * The actual `BmgService::listUnits()` filter is integration-only
 * (the unit suite deliberately avoids booting the framework per
 * `tests/bootstrap.php`). What we CAN exercise here is the
 * `CurrentTenant` static helper itself, which the service composes
 * into every query.
 *
 * If you ever need a full "tenant 2 sees zero rows" test, run it
 * against an integration DB — not the unit suite.
 */
final class BmgTenantFilterTest extends TestCase
{
    protected function setUp(): void
    {
        // Always reset between tests so static state cannot leak.
        CurrentTenant::reset();
        // Clear any environment override left over from a previous run.
        putenv('SYNAPSE_TENANT_ID');
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        putenv('SYNAPSE_TENANT_ID');
    }

    public function testDefaultTenantIsOne(): void
    {
        $this->assertSame(1, CurrentTenant::id(), 'Single-tenant deployments must default to tenant 1.');
    }

    public function testSetOverridesDefault(): void
    {
        CurrentTenant::set(2);
        $this->assertSame(2, CurrentTenant::id());
    }

    public function testSetToArbitraryTenantIdRoundTrips(): void
    {
        // Defensive: the helper must accept any positive int, not
        // just 1 or 2. A future multi-tenant deployment could have
        // dozens of tenants.
        foreach ([1, 2, 7, 42, 9999] as $id) {
            CurrentTenant::set($id);
            $this->assertSame($id, CurrentTenant::id(), "Tenant id {$id} must round-trip through the helper.");
        }
    }

    public function testResetRevertsToDefault(): void
    {
        CurrentTenant::set(5);
        $this->assertSame(5, CurrentTenant::id());

        CurrentTenant::reset();
        $this->assertSame(1, CurrentTenant::id(), 'reset() must restore the default tenant 1.');
    }

    public function testSetNullRevertsToDefault(): void
    {
        CurrentTenant::set(3);
        $this->assertSame(3, CurrentTenant::id());

        // Passing null is documented as the way to clear the override.
        CurrentTenant::set(null);
        $this->assertSame(1, CurrentTenant::id());
    }

    public function testEnvOverrideIsHonouredWhenSet(): void
    {
        putenv('SYNAPSE_TENANT_ID=4');
        CurrentTenant::reset();

        $this->assertSame(4, CurrentTenant::id(), 'Env override SYNAPSE_TENANT_ID must take precedence on first read.');
    }

    public function testExplicitSetBeatsEnvOverrideAfterFirstRead(): void
    {
        // Once `set()` is called, the static cache is authoritative —
        // the env var is no longer consulted until `reset()`.
        putenv('SYNAPSE_TENANT_ID=4');
        CurrentTenant::set(7);
        $this->assertSame(7, CurrentTenant::id());
    }

    public function testResetRe_readsEnvOverride(): void
    {
        putenv('SYNAPSE_TENANT_ID=4');
        CurrentTenant::set(7);
        $this->assertSame(7, CurrentTenant::id());

        CurrentTenant::reset();
        $this->assertSame(4, CurrentTenant::id(), 'reset() must re-read the env var, not go back to the hardcoded 1.');
    }

    public function testZeroIsAValidTenantId(): void
    {
        // The BmgService queries pass the value straight into a WHERE
        // clause; a tenant of 0 must be preserved (callers should not
        // have to defend against odd ids at the helper layer).
        CurrentTenant::set(0);
        $this->assertSame(0, CurrentTenant::id());
    }
}
