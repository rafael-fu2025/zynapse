<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Pagination\KeysetPaginator;
use PHPUnit\Framework\TestCase;

final class KeysetPaginatorTest extends TestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        $cursor = KeysetPaginator::encode('2026-07-01 10:00:00', 42);

        $decoded = KeysetPaginator::decode($cursor);
        $this->assertNotNull($decoded);
        $this->assertSame('2026-07-01 10:00:00', $decoded['created_at']);
        $this->assertSame(42, $decoded['id']);
    }

    public function testCursorIsUrlSafe(): void
    {
        $cursor = KeysetPaginator::encode('2026-07-01 10:00:00', PHP_INT_MAX);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $cursor);
    }

    public function testDecodeRejectsMalformedInput(): void
    {
        $this->assertNull(KeysetPaginator::decode(null));
        $this->assertNull(KeysetPaginator::decode(''));
        $this->assertNull(KeysetPaginator::decode('%%%not-base64%%%'));
        $this->assertNull(KeysetPaginator::decode(rtrim(strtr(base64_encode('"scalar"'), '+/', '-_'), '=')));
        $this->assertNull(KeysetPaginator::decode(rtrim(strtr(base64_encode('{"x":1}'), '+/', '-_'), '=')));
    }

    public function testFinalizeWithoutSentinelHasNoNextCursor(): void
    {
        $rows = [
            ['id' => 3, 'created_at' => '2026-07-01 10:00:03'],
            ['id' => 2, 'created_at' => '2026-07-01 10:00:02'],
        ];

        $final = KeysetPaginator::finalize($rows, 2);

        $this->assertCount(2, $final['rows']);
        $this->assertNull($final['nextCursor']);
    }

    public function testFinalizeWithSentinelEmitsCursorOfLastVisibleRow(): void
    {
        $rows = [
            ['id' => 3, 'created_at' => '2026-07-01 10:00:03'],
            ['id' => 2, 'created_at' => '2026-07-01 10:00:02'],
            ['id' => 1, 'created_at' => '2026-07-01 10:00:01'], // sentinel
        ];

        $final = KeysetPaginator::finalize($rows, 2);

        $this->assertCount(2, $final['rows']);
        $this->assertNotNull($final['nextCursor']);

        $decoded = KeysetPaginator::decode($final['nextCursor']);
        $this->assertSame(2, $decoded['id']);
        $this->assertSame('2026-07-01 10:00:02', $decoded['created_at']);
    }

    public function testFinalizeSupportsAlternateTimestampKey(): void
    {
        $rows = [
            ['id' => 9, 'commited_at' => '2026-07-01 10:00:09'],
            ['id' => 8, 'commited_at' => '2026-07-01 10:00:08'],
        ];

        $final = KeysetPaginator::finalize($rows, 1, 'commited_at');

        $decoded = KeysetPaginator::decode($final['nextCursor']);
        $this->assertSame(9, $decoded['id']);
        $this->assertSame('2026-07-01 10:00:09', $decoded['created_at']);
    }

    public function testFinalizeStripsAliasPrefixFromTimestampKey(): void
    {
        $rows = [
            ['id' => 7, 'created_at' => '2026-07-01 10:00:07'],
            ['id' => 6, 'created_at' => '2026-07-01 10:00:06'],
        ];

        // Query selected `u.created_at`; result keys drop the alias.
        $final = KeysetPaginator::finalize($rows, 1, 'u.created_at');

        $decoded = KeysetPaginator::decode($final['nextCursor']);
        $this->assertSame(7, $decoded['id']);
    }
}
