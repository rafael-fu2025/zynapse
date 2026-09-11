<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use RuntimeException;

/**
 * FuMisException — an upstream failure of the university MIS API.
 *
 * Carries the upstream HTTP status (0 for transport-level failures:
 * timeout, DNS, connection refused — expected off-campus) and an
 * upstream error code when the MIS API returned a structured error.
 * Callers map this to auth.mis_unavailable (5xx/transport) or
 * auth.mis_invalid_credentials (401 upstream).
 */
final class FuMisException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $upstreamBody Decoded error body, when present
     */
    public function __construct(
        public readonly string $upstreamCode,
        public readonly int $upstreamStatus,
        public readonly ?array $upstreamBody = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            'MIS API error [http %d] %s',
            $upstreamStatus,
            $upstreamCode !== '' ? $upstreamCode : 'unspecified',
        ));
    }

    public function isTransportFailure(): bool
    {
        return $this->upstreamStatus === 0;
    }

    public function isInvalidCredentials(): bool
    {
        // The MIS API returns 403 Forbidden with "Account not recognized"
        // when credentials do not match or when querying the wrong
        // namespace (e.g. employee ID on student endpoint).
        if ($this->upstreamStatus === 401 || $this->upstreamStatus === 403) {
            return true;
        }

        $message = (string) ($this->upstreamBody['message'] ?? '');
        return str_contains(strtolower($message), 'not recognized')
            || str_contains(strtolower($message), 'invalid');
    }
}
