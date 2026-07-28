<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * CurrentUser — request-scoped user holder.
 *
 * Populated by `ApiAuthFilter` and accessed by controllers/services
 * to avoid leaking auth state across requests. Implemented as a
 * short-lived static binding; rest of the app reads through this API
 * rather than touching globals.
 */
final class CurrentUser
{
    private static ?int $id = null;

    public static function bind(int $id): void
    {
        self::$id = $id;
    }

    public static function forget(): void
    {
        self::$id = null;
    }

    public static function id(): ?int
    {
        return self::$id;
    }

    public static function isAuthenticated(): bool
    {
        return self::$id !== null;
    }

    /**
     * Throw unless a user is bound.
     */
    public static function assert(): int
    {
        if (self::$id === null) {
            throw \App\Exceptions\ApiException::unauthorized();
        }
        return self::$id;
    }
}