<?php

declare(strict_types=1);

namespace Tests\Feature;

use Config\Services;
use Modules\Clinic\Services\EmployeePersonService;

/**
 * EmployeePortalGroupingTest — the employee portal over a person with
 * several MIS records.
 *
 * The profile payload carries the caller's whole record group (records +
 * position_year). "My clinic visits" accepts ?record_id= to read another
 * of the caller's OWN records; a record that is not the same person (per
 * the strict three-part name key) 404s — clinic history must never cross
 * to a differently-named account.
 */
final class EmployeePortalGroupingTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('FUMIS_ENABLED=0');
        $_SERVER['FUMIS_ENABLED'] = '0';
        $_ENV['FUMIS_ENABLED']    = '0';
        Services::resetSingle('fuMisAuthService');
        Services::resetSingle('fuMisClient');
        Services::resetSingle('fuMisTransport');
    }

    protected function tearDown(): void
    {
        putenv('FUMIS_ENABLED');
        unset($_SERVER['FUMIS_ENABLED'], $_ENV['FUMIS_ENABLED']);
        Services::resetSingle('fuMisTransport');
        Services::resetSingle('fuMisClient');
        Services::resetSingle('fuMisAuthService');

        parent::tearDown();
    }

    private function unique(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Log in as a user whose own users row IS an employee record.
     *
     * @return array{token: string, userId: int, number: string}
     */
    private function employeeLogin(string $number, string $lastName): array
    {
        $user = $this->login(['employee']);
        db_connect()->table('users')->where('id', $user['userId'])->update([
            'kind'            => 'employee',
            'employee_number' => $number,
            'first_name'      => 'Portal',
            'middle_name'     => 'Q',
            'last_name'       => $lastName,
        ]);
        return ['token' => $user['token'], 'userId' => $user['userId'], 'number' => $number];
    }

    /**
     * A second MIS appointment record for the same person (exact
     * three-part name), with a strictly newer 6-digit number.
     */
    private function insertSiblingRecord(string $lastName, string $number): int
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('users')->insert([
            'username'        => 'emp-' . $number . '-' . bin2hex(random_bytes(3)),
            'status'          => 'active',
            'active'          => 1,
            'kind'            => 'employee',
            'employee_number' => $number,
            'first_name'      => 'Portal',
            'middle_name'     => 'Q',
            'last_name'       => $lastName,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
        return (int) $db->insertID();
    }

    private function insertEncounter(int $patientUserId, int $attendingUserId, string $complaint): void
    {
        $now = date('Y-m-d H:i:s');
        db_connect()->table('clinic_encounters')->insert([
            'patient_school_id' => 'portal-' . bin2hex(random_bytes(4)),
            'patient_user_id'   => $patientUserId,
            'tenant_id'         => 1,
            'chief_complaint'   => $complaint,
            'attending_user_id' => $attendingUserId,
            'started_at'        => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    public function testProfileCarriesTheRecordGroup(): void
    {
        $surname = $this->unique('Surname');
        $own     = $this->employeeLogin((string) random_int(900000, 949999), $surname);
        $siblingId = $this->insertSiblingRecord($surname, (string) random_int(950000, 999999));

        $res = $this->authed($own['token'], 'get', 'api/v1/me/employee-profile');
        $res->assertStatus(200);

        $data = $this->envelope($res)['data'];
        $this->assertSame($own['number'], $data['employee_number']);
        $this->assertSame(
            EmployeePersonService::positionYear($own['number']),
            $data['position_year'],
        );
        $this->assertCount(2, $data['records'], "The profile lists the person's whole record group.");

        $primaries = array_values(array_filter($data['records'], static fn (array $r): bool => $r['is_primary']));
        $this->assertCount(1, $primaries, 'Exactly one primary record.');
        $this->assertSame($siblingId, $primaries[0]['id'], 'The newer number is the primary, not the logged-in record.');
    }

    public function testClinicVisitsReadASiblingRecord(): void
    {
        $surname = $this->unique('Surname');
        $own     = $this->employeeLogin((string) random_int(900000, 949999), $surname);
        $siblingId = $this->insertSiblingRecord($surname, (string) random_int(950000, 999999));

        $this->insertEncounter($siblingId, $own['userId'], 'Sibling-record checkup');
        $this->insertEncounter($own['userId'], $own['userId'], 'Own-record checkup');

        $default = $this->authed($own['token'], 'get', 'api/v1/me/clinic-visits');
        $default->assertStatus(200);
        $this->assertSame('Own-record checkup', $this->envelope($default)['data'][0]['chief_complaint']);

        $sibling = $this->authed($own['token'], 'get', 'api/v1/me/clinic-visits?record_id=' . $siblingId);
        $sibling->assertStatus(200);
        $this->assertSame('Sibling-record checkup', $this->envelope($sibling)['data'][0]['chief_complaint']);

        $ownId = $this->authed($own['token'], 'get', 'api/v1/me/clinic-visits?record_id=' . $own['userId']);
        $ownId->assertStatus(200);
        $this->assertSame('Own-record checkup', $this->envelope($ownId)['data'][0]['chief_complaint']);
    }

    public function testClinicVisitsRejectAForeignRecord(): void
    {
        $surname = $this->unique('Surname');
        $own     = $this->employeeLogin((string) random_int(900000, 949999), $surname);

        // A different person's employee record — same shape, different name.
        $foreign = $this->insertSiblingRecord($this->unique('OtherSurname'), (string) random_int(950000, 999999));
        $this->insertEncounter($foreign, $own['userId'], 'Foreign history');

        $res = $this->authed($own['token'], 'get', 'api/v1/me/clinic-visits?record_id=' . $foreign);
        $res->assertStatus(404);
        $this->assertErrorCode('employee.not_registered', $res);
    }
}
