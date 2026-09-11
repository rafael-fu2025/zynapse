<?php

declare(strict_types=1);

namespace App\Services\FuMis;

use CodeIgniter\HTTP\CURLRequest;
use Config\Services;

/**
 * CurlTransport — production HttpTransport over CI4's CURLRequest.
 *
 * Wraps every transport-level failure (timeout, DNS, connection) and
 * non-2xx response into FuMisException so FuMisClient stays free of
 * framework concerns. Never logs request/response bodies — they carry
 * credentials and person data.
 */
final class CurlTransport implements HttpTransport
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?array $body = null,
        int $timeoutSeconds = 10,
    ): array {
        $this->assertSafeUrl($url);

        $options = [
            'timeout'         => $timeoutSeconds,
            'connect_timeout' => $timeoutSeconds,
            'http_errors'     => false,
            // The MIS API is campus-only; do not let a redirect chain
            // carry credentials off-network.
            'allow_redirects' => false,
        ];

        $requestHeaders = $headers;
        if ($body !== null) {
            $requestHeaders['Content-Type'] = 'application/json';
        }

        /** @var CURLRequest $curl */
        $curl = Services::curlrequest($options);

        try {
            $response = $curl->request($method, $url, [
                'headers' => $requestHeaders,
                'body'    => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            ]);
        } catch (\Throwable $t) {
            // Transport failure (timeout / connect / TLS) — status 0.
            throw new FuMisException('fumis.transport', 0, null, $t->getMessage());
        }

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($raw, true);
            $upstreamBody = is_array($decoded) ? $decoded : null;

            // Upstream error code — the MIS API error shape is not
            // documented; probe common keys, fall back to HTTP reason.
            $code = '';
            if ($upstreamBody !== null) {
                foreach (['error', 'code', 'message', 'status'] as $probe) {
                    $candidate = $upstreamBody[$probe] ?? null;
                    if (is_string($candidate) && $candidate !== '') {
                        $code = $candidate;
                        break;
                    }
                }
            }

            throw new FuMisException(
                $code !== '' ? $code : 'fumis.http_' . $status,
                $status,
                $upstreamBody,
            );
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new FuMisException('fumis.invalid_response', $status);
        }

        return $decoded;
    }

    /**
     * Outbound URL safety validation (SSRF defense).
     *
     * Only http and https schemes are permitted. Rejects localhost,
     * loopback (127.0.0.0/8, ::1), and cloud link-local metadata.
     * The trusted university host (mis.foundationu.com, or any subdomain
     * of foundationu.com configured in FUMIS_BASE_URL) is permitted even
     * when resolving to a private campus network address (10.x.x.x).
     * Non-university hosts must resolve to public, non-reserved IPs.
     *
     * @throws FuMisException
     */
    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new FuMisException('fumis.invalid_url', 400);
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new FuMisException('fumis.unsupported_scheme', 400);
        }

        $host = strtolower($parts['host']);

        // Obvious loopback and localhost labels.
        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || $host === '127.0.0.1'
            || $host === '::1'
        ) {
            throw new FuMisException('fumis.ssrf_blocked', 403);
        }

        // Link-local cloud metadata (AWS / GCP / Azure 169.254.169.254).
        if (str_starts_with($host, '169.254.')) {
            throw new FuMisException('fumis.ssrf_blocked', 403);
        }

        // Trusted university domains on the campus intranet:
        if ($host === 'foundationu.com' || str_ends_with($host, '.foundationu.com')) {
            return;
        }

        // Check if host is a literal IP.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                throw new FuMisException('fumis.ssrf_blocked', 403);
            }
            return;
        }

        // Resolve DNS and check each returned IP for non-university hosts.
        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            // DNS resolution failure — let curl handle the connection error.
            return;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new FuMisException('fumis.ssrf_blocked', 403);
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
