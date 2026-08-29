<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Inventory\StockLevelPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StockLevelPolicyTest extends TestCase
{
    /** @return iterable<string, array{int, int, string}> */
    public static function statuses(): iterable
    {
        yield 'zero is out' => [0, 40, StockLevelPolicy::OUT_OF_STOCK];
        yield 'threshold requires reorder' => [40, 40, StockLevelPolicy::NEEDS_TO_REORDER];
        yield 'positive below threshold requires reorder' => [1, 40, StockLevelPolicy::NEEDS_TO_REORDER];
        yield 'above threshold is in stock' => [41, 40, StockLevelPolicy::IN_STOCK];
    }

    #[DataProvider('statuses')]
    public function testStatus(int $onHand, int $threshold, string $expected): void
    {
        $this->assertSame($expected, StockLevelPolicy::status($onHand, $threshold));
    }

    public function testConfiguredTargetDeterminesProposal(): void
    {
        $this->assertSame(80, StockLevelPolicy::proposedQuantity(40, 40, 120));
        $this->assertSame(120, StockLevelPolicy::proposedQuantity(0, 40, 120));
    }

    public function testLegacyFallbackRemainsCompatible(): void
    {
        $this->assertSame(10, StockLevelPolicy::proposedQuantity(0, 5, null));
    }

    public function testTargetMustExceedThreshold(): void
    {
        $this->assertTrue(StockLevelPolicy::validTarget(40, 120));
        $this->assertTrue(StockLevelPolicy::validTarget(40, null));
        $this->assertFalse(StockLevelPolicy::validTarget(40, 40));
        $this->assertFalse(StockLevelPolicy::validTarget(40, 39));
    }
}

