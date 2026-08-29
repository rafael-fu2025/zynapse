<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use CodeIgniter\Test\Mock\MockCache;

/**
 * MockCache with working atomic increment/decrement.
 *
 * The framework's MockCache::increment() reads `$this->cache[$key]`
 * without an isset guard, so the FIRST increment of any key — i.e. the
 * first request through ApiRateLimitFilter in every test window — throws
 * `Undefined array key` instead of returning 1. Production handlers
 * (FileHandler, RedisHandler) handle the miss; only the test double is
 * broken. This subclass only adds the missing guard.
 */
final class SafeMockCache extends MockCache
{
    public function increment(string $key, int $offset = 1): bool
    {
        $key  = static::validateKey($key, $this->prefix);
        $data = $this->cache[$key] ?? null;

        if ($data === null) {
            $data = 0;
        } elseif (! is_int($data)) {
            return false;
        }

        return $this->save($key, $data + $offset);
    }

    public function decrement(string $key, int $offset = 1): bool
    {
        $key  = static::validateKey($key, $this->prefix);
        $data = $this->cache[$key] ?? null;

        if ($data === null) {
            $data = 0;
        } elseif (! is_int($data)) {
            return false;
        }

        return $this->save($key, $data - $offset);
    }
}
