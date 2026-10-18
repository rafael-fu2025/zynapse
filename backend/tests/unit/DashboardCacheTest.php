<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DashboardCacheTest extends TestCase
{
    public function testDashboardResponsesAreNotSharedCached(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root . '/app/Controllers/Api/Dashboard/DashboardController.php');
        $service = file_get_contents($root . '/app/Services/WorkstationDashboardService.php');
        $this->assertStringNotContainsString('Services::cache()', $controller);
        $this->assertStringNotContainsString('Services::cache()', $service);
        $this->assertStringContainsString("'Cache-Control', 'private, no-store'", $controller);
    }
}
