<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Role catalog invariants for the 2026-09 RBAC rework (D1/D2).
 *
 * Pins the 13-role catalog shape WITHOUT a database: `Config\AuthGroups`
 * is not PSR-4 autoloadable (namespace `Config`), so the class file is
 * required directly and its defaults read via reflection — same pattern
 * as ReadOnlyRoleInvariantTest. PrivilegedRoles is required directly too
 * so the SET_P list can never drift away from the catalog silently.
 */
final class RbacRoleCatalogTest extends TestCase
{
    private const EXPECTED_ROLES = [
        'superadmin',
        'clinic_admin',
        'clinic_staff',
        'kiosk',
        'guidance_admin',
        'guidance_supervisor',
        'counsellor',
        'bmg_admin',
        'facilities_op',
        'audit_reader',
        'report_viewer',
        'student',
        'employee',
    ];

    /**
     * @return array<string, array<int, string>>
     */
    private function groupPermissions(): array
    {
        require_once __DIR__ . '/../../app/Config/AuthGroups.php';
        $defaults = (new \ReflectionClass(\Config\AuthGroups::class))->getDefaultProperties();
        /** @var array<string, array<int, string>> $gp */
        $gp = $defaults['groupPermissions'] ?? [];

        return $gp;
    }

    /**
     * @return array<string, string>
     */
    private function catalogGroups(): array
    {
        require_once __DIR__ . '/../../app/Config/AuthGroups.php';
        $defaults = (new \ReflectionClass(\Config\AuthGroups::class))->getDefaultProperties();
        /** @var array<string, string> $groups */
        $groups = $defaults['groups'] ?? [];

        return $groups;
    }

    public function testCatalogHasExactlyTheThirteenRoles(): void
    {
        $this->assertSame(
            self::EXPECTED_ROLES,
            array_keys($this->catalogGroups()),
            'The 13-role catalog is code-defined — changes must be deliberate.',
        );
    }

    public function testSuperadminIsTheOnlyWildcardHolder(): void
    {
        require_once __DIR__ . '/../../app/Services/Rbac/PrivilegedRoles.php';
        $this->assertSame('superadmin', \App\Services\Rbac\PrivilegedRoles::WILDCARD_GROUP);

        $gp = $this->groupPermissions();
        $this->assertArrayHasKey('superadmin', $gp);
        $this->assertSame(
            [],
            $gp['superadmin'],
            'superadmin holds an empty explicit matrix — every permission flows from the wildcard.',
        );
        $this->assertArrayNotHasKey('admin', $gp, 'The old `admin` group must be fully retired.');
        $this->assertArrayNotHasKey('clinical_supervisor', $gp, 'The old `clinical_supervisor` group must be fully retired.');
    }

    public function testPrivilegedSetIsASubsetOfTheCatalog(): void
    {
        require_once __DIR__ . '/../../app/Services/Rbac/PrivilegedRoles.php';
        foreach (\App\Services\Rbac\PrivilegedRoles::SET_P as $role) {
            $this->assertArrayHasKey($role, $this->catalogGroups(), "SET_P role '{$role}' must exist in the catalog");
        }
    }

    public function testPermissionMovesFromTheRework(): void
    {
        $gp = $this->groupPermissions();

        // kiosk.content.manage: clinic_admin ONLY (moved off clinic_staff).
        foreach ($gp as $group => $codes) {
            $holds = in_array('kiosk.content.manage', $codes, true);
            if ($group === 'clinic_admin') {
                $this->assertTrue($holds, 'clinic_admin must hold kiosk.content.manage');
            } else {
                $this->assertFalse($holds, "'{$group}' must NOT hold kiosk.content.manage (clinic_admin only)");
            }
        }

        // facilities configuration: bmg_admin ONLY (moved off facilities_op).
        foreach ($gp as $group => $codes) {
            $holds = in_array('facilities.units.manage', $codes, true)
                || in_array('facilities.categories.manage', $codes, true);
            if ($group === 'bmg_admin') {
                $this->assertTrue($holds, 'bmg_admin must hold facilities configuration codes');
            } else {
                $this->assertFalse($holds, "'{$group}' must NOT hold facilities configuration codes (bmg_admin only)");
            }
        }
    }

    public function testUnitAdminsHoldRbacManageAndPrivilegedCodesAreUnassigned(): void
    {
        $gp = $this->groupPermissions();

        foreach (['clinic_admin', 'guidance_admin', 'bmg_admin'] as $admin) {
            $this->assertContains('rbac.manage', $gp[$admin], "{$admin} must provision non-privileged users");
            $this->assertContains('rbac.read', $gp[$admin], "{$admin} must read the role catalog");
            $this->assertNotContains('rbac.privileged.manage', $gp[$admin], 'rbac.privileged.manage is superadmin-only');
            $this->assertNotContains('api_apps.manage', $gp[$admin], 'api_apps.* is superadmin-only');
            $this->assertNotContains('api_apps.read', $gp[$admin], 'api_apps.* is superadmin-only');
        }

        // The three platform codes are held by NO group explicitly —
        // superadmin satisfies them via the wildcard.
        foreach (['rbac.privileged.manage', 'api_apps.manage', 'api_apps.read'] as $code) {
            foreach ($gp as $group => $codes) {
                $this->assertNotContains(
                    $code,
                    $codes,
                    "'{$code}' must stay wildcard-only (superadmin), not mapped to '{$group}'",
                );
            }
        }
    }
}
