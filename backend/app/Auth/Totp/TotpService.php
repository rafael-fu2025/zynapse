<?php

declare(strict_types=1);

namespace App\Auth\Totp;

/**
 * TotpService — RFC 6238 time-based one-time passwords (RFC 4226 HOTP
 * underneath), implemented locally so MFA needs no new dependency
 * (AGENTS.md: no dependencies without asking).
 *
 *   - HMAC-SHA1, 6 digits, 30-second step, ±1 step drift window.
 *   - Replay rejection: verify() refuses any timestep <= the caller's
 *     stored last-used timestep (a code is single-use).
 *   - Base32 (RFC 4648, no padding) for authenticator-app provisioning.
 *
 * Correctness is pinned by tests/Unit/TotpServiceTest against the RFC 4226
 * Appendix D reference vectors and RFC 6238 Appendix B timestamps.
 */
final class TotpService
{
    public const STEP_SECONDS = 30;
    private const DRIFT_STEPS = 1;
    private const DIGITS = 6;

    /** Generate a fresh 160-bit (32-char Base32) secret. */
    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $accountName, string $issuer = 'Synapse'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($accountName)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::STEP_SECONDS;
    }

    /**
     * Verify a code against the secret. Null $lastUsedTimestep means
     * "never used"; a non-null value additionally enforces replay
     * rejection (only strictly newer timesteps count) and returns the
     * accepted timestep so the caller can persist it.
     *
     * @return int|null the accepted timestep, or null on failure
     */
    public function verify(string $secret, string $code, ?int $lastUsedTimestep = null, ?int $now = null): ?int
    {
        $code = trim($code);
        if (preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            return null;
        }
        if (! self::base32Valid($secret)) {
            return null;
        }

        $now ??= time();
        $current = intdiv($now, self::STEP_SECONDS);

        for ($drift = -self::DRIFT_STEPS; $drift <= self::DRIFT_STEPS; $drift++) {
            $step = $current + $drift;
            if ($step < 0) {
                continue;
            }
            if ($lastUsedTimestep !== null && $step <= $lastUsedTimestep) {
                continue; // replay
            }
            if (hash_equals($this->codeAt($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function codeAt(string $secret, int $timestep, ?int $now = null): string
    {
        // Test seam: allow deterministic "now" for vectors.
        unset($now);
        $counter = pack('J', $timestep);

        return $this->hotp(self::base32Decode($secret), $counter);
    }

    /** RFC 4226 dynamic truncation, 6 digits. */
    private function hotp(string $key, string $counter): string
    {
        // SHA-1 here is REQUIRED by RFC 6238 for authenticator-app
        // interoperability (the otpauth URI advertises algorithm=SHA1).
        // HMAC-SHA1 is a keyed construction — it is NOT affected by
        // SHA-1's collision weaknesses, and the secret is a 160-bit
        // random key, not a password.
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        $bits = 0;
        $val = 0;
        foreach (str_split($bytes) as $byte) {
            $val = ($val << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= $alphabet[($val >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $out .= $alphabet[($val << (5 - $bits)) & 0x1F];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        if (! self::base32Valid($encoded)) {
            throw new \InvalidArgumentException('TOTP secret is not valid Base32.');
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = 0;
        $val = 0;
        $out = '';
        foreach (str_split(strtoupper($encoded)) as $char) {
            $val = ($val << 5) | strpos($alphabet, $char);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($val >> $bits) & 0xFF);
            }
        }

        return $out;
    }

    public static function base32Valid(string $secret): bool
    {
        return preg_match('/\A[A-Z2-7]+\z/i', $secret) === 1 && strlen($secret) >= 16;
    }
}
