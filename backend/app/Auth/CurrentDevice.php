<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * CurrentDevice — request-scoped holder for the authenticated BMG
 * hardware identity.
 *
 * Populated by `DeviceAuthFilter` (which authenticated the static
 * device token against `facilities_bmg_devices`) and read by the
 * device-ingest controllers. Mirrors `CurrentUser`/`CurrentTenant`:
 * a short-lived static binding rather than a global.
 *
 * `forget()` is called by the filter on every request BEFORE binding
 * (defensive no-op on FPM, load-bearing under persistent runtimes where
 * statics would otherwise leak across requests).
 */
final class CurrentDevice
{
    /** @var array<string, mixed>|null */
    private static ?array $device = null;

    /** @param array<string, mixed> $device facilities_bmg_devices row */
    public static function bind(array $device): void
    {
        self::$device = $device;
    }

    public static function forget(): void
    {
        self::$device = null;
    }

    /** @return array<string, mixed>|null facilities_bmg_devices row */
    public static function get(): ?array
    {
        return self::$device;
    }

    /**
     * Throw unless a device is bound (i.e. the route is missing the
     * `device_auth` filter — a configuration bug, not a client error).
     *
     * @return array<string, mixed>
     */
    public static function require(): array
    {
        if (self::$device === null) {
            throw \App\Exceptions\ApiException::unauthorized();
        }
        return self::$device;
    }
}
