<?php

declare(strict_types=1);

namespace App\Services\FuMis;

/**
 * HttpTransport — the single seam between FuMisClient and the framework.
 *
 * The unit suite is framework-free (no kernel, no curl extension
 * assumptions), so FuMisClient depends on this interface instead of
 * CodeIgniter's CURLRequest. Production binds CurlTransport; tests bind
 * an in-memory double that returns canned bodies or throws.
 */
interface HttpTransport
{
    /**
     * Perform a request. Implementations return the decoded response:
     * non-2xx and transport failures must throw FuMisException.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body JSON body, null for none
     * @return array<string, mixed> Decoded JSON response body
     * @throws FuMisException
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $body = null,
        int $timeoutSeconds = 10,
    ): array;
}
