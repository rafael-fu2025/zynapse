<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * ApiRequestLoggerFilter — emits a structured access log line.
 *
 * NEVER logs:
 *   - Authorization header (tokens)
 *   - Cookie header (refresh tokens)
 *   - Raw request body (clinical notes, payloads)
 *   - Query string parameters from sensitive endpoints
 */
final class ApiRequestLoggerFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): RequestInterface
    {
        $rid = Services::requestId()->bind((string) $request->getHeaderLine('X-Request-Id'));

        // Surface a per-request id for cross-layer correlation.
        $request->setHeader('X-Request-Id', $rid);

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        $rid = Services::requestId()->current()
            ?? Services::requestId()->bind((string) $request->getHeaderLine('X-Request-Id'));
        $response->setHeader('X-Request-Id', $rid);

        Services::logger()->info(json_encode([
            'rid'      => $rid,
            'method'   => $request->getMethod(),
            'route'    => $request->getUri()->getPath(),
            'status'   => $response->getStatusCode(),
            'query'    => $this->safeQueryParams($request->getUri()->getQuery()),
            'ua_hash'  => substr(hash('sha256', (string) $request->getHeaderLine('User-Agent')), 0, 16),
        ], JSON_UNESCAPED_SLASHES));

        return $response;
    }

    /**
     * Returns the query string with sensitive keys redacted.
     */
    private function safeQueryParams(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $redact = ['token', 'access_token', 'refresh_token', 'code', 'qr', 'qr_secret', 'password'];

        $parts = [];
        foreach (explode('&', $query) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
            if (in_array(strtolower($k), $redact, true)) {
                $parts[] = $k . '=<redacted>';
            } else {
                $parts[] = $kv;
            }
        }

        return implode('&', $parts);
    }
}
