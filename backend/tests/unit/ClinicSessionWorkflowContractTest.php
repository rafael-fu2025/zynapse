<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Architecture guards for the single-workspace Clinic session workflow. */
final class ClinicSessionWorkflowContractTest extends TestCase
{
    public function testExactEncounterAndContextualReferralRoutesExist(): void
    {
        $routes = $this->read('app/Modules/Clinic/Routes.php');
        $referrals = $this->read('app/Modules/Referrals/Services/ReferralService.php');
        $this->assertStringContainsString("get('encounters/(:num)'", $routes);
        $this->assertStringContainsString("post('encounters/(:num)/referrals'", $routes);
        $this->assertStringContainsString('createFromEncounter', $referrals);
        $this->assertStringContainsString("'clinic', 'counselling', 'intake_pass'", $referrals);
        $this->assertStringContainsString("ClinicPolicy())->check('refer', \$record)", $referrals);
    }

    public function testClinicCompletionHasOneSharedCoordinator(): void
    {
        $clinic = $this->read('app/Modules/Clinic/Services/ClinicService.php');
        $queue = $this->read('app/Modules/Clinic/Services/QueueService.php');
        $completion = $this->read('app/Modules/Clinic/Services/EncounterCompletionService.php');
        $this->assertStringContainsString('completion->complete', $clinic);
        $this->assertStringContainsString('completion->complete', $queue);
        $this->assertStringContainsString("'status' => 'done'", $completion);
        $this->assertStringContainsString("'status' => 'completed'", $completion);
        $this->assertStringNotContainsString('closeLinkedEncounter', $queue);
    }

    public function testProgressIsDerivedFromClinicalArtifacts(): void
    {
        $clinic = $this->read('app/Modules/Clinic/Services/ClinicService.php');
        $counselling = $this->read('app/Modules/Counselling/Services/CounsellingService.php');
        $this->assertStringContainsString("'vitals_count'", $clinic);
        $this->assertStringContainsString("'treatment_count'", $clinic);
        $this->assertStringContainsString("'assessment_recorded'", $clinic);
        $this->assertStringContainsString("'note_count'", $counselling);
        $this->assertStringContainsString("'outgoing_referral'", $counselling);
    }

    private function read(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $this->assertIsString($source);
        return $source;
    }
}
