<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/** Canonical hard-number stock status and replenishment rules. */
final class StockLevelPolicy
{
    public const IN_STOCK = 'in_stock';
    public const NEEDS_TO_REORDER = 'needs_to_reorder';
    public const OUT_OF_STOCK = 'out_of_stock';

    public static function status(int $onHand, int $threshold): string
    {
        if ($onHand <= 0) {
            return self::OUT_OF_STOCK;
        }

        return $onHand <= $threshold
            ? self::NEEDS_TO_REORDER
            : self::IN_STOCK;
    }

    public static function validTarget(int $threshold, ?int $target): bool
    {
        return $target === null || $target > $threshold;
    }

    /**
     * Proposed reorder quantity. Configured targets win; null retains the
     * legacy twice-threshold behavior during the additive rollout.
     */
    public static function proposedQuantity(int $onHand, int $threshold, ?int $target): int
    {
        if ($target !== null) {
            return max(0, $target - $onHand);
        }

        return max($threshold * 2 - $onHand, $threshold);
    }
}

