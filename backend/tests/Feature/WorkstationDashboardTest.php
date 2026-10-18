<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Shared\ManilaDay;
use App\Services\CurrentTenant;
use App\Services\Rbac\PermissionService;
use App\Services\WorkstationDashboardService;
use DateTimeImmutable;
use DateTimeZone;
use Config\Services;

final class WorkstationDashboardTest extends FeatureTestCase
{
    private int $tenant;
    private array $actors = [];
    private const NOW = '2030-02-01 16:00:00'; // February 2, midnight Manila.

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('tenants')->insert(['name' => 'Dashboard fixtures', 'slug' => 'dash-' . bin2hex(random_bytes(6)),
            'is_active' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        $this->tenant = (int) $this->db->insertID();
        CurrentTenant::set($this->tenant);
    }

    protected function tearDown(): void
    {
        $this->db->table('clinic_queue_entries')->where('tenant_id', $this->tenant)->delete();
        foreach ($this->actors as $id) {
            $this->db->table('auth_groups_users')->where('user_id', $id)->delete();
        }
        parent::tearDown();
    }

    private function actor(array $permissions): array
    {
        $actor = $this->login();
        $this->actors[] = $actor['userId'];
        $this->db->table('users')->where('id', $actor['userId'])->update(['tenant_id' => $this->tenant,
            'first_name' => 'Dashboard', 'last_name' => 'Patient']);
        foreach ($permissions as $permission) {
            $this->db->table('user_permissions')->insert(['user_id' => $actor['userId'], 'permission_code' => $permission, 'created_at' => self::NOW]);
        }
        Services::resetSingle('permissionService');
        CurrentTenant::set($this->tenant);
        return $actor;
    }

    private function data(string $unit, int $actor, string $now = self::NOW): array
    {
        CurrentTenant::set($this->tenant);
        return (new WorkstationDashboardService($this->db, new PermissionService(),
            static fn () => new DateTimeImmutable($now, new DateTimeZone('UTC'))))->dashboard($unit, $actor);
    }

    private function metrics(array $data): array
    {
        return array_column($data['metrics'], 'value', 'key');
    }

    private function encounter(int $actor, array $overrides = []): int
    {
        $this->db->table('clinic_encounters')->insert($overrides + ['tenant_id' => $this->tenant,
            'patient_user_id' => $actor, 'patient_school_id' => 'D-' . bin2hex(random_bytes(5)),
            'chief_complaint' => 'DO NOT EXPOSE COMPLAINT', 'attending_user_id' => $actor,
            'status' => 'open', 'started_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        return (int) $this->db->insertID();
    }

    private function session(int $actor, array $overrides = []): int
    {
        $this->db->table('counselling_sessions')->insert($overrides + ['tenant_id' => $this->tenant,
            'patient_user_id' => $actor, 'patient_school_id' => 'D-' . bin2hex(random_bytes(5)),
            'counsellor_user_id' => $actor, 'started_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        return (int) $this->db->insertID();
    }

    private function unit(array $overrides = []): int
    {
        $this->db->table('facilities_bmg_units')->insert($overrides + ['tenant_id' => $this->tenant,
            'code' => 'D-' . bin2hex(random_bytes(5)), 'display_name' => 'Test drum', 'status' => 'idle',
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        return (int) $this->db->insertID();
    }

    private function batch(int $unit, int $actor, array $overrides = []): int
    {
        $this->db->table('facilities_bmg_batches')->insert($overrides + ['tenant_id' => $this->tenant,
            'unit_id' => $unit, 'reference_code' => 'D-' . bin2hex(random_bytes(5)), 'status' => 'processing',
            'total_input_weight_kg' => 10, 'input_items' => '[]', 'started_by_user_id' => $actor,
            'started_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        return (int) $this->db->insertID();
    }

    private function device(int $unit, int $actor, array $overrides = []): int
    {
        $this->db->table('facilities_bmg_devices')->insert($overrides + ['tenant_id' => $this->tenant,
            'unit_id' => $unit, 'code' => 'D-' . bin2hex(random_bytes(5)), 'display_name' => 'Controller',
            'token_hash' => bin2hex(random_bytes(32)), 'linked_user_id' => $actor, 'last_seen_at' => self::NOW,
            'created_at' => self::NOW, 'updated_at' => self::NOW]);
        return (int) $this->db->insertID();
    }

    public static function workstations(): array
    {
        return [['clinic', 'clinic.encounters.read'], ['guidance', 'counselling.records.read'], ['facilities', 'facilities.units.read']];
    }

    /** @dataProvider workstations */
    public function testRoutesRequireAuthenticationAndBasePermission(string $unit, string $permission): void
    {
        $this->call('get', 'api/v1/dashboard/' . $unit)->assertStatus(401);
        $denied = $this->actor([]);
        $res = $this->authed($denied['token'], 'get', 'api/v1/dashboard/' . $unit);
        $res->assertStatus(403);
        $this->assertNull($this->envelope($res)['data']);
        $allowed = $this->actor([$permission]);
        $res = $this->authed($allowed['token'], 'get', 'api/v1/dashboard/' . $unit);
        $res->assertStatus(200);
        $data = $this->envelope($res)['data'];
        $this->assertSame($unit, $data['unit']);
        $this->assertSame(['unit', 'day', 'generated_at', 'scope', 'metrics', 'trend', 'breakdown', 'recent'], array_keys($data));
        $this->assertStringContainsString('private', $res->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $res->response()->getHeaderLine('Cache-Control'));
    }

    /** @dataProvider workstations */
    public function testEmptyTrendHasSevenZeroFilledManilaDates(string $unit, string $permission): void
    {
        $actor = $this->actor([$permission]);
        $data = $this->data($unit, $actor['userId']);
        $this->assertSame('2030-02-02', $data['day']);
        $this->assertSame('2030-02-01T16:00:00Z', $data['generated_at']);
        $this->assertSame(['2030-01-27', '2030-01-28', '2030-01-29', '2030-01-30', '2030-01-31', '2030-02-01', '2030-02-02'], array_column($data['trend']['points'], 'day'));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], array_column($data['trend']['points'], 'value'));
        $this->assertSame([], $data['recent']['rows']);
        $this->assertSame([], $data['breakdown']['items']);
        foreach ($data['metrics'] as $metric) {
            $this->assertSame(['key', 'label', 'value', 'unit', 'description'], array_keys($metric));
            $this->assertIsNumeric($metric['value']);
        }
    }

    public function testUnauthorizedMetricsAreAbsentNotZero(): void
    {
        $actor = $this->actor(['clinic.encounters.read', 'counselling.records.read', 'facilities.units.read']);
        $this->assertSame(['completed_consultations' => 0], $this->metrics($this->data('clinic', $actor['userId'])));
        $this->assertSame(['open_sessions' => 0, 'completed_sessions' => 0], $this->metrics($this->data('guidance', $actor['userId'])));
        $this->assertSame(['total_units' => 0, 'active_batches' => 0, 'bound_devices' => 0], $this->metrics($this->data('facilities', $actor['userId'])));
    }

    public function testClinicDateBoundsArchivedRecordsAndSafeRecentRows(): void
    {
        $actor = $this->actor(['clinic.encounters.read']);
        $id = $actor['userId'];
        foreach (['2030-01-26 15:59:59', '2030-01-26 16:00:00', '2030-02-01 15:59:59', self::NOW, '2030-02-02 15:59:59', '2030-02-02 16:00:00'] as $time) {
            $this->encounter($id, ['started_at' => $time, 'closed_at' => $time, 'status' => 'closed', 'triage_priority' => 'low']);
        }
        $this->encounter($id, ['archived_at' => self::NOW, 'triage_priority' => 'urgent']);
        $this->encounter($id, ['tenant_id' => 1, 'triage_priority' => 'high']);
        $this->encounter($id, ['status' => 'closed', 'closed_at' => self::NOW, 'outcome' => 'no_show']);
        $this->encounter($id, ['status' => 'closed', 'closed_at' => self::NOW, 'outcome' => 'auto_closed']);
        $data = $this->data('clinic', $id);
        $this->assertSame(2, $this->metrics($data)['completed_consultations']);
        $this->assertSame([1, 0, 0, 0, 0, 1, 4], array_column($data['trend']['points'], 'value'));
        $this->assertSame(['low' => 6], array_column($data['breakdown']['items'], 'value', 'key'));
        $this->assertCount(6, $data['recent']['rows']);
        foreach ($data['recent']['rows'] as $row) {
            $this->assertSame(['id', 'code', 'title', 'subtitle', 'status', 'occurred_at', 'owner'], array_keys($row));
            $this->assertSame('Dashboard Patient', $row['title']);
            $this->assertNull($row['subtitle']);
        }
        $this->assertStringNotContainsString('DO NOT EXPOSE', json_encode($data));
        $before = $this->data('clinic', $id, '2030-02-01 15:59:59');
        $this->assertSame('2030-02-01', $before['day']);
        $this->assertSame(1, $this->metrics($before)['completed_consultations']);
    }

    public function testGuidanceOwnScopeAppliesToTotalsTrendBreakdownAndRecent(): void
    {
        $a = $this->actor(['counselling.records.read']);
        $b = $this->actor(['counselling.records.read']);
        $supervisor = $this->actor(['counselling.records.read', 'counselling.records.read_any']);
        $ownId = $this->session($a['userId']);
        $this->session($a['userId'], ['ended_at' => self::NOW]);
        $this->session($a['userId'], ['ended_at' => '2030-02-01 15:59:59']);
        $this->session($a['userId'], ['archived_at' => self::NOW]);
        $this->session($a['userId'], ['tenant_id' => 1]);
        $otherId = $this->session($b['userId']);
        $own = $this->data('guidance', $a['userId']);
        $this->assertSame('own', $own['scope']);
        $this->assertSame(['open_sessions' => 1, 'completed_sessions' => 1], $this->metrics($own));
        $this->assertSame(3, array_sum(array_column($own['trend']['points'], 'value')));
        $this->assertSame(['closed' => 2, 'open' => 1], array_column($own['breakdown']['items'], 'value', 'key'));
        $this->assertContains($ownId, array_column($own['recent']['rows'], 'id'));
        $this->assertNotContains($otherId, array_column($own['recent']['rows'], 'id'));
        $this->assertCount(3, $own['recent']['rows']);
        $team = $this->data('guidance', $supervisor['userId']);
        $this->assertSame('tenant', $team['scope']);
        $this->assertSame(2, $this->metrics($team)['open_sessions']);
        $this->assertCount(4, $team['recent']['rows']);
        $this->assertSame(4, array_sum(array_column($team['trend']['points'], 'value')));
        // Returning to the owner after oversight must not reuse a shared PHI projection.
        $this->assertSame($own, $this->data('guidance', $a['userId']));
    }

    public function testFacilitiesCountsDoNotFanOutAndExcludeArchivedOrForeignParents(): void
    {
        $actor = $this->actor(['facilities.units.read', 'facilities.bmg.io.record']);
        $id = $actor['userId'];
        $unit = $this->unit();
        $active = $this->batch($unit, $id);
        $this->batch($unit, $id, ['status' => 'released', 'total_input_weight_kg' => 12.5, 'output_weight_kg' => 3, 'finished_at' => self::NOW]);
        $this->batch($unit, $id, ['status' => 'idle', 'total_input_weight_kg' => 7.5, 'finished_at' => self::NOW]);
        $this->batch($unit, $id, ['status' => 'cancelled', 'total_input_weight_kg' => 90]);
        $this->batch($unit, $id, ['status' => 'released', 'archived_at' => self::NOW]);
        $this->batch($unit, $id, ['status' => 'released', 'tenant_id' => 1]);
        $archived = $this->unit(['archived_at' => self::NOW]);
        $this->batch($archived, $id);
        $foreign = $this->unit(['tenant_id' => 1]);
        $this->batch($foreign, $id); // Deliberately mismatched child tenant.
        $this->device($unit, $id);
        $this->device($archived, $id);
        $this->device($foreign, $id);
        $this->device($this->unit(), $id, ['archived_at' => self::NOW]);
        $this->device($unit, $id, ['unit_id' => null]);
        for ($i = 0; $i < 3; $i++) {
            $this->db->table('facilities_bmg_batch_updates')->insert(['tenant_id' => $this->tenant, 'batch_id' => $active,
                'update_type' => 'output', 'output_weight_kg' => 1, 'recorded_by_user_id' => $id, 'created_at' => self::NOW]);
        }
        $data = $this->data('facilities', $id);
        $this->assertSame(['total_units' => 2, 'active_batches' => 1, 'bound_devices' => 1, 'treated_kg' => 20.0], $this->metrics($data));
        $this->assertSame(4, array_sum(array_column($data['trend']['points'], 'value')));
        $this->assertSame(['idle' => 2], array_column($data['breakdown']['items'], 'value', 'key'));
        $this->assertCount(2, $data['recent']['rows']);
        $row = array_values(array_filter($data['recent']['rows'], static fn ($r) => $r['id'] === $unit))[0];
        $this->assertSame(self::NOW, $row['occurred_at']);
        $this->assertSame('Controller', $row['owner']);
        $this->assertSame('idle', $row['status']);
    }

    public function testClinicOptionalMetricsUseTodayAndTheirResourcePermissions(): void
    {
        $actor = $this->actor(['clinic.encounters.read', 'clinic.queue.read', 'clinic.appointments.read', 'clinic.inventory.read']);
        $id = $actor['userId'];
        foreach ([['2030-02-02', 'waiting', false], ['2030-02-02', 'called', false], ['2030-02-01', 'waiting', false], ['2030-02-02', 'done', false], ['2030-02-02', 'waiting', true]] as [$day, $status, $archived]) {
            $encounter = $this->encounter($id, $archived ? ['archived_at' => self::NOW] : []);
            $max = $this->db->table('clinic_queue_entries')->selectMax('position')->where('queue_date', $day)->get()->getRowArray();
            $this->db->table('clinic_queue_entries')->insert(['tenant_id' => $this->tenant, 'encounter_id' => $encounter,
                'queue_date' => $day, 'position' => (int) ($max['position'] ?? 0) + 1, 'status' => $status, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        }
        foreach ([self::NOW, '2030-02-02 15:59:59', '2030-02-02 16:00:00', '2030-02-01 15:59:59'] as $time) {
            $this->db->table('clinic_appointments')->insert(['tenant_id' => $this->tenant, 'patient_school_id' => 'dash', 'provider_user_id' => $id,
                'scheduled_at' => $time, 'status' => 'scheduled', 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        }
        foreach ([[$this->tenant, null], [$this->tenant, self::NOW], [1, null]] as [$tenant, $archived]) {
            $this->db->table('clinic_medicines')->insert(['tenant_id' => $tenant, 'generic_name' => 'Dashboard medicine', 'archived_at' => $archived,
                'created_at' => self::NOW, 'updated_at' => self::NOW]);
        }
        $this->assertSame(['waiting_queue' => 2, 'completed_consultations' => 0, 'appointments_today' => 2, 'active_medicines' => 1],
            $this->metrics($this->data('clinic', $id)));
    }

    public function testGuidanceIncomingReferralsRespectDepartmentAndQueueDayBoardVisibility(): void
    {
        $actor = $this->actor(['counselling.records.read', 'counselling.queue.read', 'referrals.read']);
        $other = $this->actor(['counselling.records.read']);
        foreach ([['counselling', 'submitted', null, $this->tenant], ['counselling', 'acknowledged', null, $this->tenant],
            ['counselling', 'under_review', null, $this->tenant], ['clinic', 'submitted', null, $this->tenant],
            ['counselling', 'closed', null, $this->tenant], ['counselling', 'submitted', self::NOW, $this->tenant],
            ['counselling', 'submitted', null, 1]] as [$target, $status, $archived, $tenant]) {
            $this->db->table('referral_referrals')->insert(['tenant_id' => $tenant, 'patient_school_id' => 'dashboard',
                'source_module' => $target === 'clinic' ? 'counselling' : 'clinic', 'target_module' => $target,
                'artifact_type' => 'summary', 'issuer_user_id' => $actor['userId'], 'status' => $status,
                'archived_at' => $archived, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        }
        foreach ([['2030-02-02', 'waiting', $this->tenant], ['2030-02-01', 'waiting', $this->tenant],
            ['2030-02-02', 'called', $this->tenant], ['2030-02-02', 'waiting', 1]] as [$day, $status, $tenant]) {
            $max = $this->db->table('counselling_queue_entries')->selectMax('position')->where('queue_date', $day)->get()->getRowArray();
            $this->db->table('counselling_queue_entries')->insert(['tenant_id' => $tenant,
                'patient_user_id' => $other['userId'], 'patient_school_id' => 'D-' . bin2hex(random_bytes(5)), 'purpose' => 'Dashboard fixture', 'assigned_counsellor_user_id' => $other['userId'],
                'queue_date' => $day, 'position' => (int) ($max['position'] ?? 0) + 1,
                'status' => $status, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        }
        $data = $this->data('guidance', $actor['userId']);
        $this->assertSame('own', $data['scope']);
        $this->assertSame(['open_sessions' => 0, 'completed_sessions' => 0, 'waiting_queue' => 1, 'incoming_referrals' => 3], $this->metrics($data));
    }

    public function testCountersRetainKeysButScopeUnitsAndCountDistinctAtRiskBatches(): void
    {
        $actor = $this->actor(['facilities.units.read']);
        $unit = $this->unit(['status' => 'processing']);
        $batch = $this->batch($unit, $actor['userId']);
        $this->unit(['tenant_id' => 1, 'status' => 'processing']);
        $this->unit(['archived_at' => self::NOW, 'status' => 'idle']);
        foreach (['temperature_high', 'moisture_low'] as $code) {
            $this->db->table('facilities_bmg_alerts')->insert(['tenant_id' => $this->tenant, 'batch_id' => $batch,
                'code' => $code, 'severity' => 'warning', 'message' => 'Fixture', 'triggered_at' => self::NOW, 'created_at' => self::NOW, 'updated_at' => self::NOW]);
        }
        $res = $this->authed($actor['token'], 'get', 'api/v1/dashboard/counters');
        $res->assertStatus(200);
        $this->assertSame(['units_idle' => 0, 'units_processing' => 1, 'units_awaiting' => 0, 'at_risk' => 1], $this->envelope($res)['data']['facilities']);
    }

    public function testClinicalIdentityJoinsCannotExposeForeignOrArchivedNames(): void
    {
        $actor = $this->actor(['clinic.encounters.read', 'counselling.records.read']);
        $foreign = $this->createUser()['id'];
        $this->db->table('users')->where('id', $foreign)->update(['tenant_id' => 1, 'first_name' => 'FOREIGN', 'last_name' => 'SECRET']);
        $archived = $this->createUser()['id'];
        $this->db->table('users')->where('id', $archived)->update(['tenant_id' => $this->tenant, 'first_name' => 'ARCHIVED', 'last_name' => 'SECRET', 'archived_at' => self::NOW]);
        foreach ([$foreign, $archived] as $patient) {
            $this->encounter($actor['userId'], ['patient_user_id' => $patient, 'attending_user_id' => $patient]);
            $this->session($actor['userId'], ['patient_user_id' => $patient]);
        }
        foreach (['clinic', 'guidance'] as $unit) {
            $data = $this->data($unit, $actor['userId']);
            $this->assertSame(['Patient', 'Patient'], array_column($data['recent']['rows'], 'title'));
            $this->assertStringNotContainsString('SECRET', json_encode($data));
        }
        $this->assertSame([null, null], array_column($this->data('clinic', $actor['userId'])['recent']['rows'], 'owner'));
    }

    public function testCountersDoNotLeakAnotherCounsellorsSessionAggregate(): void
    {
        $a = $this->actor(['counselling.records.read']);
        $b = $this->actor(['counselling.records.read']);
        $this->session($a['userId']);
        $this->session($b['userId']);
        $this->session($b['userId'], ['ended_at' => self::NOW]);
        foreach ([[$a, 0], [$b, 1], [$a, 0]] as [$actor, $closed]) {
            $res = $this->authed($actor['token'], 'get', 'api/v1/dashboard/counters');
            $res->assertStatus(200);
            $this->assertSame(['open_sessions' => 1, 'closed_sessions' => $closed], $this->envelope($res)['data']['counselling']);
        }
    }

    public function testRecentOrderingIsDeterministicAndBoundedForEveryResource(): void
    {
        $actor = $this->actor(['clinic.encounters.read', 'counselling.records.read', 'facilities.units.read']);
        $ids = ['clinic' => [], 'guidance' => [], 'facilities' => []];
        for ($i = 0; $i < 8; $i++) {
            $ids['clinic'][] = $this->encounter($actor['userId']);
            $ids['guidance'][] = $this->session($actor['userId']);
            $ids['facilities'][] = $this->unit();
        }
        foreach ($ids as $unit => $expected) {
            $rows = $this->data($unit, $actor['userId'])['recent']['rows'];
            $this->assertSame(array_slice(array_reverse($expected), 0, 6), array_column($rows, 'id'));
        }
    }
}
