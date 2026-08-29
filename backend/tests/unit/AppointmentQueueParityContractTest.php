<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AppointmentQueueParityContractTest extends TestCase
{
    public function testNormalizedSelfServiceRoutesAndPermissionsExist(): void
    {
        $routes=$this->read('app/Modules/Clinic/Routes.php');$groups=$this->read('app/Config/AuthGroups.php');
        foreach(['appointments','appointment-slots','queues'] as $path)$this->assertStringContainsString("'{$path}'",$routes);
        foreach(['portal.appointments.read','portal.appointments.manage','portal.queue.read'] as $permission){$this->assertGreaterThanOrEqual(2,substr_count($groups,$permission));}
    }
    public function testDuePolicyUsesManilaAndFifteenMinutes(): void
    {
        $clinic=$this->read('app/Modules/Clinic/Services/AppointmentService.php');$guidance=$this->read('app/Modules/Counselling/Services/QueueService.php');
        $this->assertStringContainsString("modify('+15 minutes')",$clinic);$this->assertStringContainsString("modify('+15 minutes')",$guidance);
        $this->assertStringContainsString('Asia/Manila',$clinic);$this->assertStringContainsString('Asia/Manila',$guidance);
    }
    public function testGuidanceMigrationAndServiceUseAssignedParallelLanes(): void
    {
        $migration=$this->read('app/Database/Migrations/2026-08-21-000010_GuidanceAssignedLanes.php');$queue=$this->read('app/Modules/Counselling/Services/QueueService.php');
        $this->assertStringContainsString('assigned_counsellor_user_id',$migration);$this->assertStringContainsString('uq_cqe_active_service_counsellor',$migration);
        $this->assertStringContainsString("'active' => \$active",$queue);$this->assertStringContainsString("'now_serving' => \$active[0] ?? null",$queue);
    }
    public function testKioskContentPermissionDoesNotGrantUserAdministration(): void
    {
        $groups=$this->read('app/Config/AuthGroups.php');preg_match("/'clinic_staff'\s*=>\s*\[(.*?)\n\s*\],/s",$groups,$clinic);
        $this->assertStringContainsString('kiosk.content.manage',$clinic[1]??'');$this->assertStringNotContainsString('rbac.manage',$clinic[1]??'');
    }
    private function read(string $path):string{$source=file_get_contents(__DIR__.'/../../'.$path);$this->assertIsString($source);return$source;}
}
