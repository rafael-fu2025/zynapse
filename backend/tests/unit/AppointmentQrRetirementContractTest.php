<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Clinic\Controllers\AppointmentController;
use Modules\Clinic\DTOs\AppointmentDto;
use Modules\Clinic\DTOs\UserDto;
use Modules\Clinic\Services\AppointmentService;
use Modules\Clinic\Services\PatientLookupService;
use PHPUnit\Framework\TestCase;

/** DB-free ratchet for removal, alongside the HTTP booking/cascade regressions. */
final class AppointmentQrRetirementContractTest extends TestCase
{
    public function testRetiredMethodsAndRoutesAreAbsent(): void
    {
        foreach ([AppointmentController::class, AppointmentService::class] as $class) {
            $this->assertFalse(method_exists($class, 'issueQr'));
            $this->assertFalse(method_exists($class, 'verify'));
        }
        $this->assertFalse(method_exists(AppointmentDto::class, 'withQrToken'));
        $this->assertFalse(method_exists(PatientLookupService::class, 'findForCheckin'));
        $routes = $this->read('app/Modules/Clinic/Routes.php');
        $this->assertStringNotContainsString('appointments/verify', $routes);
        $this->assertStringNotContainsString('appointments/(:num)/qr', $routes);
        $this->assertStringContainsString('AppointmentController::schedule', $routes);
        $this->assertStringContainsString('AppointmentController::transition', $routes);
        $this->assertStringContainsString('PortalAppointmentController::create', $routes);
    }

    public function testBookingServicesHaveNoQrOrHmacDependency(): void
    {
        foreach (['AppointmentService', 'PortalAppointmentService'] as $service) {
            $source = $this->read('app/Modules/Clinic/Services/' . $service . '.php');
            foreach (['qr_token', 'newQrToken', 'hashToken', 'withQrToken', 'APPOINTMENT_HMAC_KEY', 'REFERRAL_HMAC_KEY'] as $retired) {
                $this->assertStringNotContainsString($retired, $source);
            }
        }
        $source = $this->read('app/Modules/Clinic/Services/AppointmentService.php');
        $this->assertStringContainsString('function schedule(', $source);
        $this->assertStringContainsString('function bookSelf(', $source);
        $this->assertStringContainsString('ManilaDay::today()', $source);
        $this->assertStringContainsString('openEncounterForAppointment($row', $source);
        $this->assertStringContainsString("'clinic.appointment_scheduled'", $source);
        $this->assertStringContainsString("'appointment.scheduled'", $source);
    }

    public function testAppointmentDtoDoesNotPublishLegacyQrValues(): void
    {
        $data = AppointmentDto::fromRow([
            'id' => 1, 'patient_school_id' => 'TEST', 'provider_user_id' => null,
            'scheduled_at' => '2026-10-08 01:00:00', 'status' => 'scheduled',
            'reason' => 'Booking retained', 'created_at' => '2026-10-01 01:00:00',
            'qr_token' => 'legacy-token', 'qr_token_hash' => 'legacy-hash',
        ])->toArray();
        $this->assertArrayNotHasKey('qr_token', $data);
        $this->assertArrayNotHasKey('qr_token_hash', $data);
        $this->assertSame('Booking retained', $data['reason']);
        $this->assertNull($data['provider_user_id']);
        $this->assertNull($data['encounter_id']);
    }

    public function testRegistryRetiresRfidButKeepsPatientQr(): void
    {
        $data = UserDto::fromRow(['id' => 1, 'qr_code' => 'patient-identity', 'rfid_tag' => 'legacy-tag'])->toArray();
        $this->assertTrue($data['has_qr']);
        $this->assertArrayNotHasKey('has_rfid', $data);
        $this->assertArrayNotHasKey('rfid_tag', $data);
        foreach ([
            'Controllers/PatientController.php', 'Services/PatientService.php',
            'Services/PatientLookupService.php', 'Services/EmployeeSelfService.php',
            'Services/StudentSelfService.php',
        ] as $path) {
            $source = $this->read('app/Modules/Clinic/' . $path);
            $this->assertStringNotContainsString('rfid_tag', $source);
            $this->assertStringContainsString('qr_code', $source);
        }
    }

    public function testRetirementMigrationsOnlyDropTheKnownColumnsAndIndexes(): void
    {
        $appointment = $this->read('app/Database/Migrations/2026-10-08-000010_DropAppointmentQrColumns.php');
        $rfid = $this->read('app/Database/Migrations/2026-10-08-000020_DropRfidIdentityColumns.php');
        $this->assertStringContainsString("dropKey('clinic_appointments', 'qr_token_hash')", $appointment);
        $this->assertStringContainsString("dropColumn('clinic_appointments', 'qr_token_hash')", $appointment);
        $this->assertStringContainsString("dropKey('users', 'uniq_users_rfid_tag')", $rfid);
        $this->assertStringContainsString("dropColumn('users', 'rfid_tag')", $rfid);
        foreach ([$appointment, $rfid] as $source) {
            $this->assertStringNotContainsString('dropTable(', $source);
            $this->assertStringNotContainsString("dropColumn('users', 'qr_code')", $source);
            $this->assertStringContainsString('fieldExists(', $source);
            $this->assertStringContainsString('information_schema.STATISTICS', $source);
        }
    }

    private function read(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $this->assertIsString($source);
        return $source;
    }
}
