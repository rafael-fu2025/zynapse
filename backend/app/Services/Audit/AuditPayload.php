<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * AuditPayload — one redaction policy for every audit read surface.
 *
 * The append-only record retains its original forensic payload so the hash
 * chain remains meaningful. JSON detail and CSV export both pass through
 * this policy before data crosses the API boundary.
 */
final class AuditPayload
{
    public const REDACT_KEYS = [
        'password', 'refresh_token', 'access_token', 'authorization',
        'cookie', 'token', 'qr_secret', 'plaintext', 'notes_plaintext',
        'patient_school_id', 'family_id',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function redact(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT_KEYS, true)) {
                $out[$key] = '<redacted>';
                continue;
            }
            $out[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $out;
    }
}
