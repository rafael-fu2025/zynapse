<?php

declare(strict_types=1);

namespace App\Auth;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Absolute UTC instants, not clinic-day buckets. */
final class RefreshSessionPolicy
{
    public function maxAgeSeconds(): int
    {
        return $this->positiveSetting('JWT_SESSION_MAX_AGE_SECONDS', 43200);
    }

    public function graceSeconds(): int
    {
        $value = getenv('JWT_REFRESH_GRACE_SECONDS');
        if ($value === false || $value === '') {
            return 5;
        }
        if (! ctype_digit($value) || (int) $value > 5) {
            throw new RuntimeException('JWT_REFRESH_GRACE_SECONDS must be between 0 and 5.');
        }
        return (int) $value;
    }

    public function refreshExpiry(int $now, int $deadline): int
    {
        $this->assertLive($deadline, $now);
        return min($deadline, $now + $this->positiveSetting('JWT_REFRESH_TTL_SECONDS', 2592000));
    }

    public function assertLive(int $deadline, int $now): void
    {
        if ($deadline <= $now) {
            throw new RuntimeException('Refresh session has expired.');
        }
    }

    public function timestamp(?string $value): int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value ?? '', new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new RuntimeException('Refresh session deadline is missing or invalid.');
        }
        return $date->getTimestamp();
    }

    private function positiveSetting(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 315360000]]);
        if ($parsed === false) {
            throw new RuntimeException($name . ' must be a positive integer no greater than 315360000.');
        }
        return $parsed;
    }
}
