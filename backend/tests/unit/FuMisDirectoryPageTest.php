<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FuMis\FuMisDirectoryPage;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Pure parser tests for MIS directory pagination envelope parsing.
 */
final class FuMisDirectoryPageTest extends TestCase
{
    public function testParsesNestedDataEnvelope(): void
    {
        $parsed = FuMisDirectoryPage::parse([
            'status' => 'success',
            'data'   => [
                'data'         => [
                    ['student_id' => '20261001', 'first_name' => 'John'],
                    ['student_id' => '20261002', 'first_name' => 'Jane'],
                ],
                'current_page' => 1,
                'max_page'     => 3,
                'limit'        => 20,
            ],
        ], 1, 10);

        $this->assertSame(1, $parsed['current_page']);
        $this->assertSame(3, $parsed['max_page']);
        $this->assertCount(2, $parsed['records']);
    }

    public function testParsesFlatRootEnvelopeWithNumericStringMetadata(): void
    {
        $parsed = FuMisDirectoryPage::parse([
            'data'         => [['employee_id' => 'EMP1001', 'first_name' => 'Ada']],
            'current_page' => '2',
            'max_page'     => '5',
        ], 2, 10);

        $this->assertSame(2, $parsed['current_page']);
        $this->assertSame(5, $parsed['max_page']);
        $this->assertSame('EMP1001', $parsed['records'][0]['employee_id']);
    }

    public function testAcceptsEmptyDirectorySnapshot(): void
    {
        $parsed = FuMisDirectoryPage::parse([
            'data'         => [],
            'current_page' => 1,
            'max_page'     => 0,
        ], 1, 10);

        $this->assertSame(1, $parsed['current_page']);
        $this->assertSame(0, $parsed['max_page']);
        $this->assertSame([], $parsed['records']);
    }

    public function testRejectsUnsuccessfulEnvelope(): void
    {
        $this->expectException(UnexpectedValueException::class);
        FuMisDirectoryPage::parse([
            'status'  => 'error',
            'message' => 'Upstream service unavailable',
            'data'    => [],
        ], 1, 10);
    }

    public function testRejectsMismatchedCurrentPage(): void
    {
        $this->expectException(UnexpectedValueException::class);
        FuMisDirectoryPage::parse([
            'data'         => [['student_id' => '20261001']],
            'current_page' => 2,
            'max_page'     => 2,
        ], 1, 10);
    }

    public function testRejectsMaxPageExceedingSafetyLimit(): void
    {
        $this->expectException(UnexpectedValueException::class);
        FuMisDirectoryPage::parse([
            'data'         => [['student_id' => '20261001']],
            'current_page' => 1,
            'max_page'     => 500,
        ], 1, 100);
    }

    public function testRejectsEmptyNonFinalPage(): void
    {
        $this->expectException(UnexpectedValueException::class);
        FuMisDirectoryPage::parse([
            'data'         => [],
            'current_page' => 1,
            'max_page'     => 2,
        ], 1, 10);
    }

    public function testRejectsMalformedRecordEntry(): void
    {
        $this->expectException(UnexpectedValueException::class);
        FuMisDirectoryPage::parse([
            'data'         => ['not-an-assoc-array'],
            'current_page' => 1,
            'max_page'     => 1,
        ], 1, 10);
    }
}
