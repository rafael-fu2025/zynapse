<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Contract guard for the panel-approved standard queue policy. */
final class QueueFifoContractTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Modules/Clinic/Services/QueueService.php',
        );
        $this->assertIsString($source);
        $this->source = $source;
    }

    public function testCallNextOrdersByAscendingPosition(): void
    {
        $this->assertStringContainsString(
            "ORDER BY `position` ASC FOR UPDATE",
            $this->source,
            'QueueService::callNext must lock and call entries in FIFO position order.',
        );
    }

    public function testCallNextDoesNotOrderByPriority(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/ORDER\s+BY[^;]*(priority|urgent)/i',
            $this->source,
            'Queue order must not contain a priority or urgency bypass.',
        );
    }
}

