<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\TriageAssistant;
use PHPUnit\Framework\TestCase;

final class TriageAssistantTest extends TestCase
{
    private TriageAssistant $triage;

    protected function setUp(): void
    {
        $this->triage = new TriageAssistant();
    }

    public function testUrgentKeyword(): void
    {
        $r = $this->triage->analyze('Patient reports chest pain and difficulty breathing');
        $this->assertSame('urgent', $r['predicted_priority']);
        $this->assertGreaterThanOrEqual(0.90, $r['confidence_score']);
    }

    public function testHighKeyword(): void
    {
        $r = $this->triage->analyze('High fever since morning');
        $this->assertSame('high', $r['predicted_priority']);
    }

    public function testMediumKeyword(): void
    {
        $r = $this->triage->analyze('Mild headache and cough');
        $this->assertSame('medium', $r['predicted_priority']);
    }

    public function testLowFallback(): void
    {
        $r = $this->triage->analyze('Requesting a medical certificate');
        $this->assertSame('low', $r['predicted_priority']);
    }

    public function testUnknownComplaintDefaultsLow(): void
    {
        $r = $this->triage->analyze('xyzzy nonspecific');
        $this->assertSame('low', $r['predicted_priority']);
    }

    public function testVitalsEscalateMediumToHigh(): void
    {
        // "headache" is medium; a 39.0°C temp escalates it one step.
        $r = $this->triage->analyze('headache', ['temp_c' => 39.0]);
        $this->assertSame('high', $r['predicted_priority']);
        $this->assertTrue($r['features_used']['vitals_triggered']);
    }

    public function testExtremeBradycardiaEscalates(): void
    {
        $r = $this->triage->analyze('cough', ['pulse_bpm' => 45]);
        $this->assertSame('high', $r['predicted_priority']);
    }

    public function testSevereAllergyForcesUrgent(): void
    {
        $r = $this->triage->analyze('allergic reaction to something', null, [
            ['allergen' => 'Penicillin', 'severity' => 'severe'],
        ]);
        $this->assertSame('urgent', $r['predicted_priority']);
        $this->assertTrue($r['features_used']['allergy_triggered']);
    }

    public function testMildAllergyDoesNotForceUrgent(): void
    {
        $r = $this->triage->analyze('rash on arm', null, [
            ['allergen' => 'Pollen', 'severity' => 'mild'],
        ]);
        $this->assertNotSame('urgent', $r['predicted_priority']);
    }

    public function testConfidenceCappedAtPoint99(): void
    {
        $r = $this->triage->analyze('chest pain, seizure, stroke', ['temp_c' => 40.0]);
        $this->assertLessThanOrEqual(0.99, $r['confidence_score']);
    }
}
