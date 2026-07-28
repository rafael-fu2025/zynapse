<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Export\CsvWriter;
use PHPUnit\Framework\TestCase;

final class CsvWriterRedactTest extends TestCase
{
    public function testRedactsSensitiveKeysCaseInsensitively(): void
    {
        $out = CsvWriter::redact([
            'password'      => 'hunter2',
            'Refresh_Token' => 'abc',
            'TOKEN'         => 'xyz',
            'outcome'       => 'success',
        ]);

        $this->assertSame('<redacted>', $out['password']);
        $this->assertSame('<redacted>', $out['Refresh_Token']);
        $this->assertSame('<redacted>', $out['TOKEN']);
        $this->assertSame('success', $out['outcome']);
    }

    public function testRedactsNestedPayloads(): void
    {
        $out = CsvWriter::redact([
            'context' => [
                'qr_secret' => 'raw-secret',
                'deep'      => ['patient_school_id' => '2020-12345', 'reason_code' => 'ok'],
            ],
        ]);

        $this->assertSame('<redacted>', $out['context']['qr_secret']);
        $this->assertSame('<redacted>', $out['context']['deep']['patient_school_id']);
        $this->assertSame('ok', $out['context']['deep']['reason_code']);
    }

    public function testNonSensitivePayloadPassesThroughUnchanged(): void
    {
        $payload = ['action' => 'bmg.start', 'unit_id' => 3, 'flags' => [true, false]];

        $this->assertSame($payload, CsvWriter::redact($payload));
    }

    public function testEveryDeclaredRedactKeyIsRedacted(): void
    {
        $payload = array_fill_keys(CsvWriter::REDACT_KEYS, 'sensitive');

        foreach (CsvWriter::redact($payload) as $key => $value) {
            $this->assertSame('<redacted>', $value, "Key `{$key}` leaked.");
        }
    }
}
