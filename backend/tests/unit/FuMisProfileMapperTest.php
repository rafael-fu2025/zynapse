<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FuMis\FuMisException;
use App\Services\FuMis\FuMisProfileMapper;
use PHPUnit\Framework\TestCase;

/**
 * FuMisProfileMapper contract tests.
 *
 * The MIS API docs never document the actual JSON keys inside `data`
 * (prose only), so the mapper probes candidate keys in priority order
 * and must tolerate every shape: missing keys, numeric strings, yes/no
 * booleans, and blank strings. These tests pin that tolerance down so
 * locking the mapping against real sandbox payloads (CONFIRM-FIELD
 * markers in the mapper) can't silently regress the fallbacks.
 */
final class FuMisProfileMapperTest extends TestCase
{
    private FuMisProfileMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FuMisProfileMapper();
    }

    public function testMapStudentMatchesRealSandboxPayload(): void
    {
        // Real payload shape captured 2026-09-08 from https://mis.foundationu.com/sandbox
        // Notice the data.data nesting, trailing space on middle_name, and string level.
        $realSandboxData = [
            'data' => [
                'student_id'  => '20230001',
                'last_name'   => 'DELA CRUZ',
                'first_name'  => 'JUAN',
                'middle_name' => 'REYES ',
                'program'     => 'BSIT',
                'level'       => '3',
                'department'  => 'CCS',
            ],
            'access_token'  => 'mock-token',
            'refresh_token' => 'mock-refresh',
            'expires_at'    => '2026-09-08 11:16:02',
        ];

        $result = $this->mapper->mapStudent($realSandboxData);

        $this->assertSame('20230001', $result['identifier']);
        $this->assertSame('JUAN', $result['first_name']);
        $this->assertSame('REYES', $result['middle_name'], 'Trailing whitespace in middle_name must be trimmed.');
        $this->assertSame('DELA CRUZ', $result['last_name']);
        $this->assertSame('BSIT', $result['course']);
        $this->assertSame(3, $result['year_level'], 'Level string "3" must parse to int 3.');
        $this->assertSame('CCS', $result['department']);
    }

    public function testMapStudentPrefersPrimarySpellings(): void
    {
        $result = $this->mapper->mapStudent([
            'student_id'  => '20261234',
            'first_name'  => 'Juan',
            'middle_name' => 'Reyes',
            'last_name'   => 'Dela Cruz',
            'program'     => 'BSIT',
            'level'       => '2',
            'section'     => 'Block C',
        ]);

        $this->assertSame('20261234', $result['identifier']);
        $this->assertSame('Juan', $result['first_name']);
        $this->assertSame('Reyes', $result['middle_name']);
        $this->assertSame('Dela Cruz', $result['last_name']);
        $this->assertSame('BSIT', $result['course']);
        $this->assertSame(2, $result['year_level']);
        $this->assertSame('Block C', $result['section']);
    }

    public function testMapStudentFallsBackToAlternateSpellings(): void
    {
        $result = $this->mapper->mapStudent([
            'id'         => '20269999',
            'firstName'  => 'Ana',
            'lastName'   => 'Lim',
            'course'     => 'BSN',
            'year_level' => 5,
        ]);

        $this->assertSame('20269999', $result['identifier']);
        $this->assertSame('Ana', $result['first_name']);
        $this->assertNull($result['middle_name']);
        $this->assertSame('Lim', $result['last_name']);
        $this->assertSame('BSN', $result['course']);
        $this->assertSame(5, $result['year_level']);
    }

    public function testMapStudentIsSafeOnEmptyPayload(): void
    {
        $result = $this->mapper->mapStudent([]);

        $this->assertNull($result['identifier']);
        $this->assertNull($result['first_name']);
        $this->assertNull($result['course']);
        $this->assertNull($result['year_level']);
        $this->assertNull($result['section']);
    }

    public function testMapStudentTrimsWhitespaceAndDropsBlankStrings(): void
    {
        $result = $this->mapper->mapStudent([
            'student_id' => '  20261234  ',
            'first_name' => '   ',
            'last_name'  => ' Cruz ',
        ]);

        $this->assertSame('20261234', $result['identifier']);
        $this->assertNull($result['first_name'], 'Whitespace-only values must read as absent.');
        $this->assertSame('Cruz', $result['last_name']);
    }

    public function testMapEmployeePrefersPrimarySpellings(): void
    {
        $result = $this->mapper->mapEmployee([
            'employee_id'       => '20269001',
            'first_name'        => 'Maria',
            'last_name'         => 'Santos',
            'department'        => 'College of Computer Studies',
            'position'          => 'Instructor I',
            'employment_status' => 'active',
            'is_teaching'       => true,
        ]);

        $this->assertSame('20269001', $result['identifier']);
        $this->assertSame('Maria', $result['first_name']);
        $this->assertSame('Santos', $result['last_name']);
        $this->assertSame('College of Computer Studies', $result['department']);
        $this->assertSame('Instructor I', $result['position']);
        $this->assertSame('active', $result['employment_status']);
        $this->assertTrue($result['is_teaching']);
    }

    public function testMapEmployeeCoercesTruthysAndClampsStatus(): void
    {
        $result = $this->mapper->mapEmployee([
            'employee_id'       => '20269002',
            'isTeaching'        => 'yes',
            'employment_status' => 'On Leave',
        ]);

        $this->assertSame('20269002', $result['identifier']);
        $this->assertTrue($result['is_teaching'], "'yes' must coerce to true.");
        $this->assertNull($result['employment_status'], 'Unknown enum values must read as absent, not garbage.');
    }

    public function testMapEmployeeIsSafeOnEmptyPayload(): void
    {
        $result = $this->mapper->mapEmployee([]);

        $this->assertNull($result['identifier']);
        $this->assertNull($result['department']);
        $this->assertNull($result['is_teaching']);
        $this->assertNull($result['employment_status']);
    }

    /**
     * The two boolean-ish shapes most likely in the real payloads.
     */
    public function testTeachingFlagAcceptsIntAndStringFalse(): void
    {
        $intFlag = $this->mapper->mapEmployee(['employee_id' => '1', 'is_teaching' => 0]);
        $this->assertFalse($intFlag['is_teaching']);

        $stringFlag = $this->mapper->mapEmployee(['employee_id' => '1', 'teaching' => 'no']);
        $this->assertFalse($stringFlag['is_teaching']);
    }

    /**
     * FuMisException surface the controller relies on for dispatch:
     * transport failures (status 0) vs upstream 401 (credentials) vs
     * upstream 5xx. Pinned here because FuMisAuthService's routing
     * decisions hang off these predicates.
     */
    public function testFuMisExceptionPredicates(): void
    {
        $transport = new FuMisException('fumis.transport', 0);
        $this->assertTrue($transport->isTransportFailure());
        $this->assertFalse($transport->isInvalidCredentials());

        $unauthorized = new FuMisException('fumis.invalid_credentials', 401, ['error' => 'invalid']);
        $this->assertFalse($unauthorized->isTransportFailure());
        $this->assertTrue($unauthorized->isInvalidCredentials());

        $serverError = new FuMisException('fumis.http_500', 500);
        $this->assertFalse($serverError->isTransportFailure());
        $this->assertFalse($serverError->isInvalidCredentials());
    }
}
