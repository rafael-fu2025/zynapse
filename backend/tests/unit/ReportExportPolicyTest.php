<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Reports\Services\ReportExportPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportExportPolicyTest extends TestCase
{
    #[DataProvider('dangerousCells')]
    public function testSpreadsheetFormulasAreNeutralized(string $input): void
    {
        $sanitized = ReportExportPolicy::sanitizeCell($input);

        $this->assertIsString($sanitized);
        $this->assertStringStartsWith("'", $sanitized);
    }

    /** @return array<string, array{string}> */
    public static function dangerousCells(): array
    {
        return [
            'formula' => ['=HYPERLINK("https://example.test","Name")'],
            'leading whitespace' => [" \t+SUM(1,1)"],
            'minus command' => ['-2+3'],
            'at expression' => ['@SUM(A1:A2)'],
        ];
    }

    public function testLineBreaksAreFlattenedAndScalarTypesArePreserved(): void
    {
        $this->assertSame(
            ['two lines', 12, 4.5, null, false],
            ReportExportPolicy::sanitizeRow(["two\r\nlines", 12, 4.5, null, false]),
        );
    }

    public function testOrdinaryTextIsUnchanged(): void
    {
        $this->assertSame('Routine care', ReportExportPolicy::sanitizeCell('Routine care'));
    }
}
