<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

final class PermanentDeletionTest extends FeatureTestCase
{
    public static function eligibleResources(): array
    {
        return array_map(static fn (string $key): array => [$key], [
            'announcements', 'services', 'surveys', 'equipment', 'medicines', 'supplies',
            'staff-schedules', 'devices', 'waste-categories', 'drums', 'report-configs',
        ]);
    }

    public static function protectedResources(): array
    {
        return [['students'], ['employees'], ['encounters'], ['counselling-sessions']];
    }

    private function endpoint(string $resource, int $id): string
    {
        return 'api/v1/archived-records/' . $resource . '/' . $id . '/delete-permanently';
    }

    /** @return array{0:string,1:int} */
    private function fixture(string $resource, int $actor, bool $archived = true): array
    {
        $code = 'del_' . bin2hex(random_bytes(5));
        $now = gmdate('Y-m-d H:i:s');
        [$table, $data] = match ($resource) {
            'announcements' => ['guidance_announcements', ['title' => 'Private title', 'body' => 'Private body']],
            'services' => ['guidance_services', ['code' => $code, 'name' => 'Test service']],
            'surveys' => ['surveys', ['title' => 'Private survey title']],
            'equipment' => ['clinic_equipment', ['name' => 'Test equipment']],
            'medicines' => ['clinic_medicines', ['generic_name' => 'Test medicine']],
            'supplies' => ['clinic_inventory_items', ['sku' => $code, 'name' => 'Test supply']],
            'staff-schedules' => ['clinic_staff_schedules', ['user_id' => $actor, 'day_of_week' => 1, 'shift_start' => '08:00:00', 'shift_end' => '09:00:00']],
            'devices' => ['facilities_bmg_devices', ['code' => $code, 'display_name' => 'Test device', 'token_hash' => hash('sha256', $code), 'token_prefix' => substr($code, 0, 12), 'linked_user_id' => $actor]],
            'waste-categories' => ['facilities_waste_categories', ['code' => $code, 'name' => 'Test waste']],
            'drums' => ['facilities_bmg_units', ['code' => $code, 'display_name' => 'Test drum']],
            'report-configs' => ['report_configurations', ['name' => 'Private report name', 'module' => 'clinic', 'created_by_user_id' => $actor]],
            'students' => ['users', ['username' => $code, 'kind' => 'student', 'first_name' => 'Private', 'last_name' => 'Patient']],
            'employees' => ['users', ['username' => $code, 'kind' => 'employee', 'first_name' => 'Private', 'last_name' => 'Patient']],
            'encounters' => ['clinic_encounters', ['chief_complaint' => 'Private', 'attending_user_id' => $actor, 'started_at' => $now, 'status' => 'closed']],
            'counselling-sessions' => ['counselling_sessions', ['counsellor_user_id' => $actor, 'started_at' => $now]],
        };
        $data += ['tenant_id' => 1, 'created_at' => $now, 'updated_at' => $now];
        if (in_array($resource, ['staff-schedules', 'waste-categories', 'report-configs'], true)) {
            $data['is_active'] = $archived ? 0 : 1;
        } else {
            $data['archived_at'] = $archived ? $now : null;
        }
        // The clinical legacy identifier column has been retired in newer schemas.
        if (db_connect()->fieldExists('patient_school_id', $table)) {
            $data['patient_school_id'] = $code;
        }
        db_connect()->table($table)->insert($data);
        return [$table, (int) db_connect()->insertID()];
    }

    private function deletionAudits(string $table, int $id): int
    {
        return db_connect()->table('audit_outbox')->where('action_code', 'archive.permanently_deleted')
            ->where('entity_type', $table)->where('entity_id', $id)->countAllResults();
    }

