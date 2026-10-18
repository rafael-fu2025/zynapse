<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\FuMis\MisSyncJobService;
use PHPUnit\Framework\TestCase;

final class MisSyncJobTest extends TestCase
{
    public function testEmptyKindsAreAnObjectAndInternalOwnershipIsRedacted(): void
    {
        $dto = MisSyncJobService::dto(['id' => '4', 'tenant_id' => '2', 'kind' => 'all', 'dry_run' => '0', 'status' => 'queued', 'kinds_json' => '{}', 'created_at' => '2026-10-13 00:00:00', 'owner_token' => 'secret', 'actor_user_id' => 9]);
        $this->assertSame(4, $dto['id']);
        $this->assertSame(2, $dto['tenant_id']);
        $this->assertFalse($dto['dry_run']);
        $this->assertInstanceOf(\stdClass::class, $dto['kinds']);
        $this->assertSame('{}', json_encode($dto['kinds']));
        $this->assertNull($dto['error']);
        $this->assertArrayNotHasKey('owner_token', $dto);
        $this->assertArrayNotHasKey('actor_user_id', $dto);
    }

    public function testAdmissionBoundsRejectInvalidOptions(): void
    {
        $this->expectException(ApiException::class);
        MisSyncJobService::validate('student', 0, 1000);
    }

    public function testAdmissionAcceptsBoundaryOptions(): void
    {
        MisSyncJobService::validate('all', 100, 10000);
        $this->addToAssertionCount(1);
    }
}
