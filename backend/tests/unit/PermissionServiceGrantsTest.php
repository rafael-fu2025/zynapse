<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Rbac\PermissionService;
use PHPUnit\Framework\TestCase;

/**
 * PermissionService::grants() — wildcard-exclusion policy.
 *
 * Exercises the pure grant decision without a database. The counselling
 * records exclusion (RBAC_SECURITY_REVIEW R1) was lifted per product
 * decision, so the admin wildcard now grants every permission code,
 * including counselling.records.*.
 */
final class PermissionServiceGrantsTest extends TestCase
{
    private PermissionService $svc;

    protected function setUp(): void
    {
        $this->svc = new PermissionService();
    }

    public function testWildcardGrantsAllCodesIncludingCounselling(): void
    {
        $this->assertTrue($this->svc->grants(['*'], 'counselling.records.read'));
        $this->assertTrue($this->svc->grants(['*'], 'counselling.records.write'));
        $this->assertTrue($this->svc->grants(['*'], 'counselling.records.create'));
    }

    public function testWildcardGrantsNonSensitiveCodes(): void
    {
        $this->assertTrue($this->svc->grants(['*'], 'clinic.encounters.read'));
        $this->assertTrue($this->svc->grants(['*'], 'facilities.units.manage'));
        $this->assertTrue($this->svc->grants(['*'], 'rbac.manage'));
        $this->assertTrue($this->svc->grants(['*'], 'audit.export'));
    }

    public function testExplicitGrantAllowsCode(): void
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

    public function testAdminWithCollapsedListGrantsCounselling(): void
    {
        // allForUser() collapses any wildcard holder to ['*']; with the
        // R1 exclusion lifted, the wildcard satisfies counselling codes.
        $this->assertTrue($this->svc->grants(['*'], 'counselling.records.read'));
    }

    public function testNonExcludedExplicitCodeAllowedWithoutWildcard(): void
    {
        $this->assertTrue($this->svc->grants(['clinic.encounters.read'], 'clinic.encounters.read'));
    }

    public function testCustomWildcardTokenHonoured(): void
    {
        // The wildcard token is configurable; a non-'*' token behaves
        // identically for the grant path.
        $this->assertTrue($this->svc->grants(['ALL'], 'clinic.encounters.read', 'ALL'));
        $this->assertTrue($this->svc->grants(['ALL'], 'counselling.records.read', 'ALL'));
    }
}
