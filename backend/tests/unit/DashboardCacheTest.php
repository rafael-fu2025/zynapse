<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * DashboardCacheKeyTest — verifies cache key isolation rules for the
 * dashboard counters endpoint.
 */
final class DashboardCacheTest extends TestCase
{
    /**
     * Build the cache key using the same algorithm as DashboardController.
     */
    private function buildKey(int $tenantId, array $perms): string
    {
        $signature = ((int) ($perms['clinic'] ?? false)) . ':' .
                     ((int) ($perms['counselling'] ?? false)) . ':' .
                     ((int) ($perms['facilities'] ?? false)) . ':' .
                     ((int) ($perms['referrals'] ?? false)) . ':' .
                     ((int) ($perms['audit'] ?? false));

        return 'dashboard_counters_t' . $tenantId . '_' . md5($signature);
    }

    public function testKeysDifferByTenant(): void
    {
        $perms = ['clinic' => true, 'counselling' => true, 'facilities' => true, 'referrals' => true, 'audit' => true];

        $keyTenant1 = $this->buildKey(1, $perms);
        $keyTenant2 = $this->buildKey(2, $perms);

        $this->assertNotSame($keyTenant1, $keyTenant2);
        $this->assertStringStartsWith('dashboard_counters_t1_', $keyTenant1);
        $this->assertStringStartsWith('dashboard_counters_t2_', $keyTenant2);
    }

    public function testKeysDifferByPermissions(): void
    {
        $clinicOnly = ['clinic' => true, 'counselling' => false, 'facilities' => false, 'referrals' => false, 'audit' => false];
        $allPerms   = ['clinic' => true, 'counselling' => true, 'facilities' => true, 'referrals' => true, 'audit' => true];

        $keyClinic = $this->buildKey(1, $clinicOnly);
        $keyAll    = $this->buildKey(1, $allPerms);

        $this->assertNotSame($keyClinic, $keyAll);
    }

    public function testKeysAreIdenticalForSameTenantAndPermissions(): void
    {
        $permsA = ['clinic' => true, 'counselling' => false, 'facilities' => false, 'referrals' => false, 'audit' => false];
        $permsB = ['clinic' => true, 'counselling' => false, 'facilities' => false, 'referrals' => false, 'audit' => false];

        $keyA = $this->buildKey(1, $permsA);
        $keyB = $this->buildKey(1, $permsB);

        $this->assertSame($keyA, $keyB);
    }
}