    #[DataProvider('eligibleResources')]
    public function testEligibleArchivedRowsDeletedAndActiveRowsRefused(string $resource): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture($resource, $admin['userId'], false);
        $this->authed($admin['token'], 'post', $this->endpoint($resource, $id))->assertStatus(409);
        $this->assertSame(0, $this->deletionAudits($table, $id));
        $this->assertSame(1, db_connect()->table($table)->where('id', $id)->countAllResults());
        db_connect()->table($table)->where('id', $id)->update(
            in_array($resource, ['staff-schedules', 'waste-categories', 'report-configs'], true)
                ? ['is_active' => 0] : ['archived_at' => gmdate('Y-m-d H:i:s')],
        );
        $result = $this->authed($admin['token'], 'post', $this->endpoint($resource, $id));
        $result->assertStatus(200);
        $this->assertSame(['resource' => $resource, 'id' => $id, 'deleted' => true], $this->envelope($result)['data']);
        $this->assertSame(0, db_connect()->table($table)->where('id', $id)->countAllResults());
        $this->assertSame(1, $this->deletionAudits($table, $id));
        $audit = db_connect()->table('audit_outbox')->where('action_code', 'archive.permanently_deleted')->where('entity_type', $table)->where('entity_id', $id)->get()->getRowArray();
        $this->assertStringNotContainsString('Private', $audit['context_json']);
        $this->authed($admin['token'], 'post', $this->endpoint($resource, $id))->assertStatus(404);
        $this->assertSame(1, $this->deletionAudits($table, $id));
    }

    #[DataProvider('protectedResources')]
    public function testPatientAndClinicalRetentionAlwaysWins(string $resource): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture($resource, $admin['userId']);
        $before = db_connect()->table($table)->where('id', $id)->get()->getRowArray();
        $result = $this->authed($admin['token'], 'post', $this->endpoint($resource, $id));
        $result->assertStatus(409);
        $this->assertErrorCode('archive.retention_protected', $result);
        $this->assertStringContainsString('not authorized', $this->envelope($result)['errors'][0]['message']);
        $this->assertSame($before, db_connect()->table($table)->where('id', $id)->get()->getRowArray());
        $this->assertSame(0, $this->deletionAudits($table, $id));
    }

    #[DataProvider('eligibleResources')]
    #[DataProvider('protectedResources')]
    public function testPermissionAndTenantIsolation(string $resource): void
    {
        $admin = $this->login(['superadmin']);
        $outsider = $this->login([]);
        [$table, $id] = $this->fixture($resource, $admin['userId']);
        $this->authed($outsider['token'], 'post', $this->endpoint($resource, $id))->assertStatus(403);
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('tenants')->insert(['name' => 'Deletion isolation', 'slug' => 'del-' . bin2hex(random_bytes(5)), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $tenant = (int) db_connect()->insertID();
        db_connect()->table('users')->where('id', $admin['userId'])->update(['tenant_id' => $tenant]);
        $this->authed($admin['token'], 'post', $this->endpoint($resource, $id))->assertStatus(404);
        $this->assertSame(1, db_connect()->table($table)->where('id', $id)->countAllResults());
        $this->assertSame(0, $this->deletionAudits($table, $id));
    }

    public function testSupplyStockBalanceIsRetainedEvenWithoutMovements(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture('supplies', $admin['userId']);
        db_connect()->table($table)->where('id', $id)->update(['quantity_on_hand' => 5]);
        $result = $this->authed($admin['token'], 'post', $this->endpoint('supplies', $id));
        $result->assertStatus(409);
        $this->assertErrorCode('archive.stock_protected', $result);
        $this->assertSame(5, (int) db_connect()->table($table)->where('id', $id)->get()->getRowArray()['quantity_on_hand']);
        $this->assertSame(0, $this->deletionAudits($table, $id));
    }

    public function testPublishedSurveyHistoryIsRetained(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture('surveys', $admin['userId']);
        db_connect()->table('survey_versions')->insert(['tenant_id' => 1, 'survey_id' => $id, 'version_no' => 1, 'published_at' => gmdate('Y-m-d H:i:s')]);
        $result = $this->authed($admin['token'], 'post', $this->endpoint('surveys', $id));
        $result->assertStatus(409);
        $this->assertStringContainsString('published survey versions', $this->envelope($result)['errors'][0]['message']);
        $this->assertSame(1, db_connect()->table('survey_versions')->where('survey_id', $id)->countAllResults());
        $this->assertSame(0, $this->deletionAudits($table, $id));
    }

    public function testDraftCleanupRollsBackWhenLaterChildIsPublished(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture('surveys', $admin['userId']);
        $questionIds = [];
        foreach (['Draft one', 'Draft two'] as $text) {
            db_connect()->table('survey_questions')->insert(['tenant_id' => 1, 'survey_id' => $id, 'question_text' => $text]);
            $questionIds[] = (int) db_connect()->insertID();
        }
        db_connect()->table('survey_answer_options')->insert(['tenant_id' => 1, 'question_id' => $questionIds[0], 'option_text' => 'Draft option']);
        // Published content discovered after the first draft child was removed
        // forces the entire transaction (including that removal) to roll back.
        db_connect()->table('survey_questions')->where('id', $questionIds[1])->update(['version_id' => 999999]);
        $result = $this->authed($admin['token'], 'post', $this->endpoint('surveys', $id));
        $result->assertStatus(409);
        $this->assertSame(2, db_connect()->table('survey_questions')->where('survey_id', $id)->countAllResults());
        $this->assertSame(1, db_connect()->table('survey_answer_options')->where('question_id', $questionIds[0])->countAllResults());
        $this->assertSame(0, $this->deletionAudits($table, $id));
        db_connect()->table('survey_questions')->where('id', $questionIds[1])->update(['version_id' => null]);
        $this->authed($admin['token'], 'post', $this->endpoint('surveys', $id))->assertStatus(200);
        $this->assertSame(0, db_connect()->table('survey_questions')->where('survey_id', $id)->countAllResults());
        $this->assertSame(0, db_connect()->table('survey_answer_options')->where('question_id', $questionIds[0])->countAllResults());
    }

    public function testHistoricalStockMovementBlocksDeletion(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture('supplies', $admin['userId']);
        db_connect()->table('clinic_inventory_movements')->insert(['tenant_id' => 1, 'item_id' => $id, 'qty_delta' => 1, 'reason_code' => 'receive', 'moved_by_user_id' => $admin['userId'], 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->authed($admin['token'], 'post', $this->endpoint('supplies', $id))->assertStatus(409);
        $this->assertSame(1, db_connect()->table('clinic_inventory_movements')->where('item_id', $id)->countAllResults());
        $this->assertSame(0, $this->deletionAudits($table, $id));
    }

    public function testDeviceCommandCascadeCannotEraseEvidence(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture('devices', $admin['userId']);
        db_connect()->table('facilities_bmg_device_commands')->insert(['tenant_id' => 1, 'device_id' => $id, 'command' => 'start', 'payload' => '{}', 'status' => 'acknowledged', 'updated_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s')]);
        $this->authed($admin['token'], 'post', $this->endpoint('devices', $id))->assertStatus(409);
        $this->assertSame(1, db_connect()->table('facilities_bmg_device_commands')->where('device_id', $id)->countAllResults());
        $this->assertSame(0, $this->deletionAudits($table, $id));
    }

    public function testNonFkDeviceCodeTelemetryIsProtected(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $device] = $this->fixture('devices', $admin['userId']);
        [, $drum] = $this->fixture('drums', $admin['userId']);
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('facilities_bmg_batches')->insert([
            'tenant_id' => 1, 'unit_id' => $drum, 'reference_code' => 'del_' . bin2hex(random_bytes(5)),
            'status' => 'cancelled', 'input_items' => '[]', 'started_by_user_id' => $admin['userId'],
            'started_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $batch = (int) db_connect()->insertID();
        $code = db_connect()->table($table)->where('id', $device)->get()->getRowArray()['code'];
        db_connect()->table('facilities_bmg_process_logs')->insert([
            'tenant_id' => 1, 'batch_id' => $batch, 'device_id' => $code, 'log_date' => '2026-01-01',
            'recorded_by_user_id' => $admin['userId'], 'created_at' => $now,
        ]);
        $result = $this->authed($admin['token'], 'post', $this->endpoint('devices', $device));
        $result->assertStatus(409);
        $this->assertStringContainsString('telemetry history', $this->envelope($result)['errors'][0]['message']);
        $this->assertSame(1, db_connect()->table('facilities_bmg_process_logs')->where('device_id', $code)->countAllResults());
        $this->assertSame(0, $this->deletionAudits($table, $device));
    }

    public function testSurveyLinkOpenHistoryIsNotCascaded(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture('surveys', $admin['userId']);
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('survey_links')->insert([
            'tenant_id' => 1, 'survey_id' => $id, 'type' => 'survey', 'title' => 'Draft link',
            'external_url' => 'https://example.test/survey', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $link = (int) db_connect()->insertID();
        db_connect()->table('survey_link_opens')->insert([
            'tenant_id' => 1, 'survey_id' => $id, 'link_id' => $link,
            'student_user_id' => $admin['userId'], 'opened_at' => $now,
        ]);
        $this->authed($admin['token'], 'post', $this->endpoint('surveys', $id))->assertStatus(409);
        $this->assertSame(1, db_connect()->table('survey_link_opens')->where('link_id', $link)->countAllResults());
        $this->assertSame(1, db_connect()->table('survey_links')->where('id', $link)->countAllResults());
        $this->assertSame(0, $this->deletionAudits($table, $id));
    }

    public function testRetiredEquipmentUnitsAndForecastHistoryAreRetained(): void
    {
        $admin = $this->login(['superadmin']);
        $now = gmdate('Y-m-d H:i:s');
        [$equipmentTable, $equipment] = $this->fixture('equipment', $admin['userId']);
        db_connect()->table('clinic_equipment_units')->insert([
            'tenant_id' => 1, 'equipment_id' => $equipment, 'status' => 'retired',
            'status_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->authed($admin['token'], 'post', $this->endpoint('equipment', $equipment))->assertStatus(409);
        $this->assertSame(0, $this->deletionAudits($equipmentTable, $equipment));
        [$medicineTable, $medicine] = $this->fixture('medicines', $admin['userId']);
        db_connect()->table('clinic_medicine_forecasts')->insert([
            'tenant_id' => 1, 'medicine_id' => $medicine, 'forecast_date' => '2026-01-01',
            'forecast_period_start' => '2026-01-01', 'forecast_period_end' => '2026-01-02',
            'predicted_daily_usage' => 0, 'current_stock' => 0, 'reorder_threshold' => 0, 'created_at' => $now,
        ]);
        $this->authed($admin['token'], 'post', $this->endpoint('medicines', $medicine))->assertStatus(409);
        $this->assertSame(1, db_connect()->table('clinic_medicine_forecasts')->where('medicine_id', $medicine)->countAllResults());
        $this->assertSame(0, $this->deletionAudits($medicineTable, $medicine));
    }

    public function testClosedDeviceAssignmentProtectsBothDeviceAndDrum(): void
    {
        $admin = $this->login(['superadmin']);
        [$deviceTable, $device] = $this->fixture('devices', $admin['userId']);
        [$drumTable, $drum] = $this->fixture('drums', $admin['userId']);
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('facilities_bmg_device_assignments')->insert([
            'tenant_id' => 1, 'device_id' => $device, 'unit_id' => $drum,
            'assigned_by_user_id' => $admin['userId'], 'assigned_at' => $now,
            'unassigned_at' => $now, 'created_at' => $now,
        ]);
        foreach (['devices' => [$deviceTable, $device], 'drums' => [$drumTable, $drum]] as $resource => [$table, $id]) {
            $result = $this->authed($admin['token'], 'post', $this->endpoint($resource, $id));
            $result->assertStatus(409);
            $this->assertStringContainsString('assignment history', $this->envelope($result)['errors'][0]['message']);
            $this->assertSame(0, $this->deletionAudits($table, $id));
        }
        $this->assertSame(1, db_connect()->table('facilities_bmg_device_assignments')->where('device_id', $device)->countAllResults());
    }

    public function testGeneratedReportReferenceCannotBeSetNullByDeletion(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture('report-configs', $admin['userId']);
        $now = gmdate('Y-m-d H:i:s');
        db_connect()->table('generated_reports')->insert([
            'tenant_id' => 1, 'config_id' => $id, 'module' => 'clinic', 'file_path' => '',
            'status' => 'expired', 'generated_by_user_id' => $admin['userId'], 'generated_at' => $now, 'created_at' => $now,
        ]);
        $this->authed($admin['token'], 'post', $this->endpoint('report-configs', $id))->assertStatus(409);
        $this->assertSame(1, db_connect()->table('generated_reports')->where('config_id', $id)->countAllResults());
        $this->assertSame(0, $this->deletionAudits($table, $id));
    }

    public function testLegacyWasteDeleteAlsoRequiresArchiveAndChecksReferences(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture('waste-categories', $admin['userId'], false);
        $route = 'api/v1/facilities/waste-categories/' . $id;
        $this->authed($admin['token'], 'delete', $route)->assertStatus(409);
        db_connect()->table($table)->where('id', $id)->update(['is_active' => 0]);
        [, $drum] = $this->fixture('drums', $admin['userId']);
        db_connect()->table('facilities_bmg_units')->where('id', $drum)->update(['default_category_id' => $id]);
        $this->authed($admin['token'], 'delete', $route)->assertStatus(409);
        $this->assertSame(1, db_connect()->table($table)->where('id', $id)->countAllResults());
        $this->assertSame(0, $this->deletionAudits($table, $id));
    }

    public function testAuditInsertFailureRollsBackParentDeletion(): void
    {
        $admin = $this->login(['superadmin']);
        [$table, $id] = $this->fixture('announcements', $admin['userId']);
        $db = db_connect();
        $trigger = 'test_delete_audit_' . bin2hex(random_bytes(5));
        // Test-schema-only fault injection, restricted to this fixture event.
        $db->query("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON audit_outbox FOR EACH ROW BEGIN IF NEW.action_code = 'archive.permanently_deleted' AND NEW.entity_type = 'guidance_announcements' AND NEW.entity_id = {$id} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test audit failure'; END IF; END");
        try {
            try {
                $result = $this->authed($admin['token'], 'post', $this->endpoint('announcements', $id));
                $result->assertStatus(409);
            } catch (\CodeIgniter\Database\Exceptions\DatabaseException $exception) {
                $this->assertStringContainsString('Test audit failure', $exception->getMessage());
            }
            $this->assertSame(1, $db->table($table)->where('id', $id)->countAllResults());
            $this->assertSame(0, $this->deletionAudits($table, $id));
        } finally {
            $db->query('DROP TRIGGER `' . $trigger . '`');
            $db->resetTransStatus();
        }
    }

    public function testUnknownResourcesAndUnauthenticatedCallsAreRejected(): void
    {
        $this->post($this->endpoint('announcements', 1))->assertStatus(401);
        $admin = $this->login(['superadmin']);
        $this->authed($admin['token'], 'post', $this->endpoint('users', 1))->assertStatus(404);
    }
}
