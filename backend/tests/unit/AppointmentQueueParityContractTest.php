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
    public function testDuePolicyIsManualForClinicAndManilaAwareEverywhere(): void
    {
        // 2026-09-25 staff meeting: clinic attendance is staff-actioned —
        // the T-15 auto-check-in and the no-show aging sweep are GONE.
        // The clinic service must not carry either sweep any more.
        $clinic=$this->read('app/Modules/Clinic/Services/AppointmentService.php');
        $this->assertStringNotContainsString('autoCheckInTodaysPending',$clinic);
        $this->assertStringNotContainsString('agePastDueNoShows',$clinic);
        $this->assertStringContainsString('Asia/Manila',$clinic);
        // Guidance still enqueues due appointments at T-15 (queue rows,
        // not status transitions) on the Manila clock.
        $guidance=$this->read('app/Modules/Counselling/Services/QueueService.php');
        $this->assertStringContainsString("modify('+15 minutes')",$guidance);
        $this->assertStringContainsString('Asia/Manila',$guidance);
    }
    public function testGuidanceMigrationAndServiceUseAssignedParallelLanes(): void
    {
        $migration=$this->read('app/Database/Migrations/2026-08-21-000010_GuidanceAssignedLanes.php');$queue=$this->read('app/Modules/Counselling/Services/QueueService.php');
        $this->assertStringContainsString('assigned_counsellor_user_id',$migration);$this->assertStringContainsString('uq_cqe_active_service_counsellor',$migration);
        $this->assertStringContainsString('assigned_counsellor_user_id',$queue);
    }
    public function testBothGuidanceBookingPathsEnforceTheThreeStrikeLimit(): void
    {
        // Staff booking enforced the limit but student self-booking did not,
        // so a student at three strikes could book themselves back in.
        $schedule=$this->read('app/Modules/Counselling/Services/ScheduleService.php');
        $portal=$this->read('app/Modules/Clinic/Services/PortalAppointmentService.php');
        $this->assertStringContainsString('public const NO_SHOW_STRIKE_LIMIT',$schedule);
        // Same constant, same error code on both paths — they cannot drift.
        $this->assertStringContainsString('GuidanceScheduleService::NO_SHOW_STRIKE_LIMIT',$portal);
        $this->assertStringContainsString('counselling.schedule.no_show_limit',$schedule);
        $this->assertStringContainsString('counselling.schedule.no_show_limit',$portal);
        // The gate must run inside bookGuidance, before the slot is written.
        $body=substr($portal,(int)strpos($portal,'function bookGuidance'));
        $gate=strpos($body,'assertUnderNoShowLimit');
        $insert=strpos($body,"insert(['tenant_id'");
        $this->assertNotFalse($gate);
        $this->assertNotFalse($insert);
        $this->assertLessThan($insert,$gate);
        // Clinic bookings are not subject to the Guidance counter.
        $clinicBranch=substr($portal,(int)strpos($portal,"if (\$department === 'clinic')"),400);
        $this->assertStringNotContainsString('assertUnderNoShowLimit',$clinicBranch);
    }
    public function testStaffRoleDoesNotGrantUserAdministration(): void
    {
        $groups=$this->read('app/Config/AuthGroups.php');preg_match("/'clinic_staff'\s*=>\s*\[(.*?)\n\s*\],/s",$groups,$clinic);
        preg_match("/'clinic_admin'\s*=>\s*\[(.*?)\n\s*\],/s",$groups,$admin);
        // 2026-09 RBAC rework: clinic_staff holds no user-administration code.
        $this->assertStringNotContainsString('rbac.manage',$clinic[1]??'');
        $this->assertStringContainsString('rbac.manage',$admin[1]??'');
    }
    private function read(string $path):string{$source=file_get_contents(__DIR__.'/../../'.$path);$this->assertIsString($source);return$source;}
}
