<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Source-level architecture guards for the two independently owned queues. */
final class DestinationQueueContractTest extends TestCase
{
    public function testGuidanceQueueIsCounsellingOwnedAndFifo(): void
    {
        $source = $this->read('app/Modules/Counselling/Services/QueueService.php');
        $this->assertStringContainsString('counselling_queue_entries', $source);
        $this->assertStringContainsString('ORDER BY `position` ASC FOR UPDATE', $source);
        $this->assertStringNotContainsString('clinic_queue_entries', $source);
    }

    public function testQueueNumbersAreExplicit(): void
    {
        $guidance = $this->read('app/Modules/Counselling/Services/QueueService.php');
        $clinic = $this->read('app/Modules/Clinic/Services/QueueService.php');
        $this->assertStringContainsString("sprintf('G-%03d'", $guidance);
        $this->assertStringContainsString("sprintf('C-%03d'", $clinic);
    }

    public function testStaffRolesDoNotCrossGrantQueueOwnership(): void
    {
        $config = $this->read('app/Config/AuthGroups.php');
        preg_match("/'clinic_staff'\s*=>\s*\[(.*?)\n\s*\],/s", $config, $clinic);
        preg_match("/'counsellor'\s*=>\s*\[(.*?)\n\s*\],/s", $config, $counsellor);
        $this->assertStringContainsString('clinic.queue.manage', $clinic[1] ?? '');
        $this->assertStringNotContainsString('counselling.queue.', $clinic[1] ?? '');
        $this->assertStringContainsString('counselling.queue.manage', $counsellor[1] ?? '');
        $this->assertStringNotContainsString('clinic.queue.', $counsellor[1] ?? '');
    }

    public function testReferralHandoffIsIdempotentAndDoesNotMutateSourceQueue(): void
    {
        $source = $this->read('app/Modules/Referrals/Services/ReferralService.php');
        $this->assertStringContainsString('queue_handoff_entry_id', $source);
        $this->assertStringContainsString('getForHandoff', $source);
        $this->assertStringContainsString("'source_queue_disposition' => 'unchanged'", $source);
    }

    public function testMigrationBackfillsClinicAndAddsIndependentGuards(): void
    {
        $migration = $this->read('app/Database/Migrations/2026-08-14-000010_DestinationQueues.php');
        $this->assertStringContainsString("'default' => 'clinic'", $migration);
        $this->assertStringContainsString('uq_cqe_active_patient', $migration);
        $this->assertStringContainsString('uq_cqe_active_service_slot', $migration);
        $this->assertStringContainsString("dropTable('counselling_queue_entries'", $migration);
        $this->assertStringNotContainsString("dropTable('clinic_queue_entries'", $migration);
    }

    private function read(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $this->assertIsString($source);
        return $source;
    }
}
