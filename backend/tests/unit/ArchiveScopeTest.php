<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Shared\ArchiveScope;
use PHPUnit\Framework\TestCase;

final class ArchiveScopeTest extends TestCase
{
    public function testNamedScopesAreRecognised(): void
    {
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::from('active'));
        $this->assertSame(ArchiveScope::ARCHIVED, ArchiveScope::from('archived'));
        $this->assertSame(ArchiveScope::ALL, ArchiveScope::from('all'));
        $this->assertSame(ArchiveScope::ARCHIVED, ArchiveScope::from('  Archived '));
    }

    public function testLegacyBooleanMapsToAllOrActive(): void
    {
        // `include_archived=true` always meant "live + archived".
        $this->assertSame(ArchiveScope::ALL, ArchiveScope::from(true));
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::from(false));
    }

    public function testUnknownValuesFallBackToActiveNeverWiden(): void
    {
        // An unrecognised scope must not reveal archived records.
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::from(null));
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::from(''));
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::from('everything'));
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::from('1'));
    }

    public function testExplicitArchivedParamBeatsLegacyFlag(): void
    {
        $this->assertSame(ArchiveScope::ARCHIVED, ArchiveScope::fromRequest('archived', null));
        $this->assertSame(ArchiveScope::ARCHIVED, ArchiveScope::fromRequest('archived', '1'));
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::fromRequest('active', '1'));
    }

    public function testLegacyIncludeArchivedFlagStillWorksForTheMobileClient(): void
    {
        $this->assertSame(ArchiveScope::ALL, ArchiveScope::fromRequest(null, '1'));
        $this->assertSame(ArchiveScope::ALL, ArchiveScope::fromRequest(null, 'true'));
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::fromRequest(null, '0'));
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::fromRequest(null, null));
        $this->assertSame(ArchiveScope::ACTIVE, ArchiveScope::fromRequest('', null));
    }

    public function testPatientRegistryUsesTheScopeEverywhere(): void
    {
        // Contract: lists, search and facets all take the scope, and search no
        // longer hard-codes live-only (archived rows used to vanish on typing).
        $service = $this->read('app/Modules/Clinic/Services/PatientService.php');
        $controller = $this->read('app/Modules/Clinic/Controllers/PatientController.php');
        foreach (['listStudents', 'searchStudents', 'studentFacets', 'listEmployees', 'searchEmployees', 'employeeFacets'] as $method) {
            $body = $this->methodBody($service, $method);
            $this->assertStringContainsString('ArchiveScope::', $body, $method . ' must use the archive scope');
            $this->assertStringNotContainsString("->where('archived_at', null)", $body, $method . ' must not hard-code live-only');
        }
        $this->assertGreaterThanOrEqual(6, substr_count($service, 'ArchiveScope::apply('));
        $this->assertGreaterThanOrEqual(6, substr_count($controller, '$this->archiveScope()'));
        // The MIS directory can't be archived, so the archived view skips it.
        $this->assertSame(2, substr_count($service, 'ArchiveScope::ARCHIVED'));
    }

    /** Source of one method, up to the next method declaration. */
    private function methodBody(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');
        $this->assertNotFalse($start, 'method ' . $name . ' not found');
        preg_match('/\n    (?:public|private|protected) function /', $source, $m, PREG_OFFSET_CAPTURE, $start + 10);
        $end = $m[0][1] ?? strlen($source);
        return substr($source, $start, $end - $start);
    }

    private function read(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $this->assertIsString($source);
        return $source;
    }
}
