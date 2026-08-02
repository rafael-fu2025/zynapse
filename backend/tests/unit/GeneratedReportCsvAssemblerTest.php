<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Reports\Services\GeneratedReportCsvAssembler;
use PHPUnit\Framework\TestCase;

final class GeneratedReportCsvAssemblerTest extends TestCase
{
    public function testArtifactContainsProvenanceHeaderAndStreamedRows(): void
    {
        $partPath = tempnam(sys_get_temp_dir(), 'synapse-report-part-');
        $outputPath = tempnam(sys_get_temp_dir(), 'synapse-report-output-');
        self::assertIsString($partPath);
        self::assertIsString($outputPath);

        try {
            $part = fopen($partPath, 'wb');
            self::assertIsResource($part);
            fputcsv($part, ['2026-08-01', 'open', 3], ',', '"', '');
            fputcsv($part, ['2026-08-02', 'closed', 2], ',', '"', '');
            fclose($part);

            GeneratedReportCsvAssembler::assemble(
                $outputPath,
                $partPath,
                ['Date', 'Status', 'Encounter count'],
                [
                    'Module' => 'clinic',
                    'Range start' => '2026-08-01',
                    'Range end' => '2026-08-02',
                    'Calendar timezone' => 'Asia/Manila',
                    'Aggregate row count' => 2,
                ],
            );

            $rows = [];
            $output = fopen($outputPath, 'rb');
            self::assertIsResource($output);
            while (($row = fgetcsv($output, null, ',', '"', '')) !== false) {
                $rows[] = $row;
            }
            fclose($output);

            self::assertSame(['SYNAPSE generated report', ''], $rows[0]);
            self::assertSame(['Module', 'clinic'], $rows[1]);
            self::assertSame(['Calendar timezone', 'Asia/Manila'], $rows[4]);
            self::assertSame(['Aggregate row count', '2'], $rows[5]);
            self::assertSame(['Date', 'Status', 'Encounter count'], $rows[7]);
            self::assertSame(['2026-08-01', 'open', '3'], $rows[8]);
            self::assertSame(['2026-08-02', 'closed', '2'], $rows[9]);
            self::assertFileDoesNotExist($partPath);
        } finally {
            @unlink($partPath);
            @unlink($outputPath);
        }
    }
}
