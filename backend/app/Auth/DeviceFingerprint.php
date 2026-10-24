<?php

declare(strict_types=1);

namespace App\Auth;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * DeviceFingerprint — coarse, privacy-preserving provenance for a refresh
 * session (2026-10 audit G-5).
 *
 * Both values are deliberately coarse:
 *   - user_agent_hash: first 16 hex of SHA-256 over the raw User-Agent —
 *     enough to tell "Windows Chrome" from "iPhone Safari" apart, useless
 *     for fingerprinting a person across sites.
 *   - ip_subnet: IPv4 /24 or IPv6 /64 — the network, never the host.
 *
 * They populate the (previously unused) auth_refresh_tokens columns so the
 * session inventory can answer "which devices, last seen when" without
 * storing anything close to PII.
 */
final class DeviceFingerprint
{
    private function __construct(
        public readonly string $userAgentHash,
        public readonly string $ipSubnet,
    ) {
    }

    public static function fromRequest(IncomingRequest $request): self
    {
        $ua = (string) $request->getUserAgent()->getAgentString();

        return new self(
            substr(hash('sha256', $ua), 0, 16),
            self::subnetOf($request->getIPAddress()),
        );
    }

    private static function subnetOf(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return 'unknown';
        }
        if (strlen($packed) === 4) {
            return inet_ntop($packed & "\xff\xff\xff\x00") . '/24';
        }
        // IPv6 /64: zero the low 64 bits.
        return inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8)) . '/64';
    }
}
