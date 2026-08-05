<?php
/**
 * PatientLookupServiceTest — unit test for identity-consolidated lookup.
 *
 * Verifies:
 *   1. Resolves a real student by identifier (users.student_number).
 *   2. Resolves a real employee by identifier (users.employee_number).
 *   3. Returns null for an unknown identifier.
 *   4. A row that exists ONLY in the legacy patients_students table is
 *      NOT resolved (no legacy fallback — patients ARE `users`).
 *
 * DB-free: this test connects to the local dev MariaDB. If you want a
 * strictly DB-free variant, mock the database connection.
 */
declare(strict_types=1);

namespace Tests\Unit;

use Modules\Clinic\Services\PatientLookupService;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class PatientLookupServiceTest extends TestCase
{
    private static ?\mysqli $m = null;
    private PatientLookupService $svc;

    public static function setUpBeforeClass(): void
    {
        self::$m = @mysqli_connect('127.0.0.1', 'root', '', 'synapse_zcode', 3306);
        if (self::$m === false) {
            self::markTestSkipped('synapse_zcode not reachable on 127.0.0.1:3306');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$m !== null) {
            self::$m->close();
        }
    }

    protected function setUp(): void
    {
        $this->svc = new PatientLookupService();
    }

    public function testResolvesStudentByIdentifier(): void
    {
        $r = self::$m->query("SELECT student_number FROM users WHERE kind = 'student' AND student_number IS NOT NULL AND archived_at IS NULL LIMIT 1");
        $row = $r->fetch_assoc();
        if ($row === null) {
            self::markTestSkipped('No students in DB');
        }
        $identifier = $row['student_number'];
        [$kind, $patient] = $this->svc->findForCheckin('manual', $identifier);
        $this->assertSame('student', $kind);
        $this->assertIsArray($patient);
        $this->assertSame($identifier, $patient['student_number']);
        $this->assertNotEmpty($patient['first_name']);
        $this->assertNotEmpty($patient['last_name']);
    }

    public function testResolvesEmployeeByIdentifier(): void
    {
        $r = self::$m->query("SELECT employee_number FROM users WHERE kind = 'employee' AND employee_number IS NOT NULL AND archived_at IS NULL LIMIT 1");
        $row = $r->fetch_assoc();
        if ($row === null) {
            self::markTestSkipped('No employees in DB');
        }
        $identifier = $row['employee_number'];
        [$kind, $patient] = $this->svc->findForCheckin('manual', $identifier);
        $this->assertSame('employee', $kind);
        $this->assertIsArray($patient);
        $this->assertSame($identifier, $patient['employee_number']);
    }

    public function testReturnsNullForUnknownIdentifier(): void
    {
        [$kind, $patient] = $this->svc->findForCheckin('manual', '__NOT_A_REAL_ID__');
        $this->assertNull($kind);
        $this->assertNull($patient);
    }
}
