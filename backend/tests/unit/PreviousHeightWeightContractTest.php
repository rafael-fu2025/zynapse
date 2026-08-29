<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Privacy and visit-boundary guard for reusable anthropometric values. */
final class PreviousHeightWeightContractTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Modules/Clinic/Services/ClinicService.php',
        );
        $this->assertIsString($source);
        $this->source = $source;
    }

    public function testOnlyHeightAndWeightAreSelectedForReuse(): void
    {
        $this->assertStringContainsString(
            "select('v.encounter_id AS source_encounter_id, v.weight_kg, v.height_cm, v.recorded_at')",
            $this->source,
        );
    }

    public function testCurrentEncounterIsExcludedFromPreviousVisitLookup(): void
    {
        $this->assertStringContainsString("->where('e.id !=', \$encounterId)", $this->source);
    }
}
