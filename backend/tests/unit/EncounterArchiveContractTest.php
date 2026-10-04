<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * EncounterArchiveContractTest — the archive action's structural
 * invariants, asserted against the service source.
 *
 * Why a source scan: `archived_at` is a soft-delete-ish filter that must
 * be applied CONSISTENTLY or the feature half-works in a way no single
 * endpoint test catches. The two failure modes this guards:
 *
 *   1. **A writer that sets the stamp but a reader that ignores it** —
 *      the encounter would stay on the board forever (archiving "does
 *      nothing"). The queue feed is the one that matters: `listEncounters`
 *      already filtered `archived_at` before this feature, but the queue
 *      board did not, so archiving a Done row used to leave it visible.
 *   2. **A gate that lets an in-flight visit be archived** — hiding live
 *      clinical work while the queue keeps serving it.
 *
 * Pure / no DB, mirroring `QueueSkipWindowContractTest`.
 */
final class EncounterArchiveContractTest extends TestCase
{
    private function clinicServiceSource(): string
    {
        $source = file_get_contents(__DIR__ . '/../../app/Modules/Clinic/Services/ClinicService.php');
        $this->assertIsString($source);
        return $source;
    }

    private function queueServiceSource(): string
    {
        $source = file_get_contents(__DIR__ . '/../../app/Modules/Clinic/Services/QueueService.php');
        $this->assertIsString($source);
        return $source;
    }

    public function testArchiveIsGatedOnAFinishedEncounter(): void
    {
        $source = $this->clinicServiceSource();

        $this->assertStringContainsString('public function archiveEncounter(', $source);
        $this->assertStringContainsString('public function restoreEncounter(', $source);

        // The gate: only closed / referred may be archived.
        $this->assertMatchesRegularExpression(
            "/in_array\\(\\\$status, \\['closed', 'referred'\\], true\\)/",
            $source,
            'archiveEncounter must refuse an encounter that is not finished.',
        );
        $this->assertStringContainsString('statemachine.clinic.encounter_not_finished', $source);
    }

    public function testArchiveWritesTheStampAndAuditsIt(): void
    {
        $source = $this->clinicServiceSource();

        // The write is a soft stamp, never a delete.
        $this->assertStringContainsString("'archived_at' => \$now", $source);
        $this->assertStringContainsString("'clinic.encounter_archived'", $source);
        $this->assertStringContainsString("'clinic.encounter_restored'", $source);
    }

    public function testArchiveReadsTheRowWithoutAnArchivedFilterSoItStaysIdempotent(): void
    {
        $source = $this->clinicServiceSource();

        // The archive/restore row lock must NOT carry `archived_at => null`
        // (that would 404 a re-archive instead of no-opping). Scope the
        // check to the archiveEncounter body.
        $body = $this->extractMethod($source, 'archiveEncounter');
        $this->assertNotSame('', $body, 'archiveEncounter body not found');
        $this->assertStringNotContainsString("'archived_at' => null", $body);

        // Both directions short-circuit on the current state.
        $this->assertStringContainsString("\$enc['archived_at'] !== null", $body);
        $this->assertStringContainsString('return EncounterDto::fromRow($enc);', $body);
    }

    public function testActiveListAndArchivedSliceAreMutuallyExclusive(): void
    {
        $source = $this->clinicServiceSource();

        // The active branch filters archived rows OUT …
        $this->assertStringContainsString("->where('e.archived_at', null);", $source);
        // … and the archived branch selects them IN.
        $this->assertStringContainsString("->where('e.archived_at IS NOT NULL', null, false)", $source);
        $this->assertStringContainsString("if (\$status === 'archived')", $source);
    }

    public function testQueueFeedsExcludeArchivedEncounters(): void
    {
        $source = $this->queueServiceSource();

        // Both operational feeds join the encounter — each must filter
        // archived rows, or an archived Done row stays on the board.
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($source, "->where('e.archived_at', null)"),
            'todayRows() and skipped() must both exclude archived encounters.',
        );
    }

    public function testRoutesAndPolicyAreRegistered(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../app/Modules/Clinic/Routes.php');
        $this->assertIsString($routes);
        $this->assertStringContainsString("post('encounters/(:num)/archive'", $routes);
        $this->assertStringContainsString("post('encounters/(:num)/restore'", $routes);

        $policy = file_get_contents(__DIR__ . '/../../app/Modules/Clinic/Policies/ClinicPolicy.php');
        $this->assertIsString($policy);
        $this->assertStringContainsString("'archive'           => 'clinic.encounters.write'", $policy);
        $this->assertStringContainsString("'restore'           => 'clinic.encounters.write'", $policy);

        // The list endpoint must accept the archived slice.
        $controller = file_get_contents(__DIR__ . '/../../app/Modules/Clinic/Controllers/ClinicController.php');
        $this->assertIsString($controller);
        $this->assertStringContainsString("'archived'", $controller);
    }

    /**
     * Extract a method body for scoped assertions. Best-effort regex,
     * adequate for the invariants above (same approach as
     * ClinicOutcomeEnumContractTest).
     */
    private function extractMethod(string $source, string $methodName): string
    {
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s';
        return preg_match($pattern, $source, $m) === 1 ? $m[0] : '';
    }
}
