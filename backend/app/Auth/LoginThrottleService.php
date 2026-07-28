<?php

declare(strict_types=1);

namespace App\Auth;

use Config\Services;

/**
 * LoginThrottleService — per-account login lockout (Phase 6).
 *
 * Counts consecutive failed logins per (account, window) and locks the
 * account identifier out once the threshold is crossed. Complements the
 * per-IP/token `ApiRateLimitFilter` bucket: the filter caps request
 * volume; this service defeats slow, distributed credential stuffing
 * against a single account.
 *
 * Privacy: the cache key is `hash_hmac('sha256', lowercase(email), JWT_SECRET)`
 * — the attempted email is NEVER stored, logged, or audited (directive).
 *
 * Env:
 *   LOGIN_LOCKOUT_MAX_FAILURES   (default 5)
 *   LOGIN_LOCKOUT_WINDOW_SECONDS (default 900)
 */
final class LoginThrottleService
{
    private function maxFailures(): int
    {
        return max(1, (int) (getenv('LOGIN_LOCKOUT_MAX_FAILURES') ?: 5));
    }

    private function windowSeconds(): int
    {
        return max(60, (int) (getenv('LOGIN_LOCKOUT_WINDOW_SECONDS') ?: 900));
    }

    /**
     * True when the account identifier is currently locked out.
     */
    public function isLocked(string $email): bool
    {
        return (int) (Services::cache()->get($this->key($email)) ?? 0) >= $this->maxFailures();
    }

    /**
     * Record a failed attempt. Returns the updated failure count.
     */
    public function registerFailure(string $email): int
    {
        $cache = Services::cache();
        $key   = $this->key($email);

        $count = (int) ($cache->get($key) ?? 0) + 1;
        // save() (not increment()) so the lockout window slides forward
        // with every failed attempt.
        $cache->save($key, $count, $this->windowSeconds());

        return $count;
    }

    /**
     * Clear the failure counter after a successful login.
     */
    public function clear(string $email): void
    {
        Services::cache()->delete($this->key($email));
    }

    public function retryAfterSeconds(): int
    {
        return $this->windowSeconds();
    }

    private function key(string $email): string
    {
        $secret = (string) (getenv('JWT_SECRET') ?: '');

        return 'login_lock_' . hash_hmac('sha256', strtolower(trim($email)), $secret);
    }
}
