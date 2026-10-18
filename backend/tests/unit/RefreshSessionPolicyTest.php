<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\RefreshSessionPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RefreshSessionPolicyTest extends TestCase
{
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['JWT_SESSION_MAX_AGE_SECONDS', 'JWT_REFRESH_TTL_SECONDS', 'JWT_REFRESH_GRACE_SECONDS'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testDefaultsAndConfiguredMaximumAge(): void
    {
        $policy = new RefreshSessionPolicy();
        $this->assertSame(43200, $policy->maxAgeSeconds());
        putenv('JWT_SESSION_MAX_AGE_SECONDS=600');
        $this->assertSame(600, $policy->maxAgeSeconds());
    }

    public function testRotationNeverMovesDeadlineAndRespectsShorterTokenTtl(): void
    {
        $policy = new RefreshSessionPolicy();
        $this->assertSame(150, $policy->refreshExpiry(100, 150));
        $this->assertSame(150, $policy->refreshExpiry(140, 150));
        putenv('JWT_REFRESH_TTL_SECONDS=10');
        $this->assertSame(110, $policy->refreshExpiry(100, 150));
    }

    public function testExactDeadlineIsExpired(): void
    {
        $this->expectException(RuntimeException::class);
        (new RefreshSessionPolicy())->refreshExpiry(150, 150);
    }

    public function testGraceCanBeDisabledButCannotBeIncreased(): void
    {
        $policy = new RefreshSessionPolicy();
        $this->assertSame(5, $policy->graceSeconds());
        putenv('JWT_REFRESH_GRACE_SECONDS=0');
        $this->assertSame(0, $policy->graceSeconds());
        putenv('JWT_REFRESH_GRACE_SECONDS=6');
        $this->expectException(RuntimeException::class);
        $policy->graceSeconds();
    }

    public function testInvalidMaximumAgeFailsClosed(): void
    {
        putenv('JWT_SESSION_MAX_AGE_SECONDS=0');
        $this->expectException(RuntimeException::class);
        (new RefreshSessionPolicy())->maxAgeSeconds();
    }

    public function testUtcParsingDoesNotDependOnProcessTimezone(): void
    {
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Manila');
            $this->assertSame(0, (new RefreshSessionPolicy())->timestamp('1970-01-01 00:00:00'));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testMissingDeadlineFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        (new RefreshSessionPolicy())->timestamp(null);
    }
}
