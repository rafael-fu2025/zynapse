<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * ApiKeyContext — request-scoped identity for external API-key calls.
 *
 * Mirrors the static binding pattern of CurrentUser/CurrentTenant:
 * ApiKeyAuthFilter binds the resolved key + app once per request; the
 * external controllers and the sandbox executor read from here. The
 * tenant itself is bound separately via CurrentTenant::set() from the
 * key row — a key IS a tenant-pinned principal.
 *
 * The full secret is never available here — only the stored prefix and
 * last4 (hash-only storage, D4/AC8).
 */
final class ApiKeyContext
{
    /** @var array<string, mixed>|null key row (prefix/last4/env/scopes/…) */
    private static ?array $key = null;

    /** @var array<string, mixed>|null app row */
    private static ?array $app = null;

    /**
     * @param array<string, mixed> $key
     * @param array<string, mixed> $app
     */
    public static function bind(array $key, array $app): void
    {
        self::$key = $key;
        self::$app = $app;
    }

    public static function reset(): void
    {
        self::$key = null;
        self::$app = null;
    }

    public static function bound(): bool
    {
        return self::$key !== null;
    }

    public static function keyId(): int
    {
        return (int) (self::$key['id'] ?? 0);
    }

    public static function appId(): int
    {
        return (int) (self::$app['id'] ?? 0);
    }

    public static function appName(): string
    {
        return (string) (self::$app['name'] ?? '');
    }

    /** 'test' | 'live' */
    public static function env(): string
    {
        return (string) (self::$key['env'] ?? '');
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        $decoded = json_decode((string) (self::$key['scopes'] ?? '[]'), true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** Identifiable prefix (e.g. `syn_live_ab12`) — safe to expose. */
    public static function prefix(): string
    {
        return (string) (self::$key['prefix'] ?? '');
    }

    /** Last 4 characters of the secret — display hint only. */
    public static function last4(): string
    {
        return (string) (self::$key['last4'] ?? '');
    }

    public static function rateLimitPerMin(): int
    {
        return (int) (self::$key['rate_limit_per_min'] ?? 0);
    }

    public static function expiresAt(): ?string
    {
        $value = self::$key['expires_at'] ?? null;
        return $value !== null ? (string) $value : null;
    }
}
