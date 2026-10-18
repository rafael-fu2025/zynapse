<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Shared\ManilaDay;
use DateTimeImmutable;
use DateTimeZone;

/** Booking and manual attendance work with both retired HMAC keys absent. */
final class AppointmentQrRetirementTest extends FeatureTestCase
{
    use \Tests\Feature\Support\SchedulingStaffFixture;
    private array $retiredEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APPOINTMENT_HMAC_KEY', 'REFERRAL_HMAC_KEY'] as $key) {
            $this->retiredEnvironment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->retiredEnvironment as $key => [$value, $env, $server]) {
            putenv($value === false ? $key : $key . '=' . $value);
            if ($env !== null) $_ENV[$key] = $env;
            if ($server !== null) $_SERVER[$key] = $server;
        }
        parent::tearDown();
    }

    private function patient(): array
    {
        $patient = $this->login(['student']);
        $patient['schoolId'] = 'QR-RET-' . bin2hex(random_bytes(5));
        db_connect()->table('users')->where('id', $patient['userId'])->update([
            'kind' => 'student', 'student_number' => $patient['schoolId'],
            'first_name' => 'Regression', 'last_name' => 'Patient',
        ]);
        return $patient;
    }

    private function postData(string $token, string $path, array $payload, int $status = 200): array
    {
        $result = $this->authed($token, 'post', $path, $payload);
        $result->assertStatus($status);
        return $this->envelope($result)['data'];
    }

    private function assertNoQr(array $data): void
    {
        $this->assertArrayNotHasKey('qr_token', $data);
        $this->assertArrayNotHasKey('qr_token_hash', $data);
        $this->assertFalse(getenv('APPOINTMENT_HMAC_KEY'));
        $this->assertFalse(getenv('REFERRAL_HMAC_KEY'));
    }

    private function assertBookingNotifications(int $id, int $patientId, ?int $providerId): void
    {
        $rows = db_connect()->table('notification_outbox')
            ->like('context_json', 'appointment#' . $id . '"')
            ->get()->getResultArray();
        $scheduled = array_values(array_filter($rows, static fn (array $row): bool => $row['template_code'] === 'appointment.scheduled'));
        $this->assertCount(1, $scheduled);
        $this->assertSame($patientId, (int) $scheduled[0]['recipient_user_id']);
        $context = json_decode($scheduled[0]['context_json'], true);
        $this->assertSame('clinic', $context['destination']);
        $this->assertSame('scheduled', $context['appointment_status']);
        $assigned = array_values(array_filter($rows, static fn (array $row): bool => $row['template_code'] === 'appointment.assigned'));
        $this->assertCount($providerId === null ? 0 : 1, $assigned);
        if ($providerId !== null) $this->assertSame($providerId, (int) $assigned[0]['recipient_user_id']);
        foreach ($rows as $row) $this->assertGreaterThan(0, (int) $row['recipient_user_id']);
    }

    public function testStaffScheduleAndManualCheckinCascadeWithoutQr(): void
    {
        $staff = $this->login(['clinic_admin']);
        $patient = $this->patient();
        // A stale scheduled date must still queue under TODAY in Manila.
        $slot = gmdate('Y-m-d H:i:s', strtotime('-40 days'));
        $booking = $this->postData($staff['token'], 'api/v1/clinic/appointments', [
            'patient_school_id' => $patient['schoolId'], 'provider_user_id' => $staff['userId'],
            'scheduled_at' => $slot, 'reason' => 'Manual attendance regression',
        ], 201);
        $this->assertNoQr($booking);
        $this->assertSame('scheduled', $booking['status']);
        $this->assertSame($slot, $booking['scheduled_at']);
        $this->assertSame('Manual attendance regression', $booking['reason']);
        $this->assertNull($booking['encounter_id']);
        $id = (int) $booking['id'];
        $this->assertBookingNotifications($id, $patient['userId'], $staff['userId']);
        $path = 'api/v1/clinic/appointments/' . $id . '/transition';
        $confirmed = $this->postData($staff['token'], $path, ['status' => 'confirmed']);
        $this->assertSame('confirmed', $confirmed['status']);
        $checked = $this->postData($staff['token'], $path, ['status' => 'checked_in']);
        $this->assertNoQr($checked);
        $encounterId = (int) $checked['encounter_id'];
        $this->assertGreaterThan(0, $encounterId);
        $db = db_connect();
        $encounter = $db->table('clinic_encounters')->where('appointment_id', $id)->get()->getRowArray();
        $this->assertSame('open', $encounter['status']);
        $this->assertSame($staff['userId'], (int) $encounter['attending_user_id']);
        $this->assertSame($patient['schoolId'], $encounter['patient_school_id']);
        $queue = $db->table('clinic_queue_entries')->where('encounter_id', $encounterId)->get()->getRowArray();
        $this->assertSame(ManilaDay::today(), $queue['queue_date']);
        $this->assertSame('waiting', $queue['status']);
        $this->assertSame(1, (int) $queue['tenant_id']);
        $this->assertGreaterThan(0, (int) $queue['position']);
        // A repeat transition is rejected, never a second encounter/queue row.
        $this->authed($staff['token'], 'post', $path, ['status' => 'checked_in'])->assertStatus(409);
        $this->assertSame(1, $db->table('clinic_encounters')->where('appointment_id', $id)->countAllResults());
        $this->assertSame(1, $db->table('clinic_queue_entries')->where('encounter_id', $encounterId)->countAllResults());
        $this->postData($staff['token'], 'api/v1/clinic/encounters/' . $encounterId . '/assessment', ['diagnosis' => 'Regression diagnosis']);
        $this->postData($staff['token'], 'api/v1/clinic/encounters/' . $encounterId . '/close', []);
        $this->assertSame('completed', $db->table('clinic_appointments')->where('id', $id)->get()->getRowArray()['status']);
        $this->assertSame('done', $db->table('clinic_queue_entries')->where('encounter_id', $encounterId)->get()->getRowArray()['status']);
        $this->assertSame(1, $db->table('audit_outbox')->where('entity_type', 'clinic_appointments')->where('entity_id', $id)->where('action_code', 'clinic.appointment_scheduled')->countAllResults());
    }

    public function testStudentBookSelfRouteWithoutQr(): void
    {
        $staff = $this->login(['clinic_admin']);
        $patient = $this->patient();
        $this->enrollSchedulingFixture($staff['userId'], 'clinic');
        $local = new DateTimeImmutable('+60 days 09:00', new DateTimeZone('Asia/Manila'));
        $slot = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        db_connect()->table('clinic_staff_schedules')->insert([
            'tenant_id' => 1, 'user_id' => $staff['userId'], 'schedule_type' => 'regular',
            'day_of_week' => (int) $local->format('w'), 'shift_start' => '09:00', 'shift_end' => '10:00',
            'effective_from' => $local->format('Y-m-d'), 'effective_to' => $local->format('Y-m-d'), 'is_active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $booking = $this->postData($patient['token'], 'api/v1/me/student-appointments', [
            'provider_user_id' => $staff['userId'], 'scheduled_at' => $slot, 'reason' => 'Self booking',
        ], 201);
        $this->assertNoQr($booking);
        $this->assertSame('scheduled', $booking['status']);
        $this->assertNull($booking['provider_user_id']);
        $this->assertSame($patient['schoolId'], $booking['patient_school_id']);
        $this->assertSame('Self booking', $booking['reason']);
        $this->assertBookingNotifications((int) $booking['id'], $patient['userId'], null);
        $this->authed($patient['token'], 'post', 'api/v1/me/student-appointments', [
            'provider_user_id' => $staff['userId'], 'scheduled_at' => $slot,
        ])->assertStatus(409);
    }

    public function testPortalBookSelfWithoutQrAndManualProviderAssignment(): void
    {
        $staff = $this->login(['clinic_admin']);
        $this->enrollSchedulingFixture($staff['userId'], 'clinic');
        $patient = $this->patient();
        $day = (new DateTimeImmutable('+75 days', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        db_connect()->table('clinic_staff_schedules')->insert([
            'tenant_id' => 1, 'user_id' => $staff['userId'], 'schedule_type' => 'regular',
            'day_of_week' => (int) (new DateTimeImmutable($day))->format('w'),
            'shift_start' => '09:00:00', 'shift_end' => '10:00:00',
            'effective_from' => $day, 'effective_to' => $day, 'is_active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $start = (new DateTimeImmutable($day . ' 09:00:00', new DateTimeZone('Asia/Manila')))
            ->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        $booking = $this->postData($patient['token'], 'api/v1/me/appointments', [
            'department' => 'clinic', 'starts_at' => $start, 'reason' => 'Portal booking',
        ], 201);
        $this->assertNoQr($booking);
        $this->assertSame('scheduled', $booking['status']);
        $this->assertSame('clinic', $booking['department']);
        $this->assertSame($start, $booking['starts_at']);
        $this->assertNull($booking['provider_user_id']);
        $this->assertSame('Portal booking', $booking['reason']);
        $id = (int) $booking['id'];
        $this->assertBookingNotifications($id, $patient['userId'], null);
        $confirmed = $this->postData($staff['token'], 'api/v1/clinic/appointments/' . $id . '/transition', ['status' => 'confirmed']);
        $this->assertSame($staff['userId'], $confirmed['provider_user_id']);
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertNoQr($confirmed);
    }

    public function testRegistryIgnoresRetiredRfidAndPreservesPatientQrIdentity(): void
    {
        $staff = $this->login(['clinic_admin']);
        $number = 'RF-RET-' . bin2hex(random_bytes(5));
        $identity = 'patient-qr-' . bin2hex(random_bytes(8));
        $created = $this->postData($staff['token'], 'api/v1/clinic/students', [
            'student_number' => $number, 'first_name' => 'Registry', 'last_name' => 'Regression',
            'qr_code' => $identity, 'rfid_tag' => str_repeat('retired', 50),
        ], 201);
        $this->assertTrue($created['has_qr']);
        $this->assertArrayNotHasKey('has_rfid', $created);
        $this->assertArrayNotHasKey('rfid_tag', $created);
        $id = (int) $created['id'];
        $updated = $this->postData($staff['token'], 'api/v1/clinic/students/' . $id, [
            'first_name' => 'Updated', 'rfid_tag' => 'ignored-handle',
        ]);
        $this->assertSame('Updated', $updated['first_name']);
        $this->assertTrue($updated['has_qr']);
        $this->assertArrayNotHasKey('has_rfid', $updated);
        $row = db_connect()->table('users')->where('id', $id)->get()->getRowArray();
        $this->assertSame($identity, $row['qr_code']);
        // Also works in the non-migrating gate's pre-retirement schema.
        if (array_key_exists('rfid_tag', $row)) $this->assertNull($row['rfid_tag']);
    }
}
