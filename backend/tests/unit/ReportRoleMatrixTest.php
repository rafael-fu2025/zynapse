<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Pin the product-level distinction between viewing/exporting and authoring. */
final class ReportRoleMatrixTest extends TestCase
{
    /** @return array<string, array<int, string>> */
    private function groupPermissions(): array
    {
        require_once __DIR__ . '/../../app/Config/AuthGroups.php';
        $defaults = (new \ReflectionClass(\Config\AuthGroups::class))->getDefaultProperties();

        /** @var array<string, array<int, string>> $permissions */
        $permissions = $defaults['groupPermissions'] ?? [];
        return $permissions;
    }

    public function testReportViewerCanReadAndExportButCannotConfigure(): void
    {
        $permissions = $this->groupPermissions()['report_viewer'] ?? [];

        $this->assertContains('reports.read', $permissions);
        $this->assertContains('reports.export', $permissions);
        $this->assertNotContains('reports.configure', $permissions);
    }

    public function testOperationalRolesDoNotGainReportAuthoring(): void
    {
        $groups = $this->groupPermissions();

        foreach (['clinic_staff', 'counsellor', 'facilities_op', 'audit_reader'] as $group) {
            $this->assertNotContains('reports.configure', $groups[$group] ?? [], $group . ' must not author reports');
        }
    }
}
