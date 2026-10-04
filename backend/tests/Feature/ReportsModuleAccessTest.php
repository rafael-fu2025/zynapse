<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * ReportsModuleAccessTest — report modules honour unit boundaries via
 * ReportService::MODULE_EXTRA_PERMISSIONS.
 *
 * `reports.read` opens the shared analytics, but each unit's module
 * additionally requires that unit's read code: counselling aggregates
 * the confidential guidance unit (`counselling.records.read`) and
 * facilities belongs to BMG (`facilities.units.read`). Clinic admins
 * are denied both; guidance keeps counselling, BMG keeps facilities;
 * and the saved-report surfaces neither show nor run another unit's
 * configurations.
 */
final class ReportsModuleAccessTest extends FeatureTestCase
{
    private const COUNSELLING_DENIED = 'rbac.permission_denied:counselling.records.read';

    public function testClinicAdminIsDeniedTheCounsellingReportModule(): void
    {
        $admin = $this->login(['clinic_admin']);

        $this->assertErrorCode(
            self::COUNSELLING_DENIED,
            $this->authed($admin['token'], 'get', 'api/v1/reports/counselling'),
        );
        $this->assertErrorCode(
            self::COUNSELLING_DENIED,
            $this->authed($admin['token'], 'get', 'api/v1/reports/export/counselling?start=2026-01-01&end=2026-01-31'),
        );
        $this->assertErrorCode(
            self::COUNSELLING_DENIED,
            $this->authed($admin['token'], 'post', 'api/v1/reports/narratives/counselling', [
                'start' => '2026-01-01',
                'end'   => '2026-01-31',
            ]),
        );
    }

    public function testClinicAdminIsDeniedTheFacilitiesReportModule(): void
    {
        $admin = $this->login(['clinic_admin']);

        $this->assertErrorCode(
            'rbac.permission_denied:facilities.units.read',
            $this->authed($admin['token'], 'get', 'api/v1/reports/facilities'),
        );
        $this->assertErrorCode(
            'rbac.permission_denied:facilities.units.read',
            $this->authed($admin['token'], 'get', 'api/v1/reports/export/facilities?start=2026-01-01&end=2026-01-31'),
        );
    }

    public function testBmgAdminKeepsTheFacilitiesReportModule(): void
    {
        $admin = $this->login(['bmg_admin']);

        $module = $this->authed($admin['token'], 'get', 'api/v1/reports/facilities');
        $module->assertStatus(200);
    }

    public function testClinicAdminSummaryOmitsCounsellingCounts(): void
    {
        $admin = $this->login(['clinic_admin']);
        $result = $this->authed($admin['token'], 'get', 'api/v1/reports/summary');
        $result->assertStatus(200);
        $data = $this->envelope($result)['data'];

        $this->assertArrayNotHasKey('counselling', $data, 'A clinic admin must not receive counselling overview counts.');
        $this->assertArrayNotHasKey('facilities', $data, 'A clinic admin must not receive facilities overview counts.');
        $this->assertArrayHasKey('clinic', $data, 'The ungated modules must stay in the summary.');
        $this->assertArrayHasKey('inventory', $data, 'The ungated modules must stay in the summary.');
        $this->assertArrayHasKey('referrals', $data, 'The ungated modules must stay in the summary.');
    }

    public function testGuidanceAdminKeepsTheCounsellingReportModule(): void
    {
        $admin = $this->login(['guidance_admin']);

        $module = $this->authed($admin['token'], 'get', 'api/v1/reports/counselling');
        $module->assertStatus(200);

        $summary = $this->authed($admin['token'], 'get', 'api/v1/reports/summary');
        $summary->assertStatus(200);
        $this->assertArrayHasKey('counselling', $this->envelope($summary)['data']);
    }

    public function testClinicAdminCannotCreateOrSeeCounsellingConfigurations(): void
    {
        $clinic = $this->login(['clinic_admin']);

        $this->assertErrorCode(
            self::COUNSELLING_DENIED,
            $this->authed($clinic['token'], 'post', 'api/v1/reports/configs', [
                'name'       => 'Clinic counselling probe',
                'module'     => 'counselling',
                'parameters' => ['start' => '2026-01-01', 'end' => '2026-01-31', 'summarize' => false],
            ]),
        );

        // A superadmin's counselling configuration must be invisible to
        // the clinic admin's configuration listing.
        $owner = $this->login(['superadmin']);
        $marker = 'Counselling monthly ' . bin2hex(random_bytes(4));
        $create = $this->authed($owner['token'], 'post', 'api/v1/reports/configs', [
            'name'       => $marker,
            'module'     => 'counselling',
            'parameters' => ['start' => '2026-01-01', 'end' => '2026-01-31', 'summarize' => false],
        ]);
        $create->assertStatus(201);

        $list = $this->authed($clinic['token'], 'get', 'api/v1/reports/configs?limit=50');
        $list->assertStatus(200);
        $items = $this->envelope($list)['data']['items'];
        $this->assertIsArray($items);
        foreach ($items as $item) {
            $this->assertNotSame('counselling', $item['module']);
        }

        // ...and the wildcard holder still sees it, so the filter is the
        // permission gate and not a listing regression.
        $ownList = $this->authed($owner['token'], 'get', 'api/v1/reports/configs?limit=50');
        $ownList->assertStatus(200);
        $names = array_column($this->envelope($ownList)['data']['items'], 'name');
        $this->assertContains($marker, $names);
    }
}
