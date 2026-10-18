<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CurrentTenant;
use App\Services\SchedulingStaffService;


final class SchedulingStaffTest extends FeatureTestCase
{
    private array $admin;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->login(['superadmin']);
        $this->tag = 'Scheduling-' . bin2hex(random_bytes(5));
    }

    /** A directory-only identity, deliberately without auth identities/groups or active login. */
    private function employee(string $department = 'Guidance', array $changes = []): int
    {
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('users')->insert([...[
            'tenant_id' => 1, 'username' => 'directory-' . bin2hex(random_bytes(6)),
            'kind' => 'employee', 'employee_number' => 'E-' . bin2hex(random_bytes(6)),
            'first_name' => $this->tag, 'last_name' => 'Directory', 'department' => $department,
            'position' => 'Staff', 'employment_status' => 'active', 'active' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ], ...$changes]);
        return (int) db_connect()->insertID();
    }

    private function postData(string $path, array $body, int $status = 200, ?string $token = null): array
    {
        $res = $this->authed($token ?? $this->admin['token'], 'post', $path, $body);
        $res->assertStatus($status);
        return $this->envelope($res)['data'] ?? [];
    }

    private function enroll(int $id, string $unit = 'guidance'): array
    {
        return $this->postData("api/v1/scheduling/staff/{$unit}/enroll", ['user_ids' => [$id]]);
    }

    private function discover(string $unit = 'guidance', string $query = ''): array
    {
        $res = $this->authed($this->admin['token'], 'get', "api/v1/scheduling/staff/{$unit}?q=" . urlencode($this->tag) . $query);
        $res->assertStatus(200);
        return $this->envelope($res)['data'];
    }

    private function assignment(int $id, string $unit = 'guidance'): ?array
    {
        return db_connect()->table('scheduling_staff_assignments')->where('tenant_id', 1)->where('unit', $unit)->where('user_id', $id)->get()->getRowArray();
    }

    private function window(int $id, int $day = 1, array $changes = [], string $unit = 'guidance'): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = $unit === 'clinic'
            ? ['user_id' => $id, 'shift_start' => '09:00:00', 'shift_end' => '10:00:00', 'schedule_type' => 'regular']
            : ['counsellor_user_id' => $id, 'start_time' => '09:00:00', 'end_time' => '10:00:00'];
        db_connect()->table($unit === 'clinic' ? 'clinic_staff_schedules' : 'counselling_availability')->insert([
            ...$row, 'tenant_id' => 1, 'day_of_week' => $day, 'is_active' => 1,
            'created_at' => $now, 'updated_at' => $now, ...$changes,
        ]);
        return (int) db_connect()->insertID();
    }

    public function testDiscoveryPaginatesMatchingDirectoryAndReviewRowsBeforeLimit(): void
    {
        $expected = [];
        for ($n = 0; $n < 5; ++$n) {
            $this->employee('Unrelated department');
            $expected[] = $this->employee();
        }
        $this->enroll($expected[2]);
        db_connect()->table('users')->where('id', $expected[2])->update(['department' => 'Transferred']);
        $ids = [];
        $cursor = '';
        do {
            $page = $this->discover('guidance', '&limit=2' . ($cursor ? '&cursor=' . urlencode($cursor) : ''));
            $this->assertLessThanOrEqual(2, count($page['items']));
            $ids = [...$ids, ...array_column($page['items'], 'user_id')];
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        $this->assertSame($expected, $ids);
        $items = $this->discover()['items'];
        $this->assertSame('not_enrolled', $items[0]['status']);
        $this->assertTrue($items[0]['can_enroll']);
        $this->assertSame('department_mismatch', $items[2]['status']);
        $this->assertFalse($items[2]['eligible']);
        $this->assertFalse($items[2]['can_enroll']);
        $this->assertSame(['guidance'], $page['department_keys']);
        $this->authed($this->admin['token'], 'get', 'api/v1/scheduling/staff/guidance?cursor=invalid')->assertStatus(422);
    }

    public function testEnrollmentIsIdempotentAuditedAndDoesNotProvisionAuthentication(): void
    {
        $id = $this->employee();
        $before = db_connect()->table('users')->where('id', $id)->get()->getRowArray();
        $this->assertSame(['created' => 1, 'skipped' => 0], $this->enroll($id));
        $this->assertSame(['created' => 0, 'skipped' => 1], $this->enroll($id));
        $this->assertSame($before, db_connect()->table('users')->where('id', $id)->get()->getRowArray());
        foreach (['auth_identities', 'auth_groups_users'] as $table) {
            $this->assertSame(0, db_connect()->table($table)->where('user_id', $id)->countAllResults());
        }
        $a = $this->assignment($id);
        $this->assertSame($this->admin['userId'], (int) $a['enrolled_by_user_id']);
        $this->assertSame(1, db_connect()->table('audit_outbox')->where('entity_type', 'scheduling_staff_assignments')
            ->where('entity_id', $a['id'])->where('action_code', 'scheduling.staff_enrolled')->countAllResults());
        $audit = db_connect()->table('audit_outbox')->where('entity_type', 'scheduling_staff_assignments')->where('entity_id', $a['id'])->get()->getRowArray();
        $this->assertSame('guidance:staff#' . $id, json_decode($audit['context_json'], true)['resource_code']);
        $this->assertSame('ready', $this->discover()['items'][0]['status']);
        $res = $this->authed($this->admin['token'], 'get', 'api/v1/counselling/counsellors');
        $res->assertStatus(200);
        $this->assertContains($id, array_column($this->envelope($res)['data'], 'id'));
    }

    public function testExplicitDeactivationAndReactivateRevalidation(): void
    {
        $id = $this->employee();
        $this->enroll($id);
        $a = $this->assignment($id);
        $path = 'api/v1/scheduling/staff/guidance/assignments/' . $a['id'] . '/status';
        $this->postData($path, ['active' => false]);
        $this->assertSame(['created' => 0, 'skipped' => 1], $this->enroll($id));
        $this->assertSame('inactive_enrollment', $this->discover()['items'][0]['status']);
        $this->postData('api/v1/counselling/availability', ['counsellor_user_id' => $id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00'], 422);
        db_connect()->table('users')->where('id', $id)->update(['department' => 'Transferred']);
        $this->postData($path, ['active' => true], 422);
        db_connect()->table('users')->where('id', $id)->update(['department' => 'Guidance']);
        $this->postData($path, ['active' => 'true'], 422);
        $this->postData($path, ['active' => true]);
        $this->assertSame('ready', $this->discover()['items'][0]['status']);
    }

    public function testEnrollmentRejectsIneligibleBatchAndRollsBack(): void
    {
        $valid = $this->employee();
        $invalid = $this->employee('Guidance', ['employment_status' => 'inactive']);
        $this->postData('api/v1/scheduling/staff/guidance/enroll', ['user_ids' => [$valid, $invalid]], 422);
        $this->assertNull($this->assignment($valid));
        foreach ([['kind' => null], ['employee_number' => null], ['archived_at' => gmdate('Y-m-d H:i:s')],
            ['deleted_at' => gmdate('Y-m-d H:i:s')], ['employment_status' => 'on_leave'], ['department' => 'Guidance Office']] as $change) {
            $id = $this->employee('Guidance', $change);
            $this->postData('api/v1/scheduling/staff/guidance/enroll', ['user_ids' => [$id]], 422);
        }
        foreach ([[], [1.5], ['1'], [true]] as $ids) {
            $this->postData('api/v1/scheduling/staff/guidance/enroll', ['user_ids' => $ids], 422);
        }
    }

    public function testOwnSchedulingDoesNotGrantEnrollmentOrOtherProviderVisibility(): void
    {
        $staff = $this->login(['counsellor']);
        $other = $this->employee();
        $this->enroll($other);
        $this->postData('api/v1/scheduling/staff/guidance/enroll', ['user_ids' => [$other]], 403, $staff['token']);
        $this->authed($staff['token'], 'get', 'api/v1/scheduling/staff/guidance')->assertStatus(403);
        foreach (['api/v1/scheduling/staff/guidance/providers', 'api/v1/counselling/counsellors'] as $route) {
            $res = $this->authed($staff['token'], 'get', $route);
            $res->assertStatus(200);
            $this->assertSame([], $this->envelope($res)['data']);
        }
        $this->postData('api/v1/scheduling/staff/clinic/enroll', ['user_ids' => [$other]], 403, $staff['token']);
        $this->postData('api/v1/scheduling/staff/unknown/enroll', ['user_ids' => [$other]], 404);
    }

    public function testTenantIsolationIncludesAssignmentsProvidersAndMutationTargets(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('tenants')->insert(['name' => $this->tag, 'slug' => strtolower($this->tag), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $tenant = (int) db_connect()->insertID();
        $foreign = $this->employee('Guidance', ['tenant_id' => $tenant]);
        db_connect()->table('scheduling_staff_assignments')->insert(['tenant_id' => $tenant, 'unit' => 'guidance', 'user_id' => $foreign, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $assignment = (int) db_connect()->insertID();
        $this->assertSame([], $this->discover()['items']);
        $this->postData('api/v1/scheduling/staff/guidance/enroll', ['user_ids' => [$foreign]], 422);
        $this->postData("api/v1/scheduling/staff/guidance/assignments/{$assignment}/status", ['active' => false], 404);
        $res = $this->authed($this->admin['token'], 'get', 'api/v1/scheduling/staff/guidance/providers');
        $this->assertNotContains($foreign, array_column($this->envelope($res)['data'], 'id'));
        CurrentTenant::set($tenant);
        $this->assertSame([$foreign], (new SchedulingStaffService())->eligibleIds('guidance'));
        CurrentTenant::set(1);
    }

    public function testTemplatePreservesEveryExistingUserDayAndIsAtomic(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $id = $this->employee($unit === 'clinic' ? 'Health Services' : 'Guidance');
            $this->enroll($id, $unit);
            $first = $this->window($id, 1, ['is_active' => 0], $unit);
            $this->window($id, 2, $unit === 'clinic' ? ['schedule_type' => 'leave', 'effective_from' => '2026-10-01', 'effective_to' => '2026-12-31'] : [], $unit);
            $this->window($id, 2, $unit === 'clinic' ? ['shift_start' => '13:00', 'shift_end' => '14:00'] : ['start_time' => '13:00', 'end_time' => '14:00'], $unit);
            $table = $unit === 'clinic' ? 'clinic_staff_schedules' : 'counselling_availability';
            $column = $unit === 'clinic' ? 'user_id' : 'counsellor_user_id';
            $before = db_connect()->table($table)->where($column, $id)->orderBy('id')->get()->getResultArray();
            $input = ['user_ids' => [$id], 'days_of_week' => [3, 2, 1, 3], 'start_time' => '08:00', 'end_time' => '17:00'];
            $this->assertSame(['created' => 1, 'skipped' => 2], $this->postData("api/v1/scheduling/staff/{$unit}/template", $input));
            $after = db_connect()->table($table)->where($column, $id)->orderBy('id')->get()->getResultArray();
            $this->assertSame($before, array_slice($after, 0, 3));
            $this->assertSame(['created' => 0, 'skipped' => 3], $this->postData("api/v1/scheduling/staff/{$unit}/template", $input));
            $this->postData("api/v1/scheduling/staff/{$unit}/template", [...$input, 'start_time' => '25:00'], 422);
            $valid = $this->employee($unit === 'clinic' ? 'Health Services' : 'Guidance');
            $this->enroll($valid, $unit);
            $validAssignment = $this->assignment($valid, $unit);
            $invalid = $this->employee('Unrelated');
            $this->postData("api/v1/scheduling/staff/{$unit}/template", [...$input, 'user_ids' => [$valid, $invalid]], 422);
            $this->assertSame($validAssignment, $this->assignment($valid, $unit));
            $this->assertSame(0, db_connect()->table($table)->where($column, $valid)->countAllResults());
            $this->assertGreaterThan(0, $first);
        }
    }

    public function testTemplatesRequireExplicitEnrollmentAndNeverEnrollImplicitly(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $id = $this->employee($unit === 'clinic' ? 'Health Services' : 'Guidance');
            $this->postData("api/v1/scheduling/staff/{$unit}/template", [
                'user_ids' => [$id], 'days_of_week' => [1], 'start_time' => '09:00', 'end_time' => '10:00',
            ], 422);
            $this->assertNull($this->assignment($id, $unit));
            $table = $unit === 'clinic' ? 'clinic_staff_schedules' : 'counselling_availability';
            $column = $unit === 'clinic' ? 'user_id' : 'counsellor_user_id';
            $this->assertSame(0, db_connect()->table($table)->where($column, $id)->countAllResults());
        }
    }

    public function testTransferBlocksScheduleWritesAndBookingButKeepsHistoryAndReviewCounts(): void
    {
        $id = $this->employee();
        $this->enroll($id);
        $date = (new \DateTimeImmutable('+40 days', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $day = (int) (new \DateTimeImmutable($date))->format('w');
        $window = $this->window($id, $day);
        $patient = $this->login(['student']);
        $number = 'P-' . bin2hex(random_bytes(6));
        db_connect()->table('users')->where('id', $patient['userId'])->update(['kind' => 'student', 'student_number' => $number]);
        $booking = ['patient_school_id' => $number, 'counsellor_user_id' => $id, 'appointment_date' => $date, 'start_time' => '09:00', 'end_time' => '10:00'];
        $appointment = $this->postData('api/v1/counselling/appointments', $booking, 201);
        $this->assertSame(1, $this->discover()['items'][0]['future_appointments']);
        db_connect()->table('users')->where('id', $id)->update(['department' => 'Transferred']);
        $item = $this->discover()['items'][0];
        $this->assertSame('department_mismatch', $item['status']);
        $this->assertSame(1, $item['future_appointments']);
        $this->postData("api/v1/counselling/availability/{$window}/update", ['start_time' => '08:00'], 422);
        $this->postData('api/v1/counselling/appointments', [...$booking, 'start_time' => '08:00', 'end_time' => '09:00'], 422);
        $this->postData('api/v1/counselling/appointments/' . $appointment['id'] . '/transition', ['action' => 'confirm'], 422);
        $this->assertSame(1, db_connect()->table('counselling_availability')->where('id', $window)->countAllResults());
        $this->assertSame('scheduled', db_connect()->table('counselling_appointments')->where('id', $appointment['id'])->get()->getRowArray()['status']);
        $this->postData('api/v1/counselling/appointments/' . $appointment['id'] . '/transition', ['action' => 'cancel']);
    }

    public function testPortalGuidanceCapacityRequiresEligibleEnrollmentAtCommit(): void
    {
        $id = $this->employee();
        $local = new \DateTimeImmutable('+61 days', new \DateTimeZone('Asia/Manila'));
        while (db_connect()->table('counselling_appointments')->where('tenant_id', 1)->where('appointment_date', $local->format('Y-m-d'))->whereIn('status', ['scheduled', 'confirmed'])->countAllResults() > 0) {
            $local = $local->modify('+1 day');
        }
        $this->assertLessThanOrEqual(89, (int) (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->diff($local)->format('%a'));
        $date = $local->format('Y-m-d');
        $day = (int) (new \DateTimeImmutable($date))->format('w');
        // Isolate capacity from other tests; restore the active flags afterwards.
        $rows = db_connect()->table('counselling_availability')->where('tenant_id', 1)->where('is_active', 1)->get()->getResultArray();
        db_connect()->table('counselling_availability')->where('tenant_id', 1)->update(['is_active' => 0]);
        try {
            $this->window($id, $day, ['start_time' => '21:00', 'end_time' => '22:00']);
            $patient = $this->login(['student']);
            db_connect()->table('users')->where('id', $patient['userId'])->update(['kind' => 'student', 'student_number' => 'P-' . bin2hex(random_bytes(5))]);
            $slotsPath = "api/v1/me/appointment-slots?department=counselling&from={$date}&to={$date}";
            $start = (new \DateTimeImmutable($date . ' 21:00', new \DateTimeZone('Asia/Manila')))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
            $body = ['department' => 'counselling', 'starts_at' => $start];
            $this->postData('api/v1/me/appointments', $body, 409, $patient['token']);
            $this->enroll($id);
            $booked = $this->postData('api/v1/me/appointments', $body, 201, $patient['token']);
            $this->assertNull($booked['provider_user_id']);
            db_connect()->table('users')->where('id', $id)->update(['employment_status' => 'inactive']);
            $res = $this->authed($patient['token'], 'get', $slotsPath);
            $res->assertStatus(200);
            $this->assertSame([], $this->envelope($res)['data']['slots']);
            $this->postData('api/v1/me/appointments', $body, 409, $patient['token']);
            $this->assertSame(1, db_connect()->table('counselling_appointments')->where('id', $booked['id'])->countAllResults());
        } finally {
            if (isset($booked)) db_connect()->table('counselling_appointments')->where('id', $booked['id'])->update(['status' => 'cancelled']);
            db_connect()->table('counselling_availability')->where('tenant_id', 1)->where('counsellor_user_id', $id)->update(['is_active' => 0]);
            if ($rows !== []) db_connect()->table('counselling_availability')->whereIn('id', array_column($rows, 'id'))->update(['is_active' => 1]);
        }
    }

    public function testDiscoveryCapsAt100AndUsesConfiguredExactAliases(): void
    {
        $config = config(\Config\SchedulingStaff::class);
        $before = $config->departments;
        try {
            $config->departments['guidance'][] = 'VERIFIED-GC';
            for ($i = 0; $i < 101; ++$i) $this->employee(' verified-gc ');
            $this->employee('VERIFIED-GC Annex', ['position' => 'Guidance Counsellor']);
            $page = $this->discover('guidance', '&limit=999');
            $this->assertCount(100, $page['items']);
            $this->assertNotNull($page['next_cursor']);
            $next = $this->discover('guidance', '&cursor=' . urlencode($page['next_cursor']));
            $this->assertCount(1, $next['items']);
            $this->assertNull($next['next_cursor']);
        } finally {
            $config->departments = $before;
        }
    }

    public function testClinicRosterCreateUpdateAndRestoreRequireCurrentEnrollment(): void
    {
        $id = $this->employee('Health Services');
        $payload = ['user_id' => $id, 'day_of_week' => 1, 'shift_start' => '09:00', 'shift_end' => '10:00'];
        $this->postData('api/v1/clinic/staff-schedules', $payload, 422);
        $this->enroll($id, 'clinic');
        $created = $this->postData('api/v1/clinic/staff-schedules', $payload, 201);
        $path = 'api/v1/clinic/staff-schedules/' . $created['id'];
        db_connect()->table('users')->where('id', $id)->update(['department' => 'Transferred']);
        $this->postData($path, ['shift_start' => '08:00'], 422);
        $this->postData($path . '/archive', []);
        $this->postData($path . '/unarchive', [], 422);
        $this->assertSame('department_mismatch', $this->discover('clinic')['items'][0]['status']);
        $this->assertSame(1, db_connect()->table('clinic_staff_schedules')->where('id', $created['id'])->countAllResults());
    }

    public function testPortalCommitRevalidatesDirectoryAfterAvailabilityWasRead(): void
    {
        $id = $this->employee();
        $this->enroll($id);
        $local = new \DateTimeImmutable('+63 days 22:00', new \DateTimeZone('Asia/Manila'));
        $this->window($id, (int) $local->format('w'), ['start_time' => '22:00', 'end_time' => '23:00']);
        $patient = $this->employee('Other');
        $portal = new \Modules\Clinic\Services\PortalAppointmentService(
            \Config\Services::appointmentService(), \Config\Services::queueService(), \Config\Services::notificationOutbox(),
        );
        // The public slot read is deliberately separated from the transaction's capacity check.
        $this->assertNotEmpty($portal->slots('counselling', $local->format('Y-m-d'), $local->format('Y-m-d')));
        $eligible = (new SchedulingStaffService())->eligibleIds('guidance');
        $old = db_connect()->table('users')->whereIn('id', $eligible)->get()->getResultArray();
        try {
            db_connect()->table('users')->whereIn('id', $eligible)->update(['archived_at' => gmdate('Y-m-d H:i:s')]);
            $commit = new \ReflectionMethod($portal, 'bookGuidance');
            try {
                $commit->invoke($portal, $patient, $local, 'initial', null);
                $this->fail('Commit must reject stale directory eligibility.');
            } catch (\App\Exceptions\ApiException $e) {
                $this->assertSame('statemachine.schedule.outside_availability', $e->errorCode);
            }
            $this->assertSame(0, db_connect()->table('counselling_appointments')->where('patient_user_id', $patient)->countAllResults());
        } finally {
            foreach ($old as $u) db_connect()->table('users')->where('id', $u['id'])->update(['archived_at' => $u['archived_at']]);
        }
    }

    public function testLegacyStudentBookingUsesEnrolledCapacityAndKeepsDtoAndReadIds(): void
    {
        $provider = $this->employee('Health Services');
        $other = $this->employee('Health Services');
        $this->enroll($other, 'clinic');
        $local = new \DateTimeImmutable('+67 days 20:00', new \DateTimeZone('Asia/Manila'));
        $day = (int) $local->format('w');
        $this->window($provider, $day, ['shift_start' => '20:00', 'shift_end' => '21:00'], 'clinic');
        $this->window($other, $day, ['shift_start' => '20:00', 'shift_end' => '21:00'], 'clinic');
        $patient = $this->login(['student']);
        db_connect()->table('users')->where('id', $patient['userId'])->update(['kind' => 'student', 'student_number' => 'P-' . bin2hex(random_bytes(5))]);
        $body = ['provider_user_id' => $provider, 'scheduled_at' => $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'reason' => 'Legacy booking'];
        // Other eligible capacity exists: an unenrolled or transferred hint
        // must not be accepted simply because somebody else covers the time.
        $this->postData('api/v1/me/student-appointments', $body, 422, $patient['token']);
        $this->enroll($provider, 'clinic');
        db_connect()->table('users')->where('id', $provider)->update(['department' => 'Transferred']);
        $this->postData('api/v1/me/student-appointments', $body, 422, $patient['token']);
        db_connect()->table('users')->where('id', $provider)->update(['department' => 'Health Services']);
        $providers = $this->authed($patient['token'], 'get', 'api/v1/me/student-providers');
        $providers->assertStatus(200);
        $this->assertContains($provider, array_column($this->envelope($providers)['data'], 'id'));
        $booked = $this->postData('api/v1/me/student-appointments', $body, 201, $patient['token']);
        try {
            $this->assertSame($body['scheduled_at'], $booked['scheduled_at']);
            $this->assertSame('scheduled', $booked['status']);
            $this->assertSame('Legacy booking', $booked['reason']);
            $this->assertNull($booked['provider_user_id']);
            foreach (['patient_school_id', 'patient_name', 'provider_name', 'created_at', 'encounter_id'] as $key) $this->assertArrayHasKey($key, $booked);
            $read = $this->authed($patient['token'], 'get', 'api/v1/me/student-appointments');
            $read->assertStatus(200);
            $this->assertContains($booked['id'], array_column($this->envelope($read)['data'], 'id'));
        } finally {
            db_connect()->table('clinic_appointments')->where('id', $booked['id'])->update(['status' => 'cancelled']);
        }
        // Omitting the historical provider hint is also supported.
        unset($body['provider_user_id']);
        $unassigned = $this->postData('api/v1/me/student-appointments', $body, 201, $patient['token']);
        db_connect()->table('clinic_appointments')->where('id', $unassigned['id'])->update(['status' => 'cancelled']);
        $this->assertSame(0, db_connect()->table('auth_groups_users')->where('user_id', $provider)->countAllResults());
        $this->assertSame(0, db_connect()->table('auth_identities')->where('user_id', $provider)->countAllResults());
    }

    public function testQueueReassignmentRequiresEnrollmentNotLoginAndCanReturnToPool(): void
    {
        $provider = $this->employee();
        $patient = $this->employee('Other');
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('counselling_queue_entries')->insert([
            'tenant_id' => 1, 'patient_user_id' => $patient, 'patient_school_id' => 'Q-' . bin2hex(random_bytes(5)),
            'queue_date' => \App\Modules\Shared\ManilaDay::today(), 'position' => random_int(100000, 999999), 'purpose' => 'Scheduling regression',
            'status' => 'waiting', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $queueId = (int) db_connect()->insertID();
        $path = 'api/v1/counselling/queue/' . $queueId . '/reassign';
        $body = ['assigned_counsellor_user_id' => $provider];
        $this->postData($path, $body, 422);
        $this->enroll($provider);
        $before = db_connect()->table('users')->where('id', $provider)->get()->getRowArray();
        $assigned = $this->postData($path, $body);
        $this->assertSame($provider, $assigned['assigned_counsellor_user_id']);
        foreach ([['department' => 'Transferred'], ['employment_status' => 'inactive'], ['archived_at' => $now], ['deleted_at' => $now]] as $change) {
            db_connect()->table('users')->where('id', $provider)->update($change);
            $this->postData($path, $body, 422);
            db_connect()->table('users')->where('id', $provider)->update(array_intersect_key($before, $change));
        }
        $assignment = $this->assignment($provider);
        $this->postData('api/v1/scheduling/staff/guidance/assignments/' . $assignment['id'] . '/status', ['active' => false]);
        $this->postData($path, $body, 422);
        $pooled = $this->postData($path, ['assigned_counsellor_user_id' => null]);
        $this->assertNull($pooled['assigned_counsellor_user_id']);
        $student = $this->login(['student']);
        $this->postData($path, ['assigned_counsellor_user_id' => null], 403, $student['token']);
        $this->assertSame($before, db_connect()->table('users')->where('id', $provider)->get()->getRowArray());
        foreach (['auth_identities', 'auth_groups_users'] as $table) $this->assertSame(0, db_connect()->table($table)->where('user_id', $provider)->countAllResults());
    }

    public function testScheduleMutationsAcquireDirectoryAndEnrollmentBeforeResourceLocks(): void
    {
        $clinic = $this->employee('Health Services');
        $guidance = $this->employee();
        $this->enroll($clinic, 'clinic');
        $this->enroll($guidance);
        $shift = $this->window($clinic, 1, [], 'clinic');
        $slot = $this->window($guidance);
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('counselling_appointments')->insert([
            'tenant_id' => 1, 'patient_school_id' => 'Lock-' . bin2hex(random_bytes(5)), 'counsellor_user_id' => $guidance,
            'appointment_date' => (new \DateTimeImmutable('+73 days'))->format('Y-m-d'), 'start_time' => '09:00', 'end_time' => '10:00',
            'type' => 'initial', 'status' => 'scheduled', 'created_by_user_id' => $this->admin['userId'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $appointment = (int) db_connect()->insertID();
        foreach ([
            ['api/v1/clinic/staff-schedules/' . $shift, ['shift_start' => '08:00'], 'clinic_staff_schedules'],
            ['api/v1/clinic/staff-schedules/' . $shift . '/archive', [], 'clinic_staff_schedules'],
            ['api/v1/clinic/staff-schedules/' . $shift . '/unarchive', [], 'clinic_staff_schedules'],
            ['api/v1/counselling/availability/' . $slot . '/update', ['start_time' => '08:00'], 'counselling_availability'],
            ['api/v1/counselling/appointments/' . $appointment . '/transition', ['action' => 'confirm'], 'counselling_appointments'],
        ] as [$path, $body, $table]) {
            $locks = [];
            $listener = static function ($query) use (&$locks): void {
                $sql = $query->getQuery();
                if (str_contains($sql, 'FOR UPDATE')) $locks[] = $sql;
            };
            \CodeIgniter\Events\Events::on('DBQuery', $listener);
            try {
                $this->postData($path, $body);
            } finally {
                \CodeIgniter\Events\Events::removeListener('DBQuery', $listener);
            }
            $first = static function (string $table) use ($locks): int {
                foreach ($locks as $index => $sql) {
                    if (str_contains($sql, 'FROM `' . $table . '`')) return $index;
                }
                return -1;
            };
            $this->assertGreaterThanOrEqual(0, $first('users'), $path);
            $this->assertGreaterThan($first('users'), $first('scheduling_staff_assignments'), $path);
            $this->assertGreaterThan($first('scheduling_staff_assignments'), $first($table), $path);
        }
    }

    public function testClinicCommitUsesCurrentCapacityDespiteEarlierRepeatableReadSnapshot(): void
    {
        $db = db_connect();
        $other = db_connect('tests', false);
        $provider = $this->employee('Health Services');
        $this->enroll($provider, 'clinic');
        $local = new \DateTimeImmutable('+71 days 20:00', new \DateTimeZone('Asia/Manila'));
        $utc = $local->setTimezone(new \DateTimeZone('UTC'));
        $window = $this->window($provider, (int) $local->format('w'), ['shift_start' => '20:00', 'shift_end' => '21:00'], 'clinic');
        $existing = $db->table('clinic_staff_schedules')->where('tenant_id', 1)->where('is_active', 1)->where('id !=', $window)->get()->getResultArray();
        $db->table('clinic_staff_schedules')->where('tenant_id', 1)->where('id !=', $window)->update(['is_active' => 0]);
        $portal = new \Modules\Clinic\Services\PortalAppointmentService(\Config\Services::appointmentService(), \Config\Services::queueService(), \Config\Services::notificationOutbox());
        $gate = new \ReflectionMethod($portal, 'assertClinicPlace');
        try {
            foreach (['booking', 'schedule', 'leave'] as $change) {
                $inserted = null;
                $db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $db->transBegin();
                try {
                    // This is the early snapshot eligibleIds() creates before
                    // waiting on directory locks. The second connection commits
                    // after it, reproducing the stale-read window deterministically.
                    (new SchedulingStaffService())->eligibleIds('clinic');
                    if ($change === 'booking') {
                        $other->table('clinic_appointments')->insert([
                            'tenant_id' => 1, 'patient_school_id' => 'Capacity-' . bin2hex(random_bytes(5)),
                            'provider_user_id' => null, 'scheduled_at' => $utc->format('Y-m-d H:i:s'),
                            'status' => 'scheduled', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                        $inserted = (int) $other->insertID();
                    } elseif ($change === 'schedule') {
                        $other->table('clinic_staff_schedules')->where('id', $window)->update(['is_active' => 0]);
                    } else {
                        $other->table('clinic_staff_schedules')->insert([
                            'tenant_id' => 1, 'user_id' => $provider, 'day_of_week' => (int) $local->format('w'),
                            'shift_start' => '20:00', 'shift_end' => '21:00', 'schedule_type' => 'leave', 'is_active' => 1,
                            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                        ]);
                        $inserted = (int) $other->insertID();
                    }
                    try {
                        $gate->invoke($portal, $utc);
                        $this->fail('Commit must see the newly committed ' . $change . '.');
                    } catch (\App\Exceptions\ApiException $e) {
                        $this->assertSame('statemachine.schedule.slot_full', $e->errorCode);
                    }
                } finally {
                    $db->transRollback();
                    if ($change === 'booking' && $inserted !== null) $other->table('clinic_appointments')->where('id', $inserted)->delete();
                    if ($change === 'leave' && $inserted !== null) $other->table('clinic_staff_schedules')->where('id', $inserted)->delete();
                    $other->table('clinic_staff_schedules')->where('id', $window)->update(['is_active' => 1]);
                }
            }
        } finally {
            $db->table('clinic_staff_schedules')->where('id', $window)->update(['is_active' => 0]);
            if ($existing !== []) $db->table('clinic_staff_schedules')->whereIn('id', array_column($existing, 'id'))->update(['is_active' => 1]);
            $other->close();
        }
    }

    public function testMigrationBackfillsLegacyWithoutChangingSchedulesOrInferringEmployees(): void
    {
        $legacy = $this->employee('Guidance', ['kind' => null, 'employee_number' => null]);
        $valid = $this->employee('Health Services');
        $this->window($legacy);
        $this->window($valid, 1, [], 'clinic');
        $before = db_connect()->table('counselling_availability')->where('counsellor_user_id', $legacy)->get()->getResultArray();
        require_once APPPATH . 'Database/Migrations/2026-10-18-000010_SchedulingStaffAssignments.php';
        $migration = new \App\Database\Migrations\SchedulingStaffAssignments();
        $migration->up();
        $migration->up();
        $this->assertNotNull($this->assignment($legacy));
        $this->assertNotNull($this->assignment($valid, 'clinic'));
        $this->assertSame($before, db_connect()->table('counselling_availability')->where('counsellor_user_id', $legacy)->get()->getResultArray());
        $items = array_column($this->discover()['items'], null, 'user_id');
        $this->assertSame('needs_review', $items[$legacy]['status']);
        $this->assertFalse($items[$legacy]['eligible']);
        $missing = 4000000000000 + random_int(1, 1000000000);
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('scheduling_staff_assignments')->insert(['tenant_id' => 1, 'unit' => 'guidance', 'user_id' => $missing, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $cursor = base64_encode(json_encode([1, 'guidance', $missing - 1]));
        $res = $this->authed($this->admin['token'], 'get', 'api/v1/scheduling/staff/guidance?cursor=' . urlencode($cursor));
        $res->assertStatus(200);
        $orphan = array_column($this->envelope($res)['data']['items'], null, 'user_id')[$missing];
        $this->assertSame('needs_review', $orphan['status']);
        $this->assertFalse($orphan['eligible']);
        $this->postData('api/v1/counselling/availability', ['counsellor_user_id' => $legacy, 'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00'], 422);
    }
}
