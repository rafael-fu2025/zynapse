<?php

declare(strict_types=1);

namespace Tests\Feature;

use Config\Services;
use Modules\Clinic\Services\EmployeePersonService;

/**
 * EmployeeRegistryGroupingTest — duplicate MIS employee records collapse
 * to one primary registry row (EmployeePersonService).
 *
 * FU MIS issues one record per appointment, so one human legitimately
 * occupies several `users` rows. The registry lists only each person's
 * primary record (newest issuance year, then serial) and carries the
 * person's other records in the row payload for the position-history
 * accordion. Nothing is merged or deleted — a search by an old number
 * must still find its record.
 */
final class EmployeeRegistryGroupingTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep the search path deterministic and offline (the MIS fallback
        // must not be reached). All three channels are set because
        // Config\FuMis reads $_ENV first, then $_SERVER, then getenv().
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

    /** Collision-proof facet/name value. */
    private function unique(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4));
    }

    /**
     * Two distinct 6-digit MIS-format numbers in the same century digit,
     * strictly increasing so the second is always the newer appointment
     * under the year → serial precedence.
     *
     * @return array{old: string, new: string}
     */
    private function numberPair(): array
    {
        $old = random_int(900000, 949999);
        $new = random_int($old + 1, 999999);
        return ['old' => (string) $old, 'new' => (string) $new];
    }

    /**
     * Create an employee record through the real route.
     *
     * @param array<string, mixed> $overrides
     * @return array{number: string, id: int}
     */
    private function createEmployeeRecord(string $token, string $number, array $overrides = []): array
    {
        $payload = array_merge([
            'employee_number' => $number,
            'first_name'      => 'Grouping',
            'middle_name'     => 'Q',
            'last_name'       => $this->unique('Surname'),
        ], $overrides);

        $res = $this->authed($token, 'post', 'api/v1/clinic/employees', $payload);
        $res->assertStatus(201);

        return [
            'number' => $number,
            'id'     => (int) ($this->envelope($res)['data']['id'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>> registry rows of one surname
     */
    private function rowsForSurname(string $token, string $surname): array
    {
        $res = $this->authed($token, 'get', 'api/v1/clinic/employees?limit=100');
        $res->assertStatus(200);

        return array_values(array_filter(
            $this->envelope($res)['data'],
            static fn (array $r): bool => $r['last_name'] === $surname,
        ));
    }

    public function testDuplicateRecordsCollapseToOnePrimaryRow(): void
    {
        $admin = $this->login(['clinic_admin']);
        ['old' => $oldN, 'new' => $newN] = $this->numberPair();
        $surname = $this->unique('Surname');

        $this->createEmployeeRecord($admin['token'], $oldN, [
            'last_name'  => $surname,
            'department' => $this->unique('Dept Old'),
            'position'   => $this->unique('Pos Old'),
        ]);
        $this->createEmployeeRecord($admin['token'], $newN, [
            'last_name'  => $surname,
            'department' => $this->unique('Dept New'),
            'position'   => $this->unique('Pos New'),
        ]);

        $rows = $this->rowsForSurname($admin['token'], $surname);

        $this->assertCount(1, $rows, 'Both records belong to one person — only the primary row is listed.');
        $row = $rows[0];

        $this->assertSame($newN, $row['employee_number'], 'The listed record is the newer appointment.');
        $this->assertCount(2, $row['records'], 'The row carries the whole record group.');
        $this->assertSame($newN, $row['records'][0]['employee_number'], 'Records ordered newest first.');
        $this->assertSame($oldN, $row['records'][1]['employee_number']);
        $this->assertTrue($row['records'][0]['is_primary']);
        $this->assertFalse($row['records'][1]['is_primary']);
        $this->assertSame(
            EmployeePersonService::positionYear($newN),
            $row['records'][0]['position_year'],
        );
        $this->assertSame(
            EmployeePersonService::positionYear($oldN),
            $row['records'][1]['position_year'],
        );
    }

    public function testSearchStillFindsNonPrimaryRecords(): void
    {
        $admin = $this->login(['clinic_admin']);
        ['old' => $oldN, 'new' => $newN] = $this->numberPair();
        $surname = $this->unique('Surname');

        $this->createEmployeeRecord($admin['token'], $oldN, ['last_name' => $surname]);
        $this->createEmployeeRecord($admin['token'], $newN, ['last_name' => $surname]);

        // Searching by the hidden record's number must find it — with the
        // person's full record group attached.
        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees/search?q=' . $oldN);
        $res->assertStatus(200);

        $rows = array_values(array_filter(
            $this->envelope($res)['data'],
            static fn (array $r): bool => $r['employee_number'] === $oldN,
        ));
        $this->assertCount(1, $rows, 'A non-primary record stays findable by its number.');
        $this->assertCount(2, $rows[0]['records'], 'Search results carry the record group too.');
    }

    public function testFacetsListOnlyPrimaryPositions(): void
    {
        $admin = $this->login(['clinic_admin']);
        ['old' => $oldN, 'new' => $newN] = $this->numberPair();
        $surname = $this->unique('Surname');
        $deptOld = $this->unique('Dept Facet Old');
        $deptNew = $this->unique('Dept Facet New');
        $posOld  = $this->unique('Pos Facet Old');
        $posNew  = $this->unique('Pos Facet New');

        $this->createEmployeeRecord($admin['token'], $oldN, [
            'last_name' => $surname, 'department' => $deptOld, 'position' => $posOld,
        ]);
        $this->createEmployeeRecord($admin['token'], $newN, [
            'last_name' => $surname, 'department' => $deptNew, 'position' => $posNew,
        ]);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees/facets');
        $res->assertStatus(200);
        $data = $this->envelope($res)['data'];

        $this->assertContains($deptNew, $data['departments']);
        $this->assertContains($posNew, $data['positions']);
        $this->assertNotContains($deptOld, $data['departments'], 'A hidden record must not offer a facet option.');
        $this->assertNotContains($posOld, $data['positions'], 'A hidden record must not offer a facet option.');
    }

    public function testPositionFacetYieldsThePrimaryRowOnly(): void
    {
        $admin = $this->login(['clinic_admin']);
        ['old' => $oldN, 'new' => $newN] = $this->numberPair();
        $surname = $this->unique('Surname');
        $posOld  = $this->unique('Pos Filter Old');
        $posNew  = $this->unique('Pos Filter New');

        $this->createEmployeeRecord($admin['token'], $oldN, ['last_name' => $surname, 'position' => $posOld]);
        $this->createEmployeeRecord($admin['token'], $newN, ['last_name' => $surname, 'position' => $posNew]);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees?limit=100&position=' . rawurlencode($posNew));
        $res->assertStatus(200);
        $this->assertContains(
            $newN,
            array_map(static fn (array $r): string => (string) $r['employee_number'], $this->envelope($res)['data']),
        );

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees?limit=100&position=' . rawurlencode($posOld));
        $res->assertStatus(200);
        $this->assertSame(
            [],
            $this->envelope($res)['data'],
            'The person is listed once (primary); their old position must not resurrect a second row.',
        );
    }

    public function testEmployeeEncountersReturnRecordHistory(): void
    {
        $admin = $this->login(['clinic_admin']);
        $emp   = $this->createEmployeeRecord($admin['token'], (string) random_int(100000, 999999));

        $now = date('Y-m-d H:i:s');
        db_connect()->table('clinic_encounters')->insert([
            'patient_school_id' => $emp['number'],
            'patient_user_id'   => $emp['id'],
            'tenant_id'         => 1,
            'chief_complaint'   => 'Fever and headache',
            'attending_user_id' => $admin['userId'],
            'started_at'        => $now,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees/' . $emp['id'] . '/encounters');
        $res->assertStatus(200);

        $data = $this->envelope($res)['data'];
        $this->assertCount(1, $data);
        $this->assertSame('Fever and headache', $data[0]['chief_complaint']);
        $this->assertNotNull($data[0]['attending_username'], 'The attending user is resolvable.');
    }

    public function testEmployeeEncountersAreTenantScoped(): void
    {
        $admin = $this->login(['clinic_admin']);
        $emp   = $this->createEmployeeRecord($admin['token'], (string) random_int(100000, 999999));

        db_connect()->table('users')->where('id', $emp['id'])->update(['tenant_id' => 2]);

        $res = $this->authed($admin['token'], 'get', 'api/v1/clinic/employees/' . $emp['id'] . '/encounters');
        $res->assertStatus(404);
    }

    public function testEmployeeEncountersRequirePatientReadPermission(): void
    {
        $outsider = $this->login(['bmg_admin']);

        $res = $this->authed($outsider['token'], 'get', 'api/v1/clinic/employees/1/encounters');
        $res->assertStatus(403);
        $this->assertErrorCode('rbac.permission_denied:clinic.patients.read', $res);
    }
}
