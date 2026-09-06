<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GuidanceSessionWorkflowContractTest extends TestCase
{
    public function testExactSessionRouteAndRecordAuthorizationArePresent(): void
    {
        $routes = $this->read('app/Modules/Counselling/Routes.php');
        $service = $this->read('app/Modules/Counselling/Services/CounsellingService.php');
        $this->assertStringContainsString("get('sessions/(:num)'", $routes);
        $this->assertStringContainsString("policy->check('list', \$row)", $service);
        $this->assertStringContainsString('queue_number', $service);
        $this->assertStringContainsString('incoming_referral_id', $service);
    }

    public function testSessionWorkspaceCompletionUsesTheLinkedQueueTransaction(): void
    {
        $controller = $this->read('app/Modules/Counselling/Controllers/CounsellingController.php');
        $queue = $this->read('app/Modules/Counselling/Services/QueueService.php');
        $this->assertStringContainsString('completeLinkedSession($sessionId)', $controller);
        $this->assertStringContainsString("'status' => 'done'", $queue);
        $this->assertStringContainsString("->where('status', 'confirmed')->update", $queue);
        $this->assertStringContainsString("(string) \$queue['status'] === 'done'", $queue);
    }

    public function testMissingSessionRepairNeverUsesTheManualOpenEndpoint(): void
    {
        $routes = $this->read('app/Modules/Counselling/Routes.php');
        $queue = $this->read('app/Modules/Counselling/Services/QueueService.php');
        $this->assertStringContainsString('repair-session', $routes);
        $this->assertStringContainsString("(string) \$queue['status'] !== 'in_session'", $queue);
        $this->assertStringContainsString('openSessionForQueue($queue', $queue);
    }

    public function testReferralCreateSerializesAndRejectsActiveDuplicates(): void
    {
        $service = $this->read('app/Modules/Referrals/Services/ReferralService.php');
        $this->assertStringContainsString('SELECT `id` FROM `users` WHERE `tenant_id` = ? AND `id` = ? FOR UPDATE', $service);
        $this->assertStringContainsString("->where('status !=', REFERRAL_STATUS_CLOSED)", $service);
        $this->assertStringContainsString('referral.active_duplicate', $service);
        $this->assertStringContainsString("'details' => ['referral' => \$referral]", $service);
    }

    public function testSessionReferralUsesItsDedicatedClinicalContext(): void
    {
        $routes = $this->read('app/Modules/Counselling/Routes.php');
        $service = $this->read('app/Modules/Referrals/Services/ReferralService.php');
        $migration = $this->read('app/Database/Migrations/2026-08-14-000030_ReferralSourceContext.php');
        $this->assertStringContainsString("post('sessions/(:num)/referrals'", $routes);
        $this->assertStringContainsString('createFromSession', $service);
        $this->assertStringContainsString("'counselling', 'clinic', 'referral_letter'", $service);
        $this->assertStringContainsString('source_session_id', $migration);
    }

    private function read(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $this->assertIsString($source);
        return $source;
    }
}
