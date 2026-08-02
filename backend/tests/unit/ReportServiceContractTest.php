<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiException;
use Modules\Reports\Services\ReportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ReportServiceContractTest extends TestCase
{
    public function testInstitutionReportModulesAreExplicitAndStable(): void
    {
        $this->assertSame(
            ['clinic', 'counselling', 'inventory', 'referrals', 'facilities'],
            ReportService::MODULES,
        );
    }

    public function testUnknownExportModuleIsRejectedBeforeDatabaseAccess(): void
    {
        /** @var ReportService $service */
        $service = (new ReflectionClass(ReportService::class))->newInstanceWithoutConstructor();

        $this->expectException(ApiException::class);
        $service->exportStream('finance', ['start' => '2026-08-01', 'end' => '2026-08-01']);
    }
}
