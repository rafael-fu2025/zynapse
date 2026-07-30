<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Rbac\PermissionService;
use PHPUnit\Framework\TestCase;

/**
 * PermissionService::grants() — wildcard-exclusion policy (RBAC_SECURITY_REVIEW R1).
 *
 * Exercises the pure grant decision without a database: sensitive
 * counselling codes must never be satisfied by the admin wildcard alone;
 * they require an explicit code in the resolved effective list.
 */
final class PermissionServiceGrantsTest extends TestCase
{
    private PermissionService $svc;

    protected function setUp(): void
    {
        $this->svc = new PermissionService();
    }

    public function testWildcardDoesNotGrantSensitiveCounsellingCodes(): void
    {
        foreach (PermissionService::WILDCARD_EXCLUSIONS as $code) {
            $this->assertFalse(
                $this->svc->grants(['*'], $code),
                "wildcard must NOT grant excluded code {$code}",
            );
        }
    }

    public function testWildcardGrantsNonSensitiveCodes(): void
    {
        $this->assertTrue($this->svc->grants(['*'], 'clinic.encounters.read'));
        $this->assertTrue($this->svc->grants(['*'], 'facilities.units.manage'));
        $this->assertTrue($this->svc->grants(['*'], 'rbac.manage'));
        $this->assertTrue($this->svc->grants(['*'], 'audit.export'));
    }

    public function testExplicitGrantAllowsSensitiveCode(): void
    {
        $counsellor = [
            'counselling.records.create',
            'counselling.records.read',
            'counselling.records.write',
            'counselling.schedule.read',
        ];

        $this->assertTrue($this->svc->grants($counsellor, 'counselling.records.read'));
        $this->assertTrue($this->svc->grants($counsellor, 'counselling.records.write'));
        $this->assertTrue($this->svc->grants($counsellor, 'counselling.records.create'));
    }

    public function testEffectiveListWithoutCodeIsDenied(): void
    {
        $clinic = ['clinic.encounters.read', 'clinic.encounters.write'];

        $this->assertFalse($this->svc->grants($clinic, 'counselling.records.read'));
        $this->assertFalse($this->svc->grants($clinic, 'clinic.patients.write'));
    }

    public function testAdminWithCollapsedListIsDeniedSensitiveCode(): void
    {
        // allForUser() collapses any wildcard holder to ['*'] — even a
        // dev admin carrying legacy per-user grants — so excluded codes
        // are denied regardless of those legacy grants.
        $this->assertFalse($this->svc->grants(['*'], 'counselling.records.read'));
    }

    public function testNonExcludedExplicitCodeAllowedWithoutWildcard(): void
    {
        $this->assertTrue($this->svc->grants(['clinic.encounters.read'], 'clinic.encounters.read'));
    }

    public function testCustomWildcardTokenHonoured(): void
    {
        // The wildcard token is configurable; a non-'*' token must behave
        // identically for both the grant and the exclusion paths.
        $this->assertTrue($this->svc->grants(['ALL'], 'clinic.encounters.read', 'ALL'));
        $this->assertFalse($this->svc->grants(['ALL'], 'counselling.records.read', 'ALL'));
    }
}
