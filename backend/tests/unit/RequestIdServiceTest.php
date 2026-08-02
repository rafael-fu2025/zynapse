<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RequestIdService;
use PHPUnit\Framework\TestCase;

final class RequestIdServiceTest extends TestCase
{
    public function testNormalizesBrowserUuidToAuditColumnWidth(): void
    {
        $service = new RequestIdService();

        $id = $service->bind('550E8400-E29B-41D4-A716-446655440000');

        $this->assertSame('550e8400e29b41d4a716446655440000', $id);
        $this->assertSame($id, $service->current());
    }

    public function testGeneratesFallbackForMissingOrMalformedId(): void
    {
        $service = new RequestIdService();

        foreach ([null, '', 'not-a-request-id'] as $candidate) {
            $id = $service->bind($candidate);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        }
    }
}
