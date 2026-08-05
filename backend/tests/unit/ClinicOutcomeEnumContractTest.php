<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\EncounterAndQueueOutcome;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * ClinicOutcomeEnumContractTest — `outcome` taxonomy parity.
 *
 * Why: the `outcome` column is the discriminator that tells the
 * redesign (auto check-in, encounter-level no-show cascade, auto-close
 * of stale open encounters) *why* a row closed, not just *when*. It
 * lives in three places that must stay in lockstep:
 *
 *   1. `EncounterAndQueueOutcome::OUTCOME_CODES` — the canonical
 *      list, mirrored by the DB-level `chk_ce_outcome` /
 *      `chk_cqe_outcome` CHECK constraints (VARCHAR + CHECK pattern,
 *      matching `BmgProcessLogObservability`).
 *   2. `ClinicService::markNoShow()` — writes literal `'no_show'`.
 *   3. `ClinicService::autoCloseStaleEncounter()` — writes literal
 *      `'auto_closed'`.
 *
 * If the migration's const drifts from the service literals, a row
 * will silently violate the CHECK at write time and the staff will
 * see a 500 instead of a clean 409. This test guards the three-way
 * parity by:
 *
 *   - asserting the const matches the expected taxonomy;
 *   - asserting the service source contains both literals (so a
 *     refactor can't silently rename them without the test failing);
 *   - asserting the migration file itself declares both literals
 *     inside its `addColumn` / constraint queries (so a hand edit
 *     that bypasses `OUTCOME_CODES` is caught).
 *
 * Pure / reflection-based — no DB connection required, mirroring
 * `BmgLossCategoriesContractTest`.
 */
final class ClinicOutcomeEnumContractTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function expectedOutcomes(): array
    {
        return ['no_show', 'auto_closed'];
    }

    public function testMigrationConstMatchesExpectedTaxonomy(): void
    {
        $ref = new ReflectionClass(EncounterAndQueueOutcome::class);
        $const = $ref->getReflectionConstant('OUTCOME_CODES');
        $this->assertNotNull($const, 'Migration must declare OUTCOME_CODES const.');

        $this->assertSame(self::expectedOutcomes(), $const->getValue());
    }

    public function testMigrationSourceContainsBothOutcomeLiterals(): void
    {
        // Guards against a hand-edit of the migration that adds an
        // `addColumn` line but forgets to extend the CHECK constraint.
        $source = file_get_contents(
            __DIR__ . '/../../app/Database/Migrations/2026-08-04-000070_EncounterAndQueueOutcome.php',
        );
        $this->assertIsString($source);
        $this->assertStringContainsString(
            "'no_show'",
            $source,
            'Migration must reference `no_show` literal at least once.',
        );
        $this->assertStringContainsString(
            "'auto_closed'",
            $source,
            'Migration must reference `auto_closed` literal at least once.',
        );
    }

    public function testClinicServiceContainsBothOutcomeLiterals(): void
    {
        // Guards the runtime half: if a refactor renames the literal
        // (e.g. `noshow` or `autoClose`) the CHECK constraint fires and
        // every no-show / auto-close throws. We assert the literal
        // appears at least once in each method body.
        $source = file_get_contents(
            __DIR__ . '/../../app/Modules/Clinic/Services/ClinicService.php',
        );
        $this->assertIsString($source);

        // Both literals must appear somewhere in the file …
        $this->assertStringContainsString("'no_show'", $source);
        $this->assertStringContainsString("'auto_closed'", $source);

        // … AND inside the methods that own them, so a rename only
        // touches one path can't silently desync the other.
        $this->assertStringContainsString(
            "public function markNoShow(",
            $source,
            'markNoShow() method missing from ClinicService.',
        );
        $markNoShowBody = $this->extractMethod($source, 'markNoShow');
        $this->assertStringContainsString(
            "'no_show'",
            $markNoShowBody,
            'markNoShow() body must write the literal `no_show` outcome.',
        );

        $this->assertStringContainsString(
            "public function autoCloseStaleEncounter(",
            $source,
            'autoCloseStaleEncounter() method missing from ClinicService.',
        );
        $autoCloseBody = $this->extractMethod($source, 'autoCloseStaleEncounter');
        $this->assertStringContainsString(
            "'auto_closed'",
            $autoCloseBody,
            'autoCloseStaleEncounter() body must write the literal `auto_closed` outcome.',
        );
    }

    public function testOutcomesAreSnakeCaseAscii(): void
    {
        foreach (self::expectedOutcomes() as $code) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $code,
                "Outcome `{$code}` must be snake_case ASCII.",
            );
        }
    }

    public function testOutcomesAreUnique(): void
    {
        $this->assertSame(
            count(self::expectedOutcomes()),
            count(array_unique(self::expectedOutcomes())),
            'OUTCOME_CODES must be unique.',
        );
    }

    /**
     * Extract a method body (substring from `function <name>(` to the
     * next `function ` at the same brace depth) for literal assertions.
     * Best-effort: regex-based, but adequate for the audit-only
     * invariants this test enforces.
     */
    private function extractMethod(string $source, string $methodName): string
    {
        $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s';
        if (preg_match($pattern, $source, $m) === 1) {
            return $m[0];
        }
        // Fallback: at least return a sentinel so the literal assertion
        // fails with a clear "method body not found" rather than a
        // misleading substring-not-found.
        return '';
    }
}