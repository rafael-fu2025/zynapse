<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Analytics\BmgAnalytics;
use PHPUnit\Framework\TestCase;

/**
 * Panel-revision (July 2026) analytics: the weight-ratio-weighted
 * expected composting duration and the slug contract for drum /
 * waste-category codes. Both are pure/stateless, so they are unit
 * tested here without booting the framework or a database.
 */
final class BmgCompostingAnalyticsTest extends TestCase
{
    private BmgAnalytics $a;

    protected function setUp(): void
    {
        $this->a = new BmgAnalytics();
    }

    // ---------------------------------------- weightedExpectedDays

    public function testWeightedExpectedDaysSingleComponent(): void
    {
        $components = [['category_id' => 1, 'weight_kg' => 10.0]];
        $expected   = [1 => ['expected_days' => 30, 'sample_count' => 3]];
        $this->assertSame(30, $this->a->weightedExpectedDays($components, $expected));
    }

    public function testWeightedExpectedDaysWeightsByRatio(): void
    {
        // 3 kg @ 30 days + 1 kg @ 10 days => (3*30 + 1*10) / 4 = 25.
        $components = [
            ['category_id' => 1, 'weight_kg' => 3.0],
            ['category_id' => 2, 'weight_kg' => 1.0],
        ];
        $expected = [
            1 => ['expected_days' => 30, 'sample_count' => 2],
            2 => ['expected_days' => 10, 'sample_count' => 5],
        ];
        $this->assertSame(25, $this->a->weightedExpectedDays($components, $expected));
    }

    public function testWeightedExpectedDaysExcludesCategoriesWithoutData(): void
    {
        // Bones (30d) counts; meat has no data and is excluded, so the
        // weighted average collapses to the bones figure.
        $components = [
            ['category_id' => 1, 'weight_kg' => 5.0],  // bones, has data
            ['category_id' => 2, 'weight_kg' => 5.0],  // meat, no data
        ];
        $expected = [
            1 => ['expected_days' => 30, 'sample_count' => 4],
            2 => ['expected_days' => null, 'sample_count' => 0],
        ];
        $this->assertSame(30, $this->a->weightedExpectedDays($components, $expected));
    }

    public function testWeightedExpectedDaysReturnsNullWhenNoComponentHasData(): void
    {
        $components = [['category_id' => 9, 'weight_kg' => 4.0]];
        $expected   = [9 => ['expected_days' => null, 'sample_count' => 0]];
        $this->assertNull($this->a->weightedExpectedDays($components, $expected));
    }

    public function testWeightedExpectedDaysEmptyComposition(): void
    {
        $this->assertNull($this->a->weightedExpectedDays([], []));
    }

    public function testWeightedExpectedDaysRoundsToNearestDay(): void
    {
        // 1 kg @ 20 + 1 kg @ 25 = 22.5 => rounds to 23 (round half up).
        $components = [
            ['category_id' => 1, 'weight_kg' => 1.0],
            ['category_id' => 2, 'weight_kg' => 1.0],
        ];
        $expected = [
            1 => ['expected_days' => 20],
            2 => ['expected_days' => 25],
        ];
        $this->assertSame(23, $this->a->weightedExpectedDays($components, $expected));
    }

    // ---------------------------------------------------- slug rules

    public function testNormalizeSlugLowercasesAndHyphenates(): void
    {
        $this->assertSame('drum-01', $this->a->normalizeSlug('DRUM 01'));
        $this->assertSame('food-waste-meat', $this->a->normalizeSlug('Food Waste (Meat)'));
        $this->assertSame('drum-01', $this->a->normalizeSlug('  drum_01  '));
        $this->assertSame('drum-01', $this->a->normalizeSlug('drum---01'));
    }

    public function testIsValidSlugAcceptsCanonicalForm(): void
    {
        $this->assertTrue($this->a->isValidSlug('drum-01'));
        $this->assertTrue($this->a->isValidSlug('bones'));
        $this->assertTrue($this->a->isValidSlug('food-waste-meat'));
    }

    public function testIsValidSlugRejectsNonCanonicalForm(): void
    {
        $this->assertFalse($this->a->isValidSlug(''));
        $this->assertFalse($this->a->isValidSlug('DRUM-01'));      // uppercase
        $this->assertFalse($this->a->isValidSlug('drum_01'));      // underscore
        $this->assertFalse($this->a->isValidSlug('-drum'));        // leading hyphen
        $this->assertFalse($this->a->isValidSlug('drum-'));        // trailing hyphen
        $this->assertFalse($this->a->isValidSlug('drum--01'));     // double hyphen
        $this->assertFalse($this->a->isValidSlug('drum 01'));      // space
    }

    public function testNormalizeThenValidateRoundTrips(): void
    {
        foreach (['Drum 01', 'FOOD/YARD', 'Bones & Meat', 'rice!!!'] as $raw) {
            $slug = $this->a->normalizeSlug($raw);
            $this->assertTrue($this->a->isValidSlug($slug), "normalizeSlug('{$raw}') => '{$slug}' must be valid");
        }
    }
}
