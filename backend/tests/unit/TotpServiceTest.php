<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Totp\TotpService;
use PHPUnit\Framework\TestCase;

/**
 * TotpService against the published reference vectors — the only honest
 * way to prove a hand-rolled TOTP: RFC 4226 Appendix D (HOTP-SHA1) and
 * RFC 6238 Appendix B (TOTP timestamps, SHA1 branch), reduced from 8 to
 * 6 digits via the same modulo the service uses.
 */
final class TotpServiceTest extends TestCase
{
    private const RFC_SECRET_B32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"

    private TotpService $totp;

    protected function setUp(): void
    {
        $this->totp = new TotpService();
    }

    /** RFC 4226 Appendix D: HOTP(secret, count) for counts 0..9. */
    public function testHotpMatchesRfc4226AppendixD(): void
    {
        $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];

        foreach ($expected as $count => $code) {
            $this->assertSame($code, $this->totp->codeAt(self::RFC_SECRET_B32, $count), "HOTP count {$count}");
        }
    }

    /** RFC 6238 Appendix B (SHA1), 6-digit reduction. */
    public function testTotpMatchesRfc6238AppendixB(): void
    {
        $vectors = [
            59 => '287082',
            1111111109 => '081804',
            1234567890 => '005924',
            2000000000 => '279037',
        ];

        foreach ($vectors as $unixTime => $code) {
            $step = intdiv($unixTime, TotpService::STEP_SECONDS);
            $this->assertSame($code, $this->totp->codeAt(self::RFC_SECRET_B32, $step), "TOTP at T={$unixTime}");
        }
    }

    public function testVerifyAcceptsCurrentStepCode(): void
    {
        $now = 1111111109 - 1111111109 % TotpService::STEP_SECONDS + 5; // inside the step
        $code = $this->totp->codeAt(self::RFC_SECRET_B32, intdiv(1111111109, TotpService::STEP_SECONDS));

        $accepted = $this->totp->verify(self::RFC_SECRET_B32, $code, null, $now);
        $this->assertSame(intdiv(1111111109, TotpService::STEP_SECONDS), $accepted);
    }

    public function testVerifyAcceptsCodesWithinTheDriftWindow(): void
    {
        $step = intdiv(1111111109, TotpService::STEP_SECONDS);
        $previousStepCode = $this->totp->codeAt(self::RFC_SECRET_B32, $step - 1);

        // $now is inside step+0; a code from step-1 is within ±1 window.
        $now = $step * TotpService::STEP_SECONDS + 5;
        $this->assertSame($step - 1, $this->totp->verify(self::RFC_SECRET_B32, $previousStepCode, null, $now));
    }

    public function testVerifyRejectsReplayedTimestep(): void
    {
        $step = intdiv(1111111109, TotpService::STEP_SECONDS);
        $code = $this->totp->codeAt(self::RFC_SECRET_B32, $step);
        $now = $step * TotpService::STEP_SECONDS + 5;

        // First use: accepted.
        $this->assertSame($step, $this->totp->verify(self::RFC_SECRET_B32, $code, $step - 5, $now));
        // Replay of the same code once its step is recorded: rejected.
        $this->assertNull($this->totp->verify(self::RFC_SECRET_B32, $code, $step, $now));
        // A code from an ALREADY-PAST step is rejected outright.
        $oldCode = $this->totp->codeAt(self::RFC_SECRET_B32, $step - 1);
        $this->assertNull($this->totp->verify(self::RFC_SECRET_B32, $oldCode, $step, $now));
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $this->assertNull($this->totp->verify(self::RFC_SECRET_B32, '12345'));
        $this->assertNull($this->totp->verify(self::RFC_SECRET_B32, 'abcdef'));
        $this->assertNull($this->totp->verify(self::RFC_SECRET_B32, '1234567'));
        $this->assertNull($this->totp->verify('SHORT', '123456'));
    }

    public function testGeneratedSecretIsBase32AndRoundTrips(): void
    {
        $secret = $this->totp->generateSecret();
        $this->assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $secret);
        $this->assertSame('12345678901234567890', TotpService::base32Decode(self::RFC_SECRET_B32));
        $this->assertSame(self::RFC_SECRET_B32, TotpService::base32Encode('12345678901234567890'));
    }

    public function testProvisioningUriIsWellFormed(): void
    {
        $uri = $this->totp->provisioningUri(self::RFC_SECRET_B32, 'admin@synapse.dev');
        // Canonical Key-URI label: "Issuer:account" with the account part
        // percent-encoded; the separator colon itself stays literal.
        $this->assertStringStartsWith('otpauth://totp/Synapse:admin%40synapse.dev?', $uri);
        $this->assertStringContainsString('secret=' . self::RFC_SECRET_B32, $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
