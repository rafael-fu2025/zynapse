<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\CurrentUser;
use App\Exceptions\ApiException;
use App\Services\CurrentTenant;
use Config\Services;
use Modules\Clinic\Policies\ClinicPolicy;
use Modules\Clinic\Services\AppointmentService;
use Modules\Clinic\Services\ClinicService;
use Modules\Clinic\Services\EmployeeSelfService;
use Modules\Clinic\Services\MedicineService;
use Modules\Clinic\Services\PatientLookupService;
use Modules\Clinic\Services\PatientService;
use Modules\Clinic\Services\StudentSelfService;

/** Real isolated MySQL service/transaction tests; no MIS requests. */
final class ClinicBoundedDeliveryTest extends FeatureTestCase
{
    private int $tenant;
    private int $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('tenants')->insert([
            'name' => 'Clinic synthetic tenant', 'slug' => 'clinic-' . bin2hex(random_bytes(6)),
            'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->tenant = (int) $this->db->insertID();
        $this->actor = $this->createUser(['clinic_admin'])['id'];
        $this->db->table('users')->where('id', $this->actor)->update(['tenant_id' => $this->tenant]);
        CurrentUser::bind($this->actor);
        CurrentTenant::set($this->tenant);
        // Existing queue_date_position UNIQUE omits tenant_id. Keep this test
        // isolated from other suite fixtures without changing that legacy DDL.
        $max = $this->db->table('clinic_queue_entries')->selectMax('position')->where('queue_date', \App\Modules\Shared\ManilaDay::today())->get()->getRowArray();
        if ((int) ($max['position'] ?? 0) > 0) {
            $this->db->table('clinic_encounters')->insert(['tenant_id' => $this->tenant, 'patient_school_id' => 'synthetic-sentinel', 'chief_complaint' => 'Fixture sentinel', 'status' => 'open', 'attending_user_id' => $this->actor, 'started_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $this->db->table('clinic_queue_entries')->insert(['tenant_id' => $this->tenant, 'encounter_id' => (int) $this->db->insertID(), 'queue_date' => \App\Modules\Shared\ManilaDay::today(), 'position' => (int) $max['position'] + 1, 'status' => 'done', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    protected function tearDown(): void
    {
        // Only this case's synthetic queue rows: avoid the legacy global
        // position index colliding with subsequent tenant-1 suite fixtures.
        $this->db->table('clinic_queue_entries')->where('tenant_id', $this->tenant)->delete();
        // The existing privileged-role fixture enumerates memberships globally.
        // Do not leave this case's foreign-tenant synthetic admin in that pool.
        $this->db->table('auth_groups_users')->where('user_id', $this->actor)->delete();
        parent::tearDown();
    }

    private function patient(string $kind, ?int $tenant = null): array
    {
        $number = 'CL-' . bin2hex(random_bytes(6));
        $this->db->table('users')->insert([
            'tenant_id' => $tenant ?? $this->tenant, 'kind' => $kind, 'status' => 'active', 'active' => 1,
            'username' => $number, 'first_name' => 'Synthetic', 'last_name' => 'Clinic',
            $kind === 'student' ? 'student_number' : 'employee_number' => $number,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return ['id' => (int) $this->db->insertID(), 'number' => $number];
    }

    private function appointments(): AppointmentService
    {
        return new AppointmentService(new ClinicPolicy(), Services::auditOutbox(), Services::notificationOutbox());
    }

    private function rejects(callable $action, int $status = 422, string $code = 'request.validation_failed'): void
    {
        try {
            $action();
            $this->fail('Foreign/inconsistent operation must fail closed.');
        } catch (ApiException $e) {
            $this->assertSame($status, $e->httpStatus);
            $this->assertSame($code, $e->errorCode);
            if ($status === 422) {
                $this->assertSame('validation.field', $e->errors[0]['code']);
            }
        }
    }

    public function testStudentAndEmployeeBookingCheckinSelfQueueAndHistoryKeepCanonicalIdentity(): void
    {
        foreach (['student', 'employee'] as $index => $kind) {
            $patient = $this->patient($kind);
            [$resolvedKind, $resolved] = (new PatientLookupService())->findByIdentifier($patient['number']);
            $this->assertSame($kind, $resolvedKind);
            $this->assertSame($patient['id'], (int) $resolved['id']);
            $appointment = $this->appointments()->schedule($patient['number'], $this->actor, '2030-01-01 ' . ($index === 0 ? '01' : '05') . ':00:00', null)->toArray();
            $this->appointments()->transition((int) $appointment['id'], 'checked_in');
            $enc = $this->db->table('clinic_encounters')->where('appointment_id', $appointment['id'])->get()->getRowArray();
            $this->assertSame($patient['id'], (int) $enc['patient_user_id']);
            $this->assertSame($this->tenant, (int) $enc['tenant_id']);
            $status = Services::queueService(false)->myStatus($patient['id']);
            $this->assertSame((int) $enc['id'], $status['encounter_id']);
            CurrentUser::bind($patient['id']);
            $visits = $kind === 'student' ? (new StudentSelfService())->listMyClinicVisits() : (new EmployeeSelfService())->listMyClinicVisits();
            $this->assertContains((int) $enc['id'], array_column($visits, 'id'));
            CurrentUser::bind($this->actor);
        }
        // Newly linked visits count as their canonical patient kinds, not unknown.
        $report = (new \Modules\Reports\Services\ReportService())->clinic([
            'start' => \App\Modules\Shared\ManilaDay::today(), 'end' => \App\Modules\Shared\ManilaDay::today(),
        ]);
        $types = array_column($report['patient_type_breakdown'], 'cnt', 'kind');
        $this->assertSame(1, $types['student']);
        $this->assertSame(1, $types['employee']);
        $this->assertSame(2, $report['unique_patients']);
        // The optional queue sentinel is an unlinked operational fixture.
        $this->assertSame(2 + ($types['unknown'] ?? 0), $report['total_encounters']);
    }

    public function testForeignIdentityAndPatientFksCannotWriteOrNotify(): void
    {
        foreach (['student', 'employee'] as $kind) {
            $foreign = $this->patient($kind, 1);
            $noticeBefore = $this->db->table('notification_outbox')->where('recipient_user_id', $foreign['id'])->countAllResults();
            $before = $this->db->table('clinic_appointments')->where('tenant_id', $this->tenant)->countAllResults();
            $this->rejects(fn () => $this->appointments()->schedule($foreign['number'], $this->actor, '2030-02-01 00:00:00', null));
            $this->rejects(fn () => (new ClinicService(new ClinicPolicy(), Services::auditOutbox(), Services::notificationOutbox()))->createEncounter($foreign['number'], 'Synthetic'));
            $this->rejects(fn () => $this->appointments()->bookSelf($foreign['id'], null, gmdate('Y-m-d H:i:s', time() + 86400), null));
            $this->assertSame($before, $this->db->table('clinic_appointments')->where('tenant_id', $this->tenant)->countAllResults());
            $this->assertSame($noticeBefore, $this->db->table('notification_outbox')->where('recipient_user_id', $foreign['id'])->countAllResults());
        }
        $local = $this->patient('student');
        $appt = $this->appointments()->schedule($local['number'], $this->actor, '2030-03-01 00:00:00', null)->toArray();
        $this->db->table('clinic_appointments')->where('id', $appt['id'])->update(['patient_user_id' => $foreign['id']]);
        $this->rejects(fn () => $this->appointments()->transition((int) $appt['id'], 'checked_in'));
        $this->assertSame(0, $this->db->table('clinic_encounters')->where('appointment_id', $appt['id'])->countAllResults());
        $this->assertSame([null, null], (new PatientLookupService())->findByIdentifier(''));
    }

    public function testNonblankUnknownIdentifierKeepsNullLinkWithoutForeignNotifications(): void
    {
        Services::resetSingle('fuMisAuthService');
        Services::resetSingle('fuMisClient');
        // No network even if the workstation enables MIS: this fallback is
        // intentionally unknown and can only receive a synthetic response.
        Services::injectMock('fuMisTransport', new class implements \App\Services\FuMis\HttpTransport {
            public function request(string $method, string $url, array $headers, ?array $body = null, int $timeoutSeconds = 10): array
            {
                throw new \App\Services\FuMis\FuMisException('synthetic.unknown', 404, 'Synthetic unknown identifier');
            }
        });
        try {
            $number = 'UNKNOWN-' . bin2hex(random_bytes(8));
            $this->assertSame([null, null], (new PatientLookupService())->findByIdentifier($number));
            $noticeMax = (int) ($this->db->table('notification_outbox')->selectMax('id')->get()->getRowArray()['id'] ?? 0);
            $appt = $this->appointments()->schedule($number, $this->actor, '2030-04-01 00:00:00', null)->toArray();
            $row = $this->db->table('clinic_appointments')->where('id', $appt['id'])->get()->getRowArray();
            $this->assertNull($row['patient_user_id']);
            $this->appointments()->transition((int) $appt['id'], 'checked_in');
            $enc = $this->db->table('clinic_encounters')->where('appointment_id', $appt['id'])->get()->getRowArray();
            $this->assertNull($enc['patient_user_id']);
            $notices = $this->db->table('notification_outbox')->where('id >', $noticeMax)->get()->getResultArray();
            $this->assertSame([$this->actor], array_values(array_unique(array_map('intval', array_column($notices, 'recipient_user_id')))));
        } finally {
            Services::resetSingle('fuMisTransport');
            Services::resetSingle('fuMisClient');
            Services::resetSingle('fuMisAuthService');
        }
    }

    public function testNonDefaultTenantMedicalCrudAndPrimaryDemotionContainForeignChildren(): void
    {
        $patient = $this->patient('student');
        $foreign = $this->patient('employee', 1);
        $service = new PatientService(new ClinicPolicy(), Services::auditOutbox());
        $service->addAllergy($patient['id'], ['allergen' => 'Synthetic allergen']);
        $allergy = $this->db->table('patient_allergies')->where('user_id', $patient['id'])->get()->getRowArray();
        $this->assertSame($this->tenant, (int) $allergy['tenant_id']);
        $service->updateAllergy($patient['id'], (int) $allergy['id'], ['allergen' => 'Changed', 'severity' => 'severe']);
        $contact = ['contact_name' => 'Synthetic contact', 'relationship' => 'Sibling', 'phone' => '09000000000', 'is_primary' => true];
        $service->addContact($patient['id'], $contact);
        $first = $this->db->table('patient_contacts')->where('user_id', $patient['id'])->get()->getRowArray();
        // Historical mismatch is fixture-only: verify no read, edit, delete or demotion leaks.
        $this->db->table('patient_contacts')->insert($contact + ['tenant_id' => 1, 'user_id' => $patient['id'], 'created_at' => gmdate('Y-m-d H:i:s')]);
        $foreignChild = (int) $this->db->insertID();
        $service->addContact($patient['id'], $contact);
        $second = $this->db->table('patient_contacts')->where('tenant_id', $this->tenant)->where('user_id', $patient['id'])->orderBy('id', 'DESC')->get()->getRowArray();
        $this->assertSame(0, (int) $this->db->table('patient_contacts')->where('id', $first['id'])->get()->getRowArray()['is_primary']);
        $dto = $service->updateContact($patient['id'], (int) $first['id'], $contact)->toArray();
        $this->assertSame(0, (int) $this->db->table('patient_contacts')->where('id', $second['id'])->get()->getRowArray()['is_primary']);
        $this->assertSame(1, (int) $this->db->table('patient_contacts')->where('id', $foreignChild)->get()->getRowArray()['is_primary']);
        $this->assertNotContains($foreignChild, array_column($dto['contacts'], 'id'));
        $this->rejects(fn () => $service->updateContact($patient['id'], $foreignChild, $contact), 404, 'resource.not_found');
        $this->rejects(fn () => $service->deleteContact($patient['id'], $foreignChild), 404, 'resource.not_found');
        $this->rejects(fn () => $service->addAllergy($foreign['id'], ['allergen' => 'Foreign']), 404, 'resource.not_found');
        // A same-tenant child pointing to a foreign parent must also reject before writing.
        $this->db->table('patient_allergies')->insert(['tenant_id' => $this->tenant, 'user_id' => $foreign['id'], 'allergen' => 'Mismatch', 'severity' => 'mild', 'noted_by_user_id' => $this->actor, 'created_at' => gmdate('Y-m-d H:i:s')]);
        $mismatch = (int) $this->db->insertID();
        $this->rejects(fn () => $service->updateAllergy($foreign['id'], $mismatch, ['allergen' => 'No']), 404, 'resource.not_found');
        $this->rejects(fn () => $service->deleteAllergy($foreign['id'], $mismatch), 404, 'resource.not_found');
        $service->deleteAllergy($patient['id'], (int) $allergy['id']);
        $service->deleteContact($patient['id'], (int) $first['id']);
        $service->deleteContact($patient['id'], (int) $second['id']);
        $this->assertSame(0, $this->db->table('patient_contacts')->where('tenant_id', $this->tenant)->where('user_id', $patient['id'])->countAllResults());
    }

    private function medicineFixture(int $encounter): array
    {
        $this->db->table('clinic_medicines')->insert(['tenant_id' => $this->tenant, 'generic_name' => 'Synthetic-' . bin2hex(random_bytes(5)), 'unit' => 'tablet', 'reorder_threshold' => 0, 'created_at' => '2030-01-01 00:00:00', 'updated_at' => '2030-01-01 00:00:00']);
        $medicine = (int) $this->db->insertID();
        $ids = [];
        foreach ([['2030-01-01', 9, 'active'], ['2030-01-02', 2, 'active'], ['2030-01-02', 9, 'recalled'], ['2030-01-03', 4, 'active']] as [$expiry, $qty, $status]) {
            $this->db->table('clinic_medicine_batches')->insert(['tenant_id' => $this->tenant, 'medicine_id' => $medicine, 'batch_number' => bin2hex(random_bytes(5)), 'expiration_date' => $expiry, 'received_date' => '2029-12-01', 'quantity_received' => $qty, 'quantity_remaining' => $qty, 'status' => $status, 'created_at' => '2030-01-01 00:00:00']);
            $ids[] = (int) $this->db->insertID();
        }
        $this->db->table('clinic_medicine_transactions')->insert(['tenant_id' => $this->tenant, 'medicine_id' => $medicine, 'batch_id' => $ids[0], 'type' => 'received', 'quantity' => 24, 'balance_after' => 24, 'performed_by_user_id' => $this->actor, 'created_at' => '2030-01-01 00:00:00']);
        return [$medicine, $ids];
    }

    public function testDownstreamInsertFailuresRollbackStockLedgerAndTreatment(): void
    {
        $patient = $this->patient('student');
        $clinic = new ClinicService(new ClinicPolicy(), Services::auditOutbox(), Services::notificationOutbox(), clock: static fn () => '2030-01-01 16:00:00');
        $direct = new MedicineService(new ClinicPolicy(), Services::auditOutbox(), static fn () => '2030-01-01 16:00:00');
        $enc = $clinic->createEncounter($patient['number'], 'Synthetic rollback')->toArray();
        foreach (['treatment', 'ledger'] as $fault) {
            [$medicine, $batches] = $this->medicineFixture((int) $enc['id']);
            $trigger = 'clinic_test_fault_' . bin2hex(random_bytes(5));
            $table = $fault === 'treatment' ? 'clinic_treatments' : 'clinic_medicine_transactions';
            $condition = 'NEW.medicine_id = ' . $medicine;
            if ($fault === 'ledger') {
                // Fail the SECOND split: the first decrement + ledger insert
                // have already executed and must roll back with the second.
                $condition .= ' AND NEW.batch_id = ' . $batches[3];
            }
            // Test-only DDL on the bootstrap-guarded isolated schema. No app
            // migration, deployed trigger or production table is modified.
            $this->db->query("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$table}` FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic downstream failure'; END IF; END");
            $this->db->transException(true);
            try {
                try {
                    if ($fault === 'treatment') {
                        $clinic->addTreatment((int) $enc['id'], ['treatment_type' => 'medication', 'medicine_id' => $medicine, 'quantity' => 3, 'description' => 'Must rollback']);
                    } else {
                        $direct->dispense($medicine, 3, 'Must rollback', (int) $enc['id']);
                    }
                    $this->fail('Injected downstream database failure must propagate.');
                } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
                    $this->assertStringContainsString('synthetic downstream failure', $e->getMessage());
                }
            } finally {
                $this->db->query("DROP TRIGGER IF EXISTS `{$trigger}`");
                $this->db->resetTransStatus();
                $this->db->transException(false);
            }
            $this->assertSame([9, 2, 9, 4], array_map('intval', array_column($this->db->table('clinic_medicine_batches')->where('medicine_id', $medicine)->orderBy('id')->get()->getResultArray(), 'quantity_remaining')));
            $this->assertSame(1, $this->db->table('clinic_medicine_transactions')->where('medicine_id', $medicine)->countAllResults());
            $this->assertSame(0, $this->db->table('clinic_treatments')->where('medicine_id', $medicine)->countAllResults());
        }
    }

    public function testManilaMidnightTreatmentDirectDispenseParitySplitLedgerAndRollback(): void
    {
        $patient = $this->patient('student');
        $clinic = new ClinicService(new ClinicPolicy(), Services::auditOutbox(), Services::notificationOutbox(), clock: static fn () => '2030-01-01 16:00:00');
        $medicineService = new MedicineService(new ClinicPolicy(), Services::auditOutbox(), static fn () => '2030-01-01 16:00:00');
        $enc = $clinic->createEncounter($patient['number'], 'Synthetic stock parity')->toArray();
        // One second before Manila midnight the prior expiry day is still usable.
        $beforeClinic = new ClinicService(new ClinicPolicy(), Services::auditOutbox(), Services::notificationOutbox(), clock: static fn () => '2030-01-01 15:59:59');
        $beforeDirect = new MedicineService(new ClinicPolicy(), Services::auditOutbox(), static fn () => '2030-01-01 15:59:59');
        foreach (['treatment', 'direct'] as $path) {
            [$beforeMedicine, $beforeBatches] = $this->medicineFixture((int) $enc['id']);
            if ($path === 'treatment') {
                $beforeClinic->addTreatment((int) $enc['id'], ['treatment_type' => 'medication', 'medicine_id' => $beforeMedicine, 'quantity' => 1, 'description' => 'Before midnight']);
            } else {
                $beforeDirect->dispense($beforeMedicine, 1, null, (int) $enc['id']);
            }
            $priorLedger = $this->db->table('clinic_medicine_transactions')->where('medicine_id', $beforeMedicine)->where('type', 'dispensed')->get()->getRowArray();
            $this->assertSame($beforeBatches[0], (int) $priorLedger['batch_id']);
            [$medicine, $batches] = $this->medicineFixture((int) $enc['id']);
            if ($path === 'treatment') {
                $clinic->addTreatment((int) $enc['id'], ['treatment_type' => 'medication', 'medicine_id' => $medicine, 'quantity' => 3, 'description' => 'Synthetic']);
                $treatment = $this->db->table('clinic_treatments')->where('medicine_id', $medicine)->get()->getRowArray();
                $this->assertSame($batches[1], (int) $treatment['batch_id']);
            } else {
                $medicineService->dispense($medicine, 3, 'Synthetic direct', (int) $enc['id']);
            }
            $ledger = $this->db->table('clinic_medicine_transactions')->where('medicine_id', $medicine)->where('type', 'dispensed')->orderBy('id')->get()->getResultArray();
            $this->assertSame([$batches[1], $batches[3]], array_map('intval', array_column($ledger, 'batch_id')));
            $this->assertSame([2, 1], array_map('intval', array_column($ledger, 'quantity')));
            $this->assertSame([22, 21], array_map('intval', array_column($ledger, 'balance_after')));
            $this->assertSame(['encounter', 'encounter'], array_column($ledger, 'reference_type'));
            $this->assertSame([9, 0, 9, 3], array_map('intval', array_column($this->db->table('clinic_medicine_batches')->where('medicine_id', $medicine)->orderBy('id')->get()->getResultArray(), 'quantity_remaining')));
            $before = $this->db->table('clinic_medicine_transactions')->where('medicine_id', $medicine)->countAllResults();
            $this->rejects(fn () => $clinic->addTreatment((int) $enc['id'], ['treatment_type' => 'medication', 'medicine_id' => $medicine, 'quantity' => 4, 'description' => 'Insufficient']), 409, 'statemachine.inventory.insufficient_stock');
            $this->rejects(fn () => $medicineService->dispense($medicine, 4, null, (int) $enc['id']), 409, 'statemachine.inventory.insufficient_stock');
            $this->assertSame($before, $this->db->table('clinic_medicine_transactions')->where('medicine_id', $medicine)->countAllResults());
            $this->assertSame($path === 'treatment' ? 1 : 0, $this->db->table('clinic_treatments')->where('medicine_id', $medicine)->countAllResults());
        }
    }
}
