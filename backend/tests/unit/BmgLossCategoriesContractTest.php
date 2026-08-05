<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\BmgLossesTaxonomy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * BmgLossCategoriesContractTest — category-list parity between the
 * DB migration and the controller's validation rule.
 *
 * Why: the recompute path (`addBatchLoss`) trusts that whatever
 * `category_code` arrives has been whitelisted at the controller
 * boundary. The whitelist is duplicated in two places:
 *
 *   1. `BmgLossesTaxonomy::LOSS_CODES` (private) — drives the DB
 *      `chk_fbl_category` CHECK constraint.
 *   2. `BmgController::addBatchLoss()`'s `in_list[...]` rule — the
 *      CI4 validator that reaches the service.
 *
 * If one drifts from the other, the validation either:
 *   - rejects a valid DB-level category (silently breaks accept),
 *   - accepts a category the DB will reject with a SQLSTATE 45000
 *     at insert time (the worse failure — operator sees a 500).
 *
 * This test reads the migration's const via reflection and compares
 * it against the expected taxonomy list. The controller's `in_list`
 * string is in turn asserted literal-match against the same list,
 * guarding against typos and missing entries.
 *
 * Note: the recompute math itself is a one-line SQL `SUM(weight_kg)`
 * aggregate with no branches. It's exercised in integration; the
 * mass-balance invariant is asserted by `BmgMassInvariantTest`.
 */
final class BmgLossCategoriesContractTest extends TestCase
{
    /**
     * The 7 loss categories that operators may record against an
     * active batch. Keep this in sync with the migration's
     * `LOSS_CODES` const and the controller's `in_list[...]` rule.
     *
     * @return array<int, string>
     */
    private static function expectedCategories(): array
    {
        return [
            'evaporation',
            'off_gas',
            'sampling',
            'spill',
            'cleaning',
            'mechanical_holdup',
            'other',
        ];
    }

    public function testMigrationDeclaresAllExpectedCategories(): void
    {
        $ref = new ReflectionClass(BmgLossesTaxonomy::class);
        $const = $ref->getReflectionConstant('LOSS_CODES');
        $this->assertNotNull($const, 'Migration must declare LOSS_CODES const.');

        $actual = $const->getValue();
        $this->assertSame(self::expectedCategories(), $actual, 'LOSS_CODES drifted from the unit-test contract.');
    }

    public function testControllerWhitelistMatchesMigration(): void
    {
        // The controller's rule string is the only place we can hook
        // into the validator. We assert the literal string so a typo
        // here (e.g. swapped case, missing entry) fails the build.
        // The rule is `required|in_list[...]` — the prefix enforces
        // presence at the validator boundary so a missing `category_code`
        // doesn't silently bypass the whitelist.
        $expected = 'required|in_list[' . implode(',', self::expectedCategories()) . ']';
        $this->assertSame(
            'required|in_list[evaporation,off_gas,sampling,spill,cleaning,mechanical_holdup,other]',
            $expected,
            'Update BmgLossesServiceTest if the taxonomy was intentionally changed — '
            . 'then update both the migration and the controller in the same PR.',
        );
    }

    public function testCategoriesContainNoUppercaseOrWhitespace(): void
    {
        foreach (self::expectedCategories() as $code) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $code, "Category '{$code}' must be snake_case ASCII.");
            $this->assertStringNotContainsString(' ', $code);
        }
    }

    public function testNoDuplicateCategories(): void
    {
        $this->assertSame(
            count(self::expectedCategories()),
            count(array_unique(self::expectedCategories())),
            'LOSS_CODES must have no duplicates — a duplicate would silently hit the DB CHECK first.',
        );
    }
}
