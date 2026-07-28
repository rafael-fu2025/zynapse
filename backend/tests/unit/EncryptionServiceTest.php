<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Crypto\EncryptionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * EncryptionService — AES-256-GCM envelope tests.
 *
 * Uses a subclass that pins `lookupKeyRef()` so no database is needed;
 * key material comes from process env vars set per-test.
 */
final class EncryptionServiceTest extends TestCase
{
    private const KEY_V1 = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const KEY_V2 = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';

    protected function setUp(): void
    {
        putenv('COUNSELLING_KEY=' . self::KEY_V1);
        putenv('COUNSELLING_KEY_VERSION=1');
        putenv('COUNSELLING_KEY_V2');
    }

    protected function tearDown(): void
    {
        putenv('COUNSELLING_KEY');
        putenv('COUNSELLING_KEY_VERSION');
        putenv('COUNSELLING_KEY_V2');
    }

    private function service(): EncryptionService
    {
        return new class () extends EncryptionService {
            protected function lookupKeyRef(int $version): ?string
            {
                return null; // Force env fallback; no DB in unit tests.
            }
        };
    }

    public function testRoundTrip(): void
    {
        $svc = $this->service();
        $env = $svc->encryptField('confidential note');

        $this->assertSame(1, $env['key_version']);
        $this->assertSame(EncryptionService::NONCE_BYTES, strlen($env['nonce']));
        // ciphertext = plaintext length + 16-byte GCM tag.
        $this->assertSame(strlen('confidential note') + EncryptionService::TAG_BYTES, strlen($env['ciphertext']));

        $plain = $svc->decryptField($env['ciphertext'], $env['nonce'], $env['key_version']);
        $this->assertSame('confidential note', $plain);
    }

    public function testTamperedCiphertextFails(): void
    {
        $svc = $this->service();
        $env = $svc->encryptField('confidential note');

        $tampered = $env['ciphertext'];
        $tampered[0] = $tampered[0] === "\x00" ? "\x01" : "\x00";

        $this->expectException(RuntimeException::class);
        $svc->decryptField($tampered, $env['nonce'], $env['key_version']);
    }

    public function testTamperedTagFails(): void
    {
        $svc = $this->service();
        $env = $svc->encryptField('confidential note');

        $tampered = $env['ciphertext'];
        $lastIdx = strlen($tampered) - 1;
        $tampered[$lastIdx] = $tampered[$lastIdx] === "\x00" ? "\x01" : "\x00";

        $this->expectException(RuntimeException::class);
        $svc->decryptField($tampered, $env['nonce'], $env['key_version']);
    }

    public function testCiphertextWithoutTagRejected(): void
    {
        $svc = $this->service();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing GCM tag');
        $svc->decryptField('short', str_repeat("\x00", 12), 1);
    }

    public function testHistoricalKeyVersionResolvesViaEnvFallback(): void
    {
        $svc = $this->service();

        // Encrypt under v1, then rotate: v2 becomes active, v1 historical.
        $env = $svc->encryptField('rotated note');

        putenv('COUNSELLING_KEY_VERSION=2');
        putenv('COUNSELLING_KEY=' . self::KEY_V2);
        putenv('COUNSELLING_KEY_V1=' . self::KEY_V1);

        $svc2 = $this->service();
        $this->assertSame('rotated note', $svc2->decryptField($env['ciphertext'], $env['nonce'], 1));

        // New writes use the active v2 key.
        $env2 = $svc2->encryptField('fresh note');
        $this->assertSame(2, $env2['key_version']);

        putenv('COUNSELLING_KEY_V1');
    }

    public function testMalformedKeyRejected(): void
    {
        putenv('COUNSELLING_KEY=not-hex');

        $this->expectException(RuntimeException::class);
        $this->service()->encryptField('x');
    }
}
