<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Business-day helpers. Persistence is STRICTLY UTC (Config\App
 * `$appTimezone`); Asia/Manila is the operational calendar the staff
 * surfaces reason about. Every queue / check-in-trail / process-log
 * day partition must go through these helpers so writer and reader
 * never split again — the 2026-08 audit found kiosk walk-ins landing
 * on the previous business day and vanishing from the staff queue
 * for the 16:00–24:00 UTC window.
 */
final class ManilaDay
{
    public const TZ = 'Asia/Manila';

    /**
     * The Manila calendar date (Y-m-d) for a UTC `Y-m-d H:i:s` value.
     */
    public static function fromUtcSql(string $utcSql): string
    {
        return (new DateTimeImmutable($utcSql, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::TZ))
            ->format('Y-m-d');
    }

    /**
     * The current Manila calendar date (Y-m-d).
     */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    /**
     * Manila's local midnight expressed as a UTC `Y-m-d H:i:s` bound
     * for `scanned_at`/`created_at` range filters.
     */
    public static function startOfDayUtcSql(): string
    {
        return (new DateTimeImmutable('today', new DateTimeZone(self::TZ)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
