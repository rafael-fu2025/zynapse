<?php

declare(strict_types=1);

namespace App\Filters;

use App\Auth\ApiKeyContext;
use App\Exceptions\ApiException;
use App\Services\CurrentTenant;
use App\Services\External\ApiKeyService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * ApiKeyAuthFilter — authenticates EXTERNAL API traffic (2026-09, D4).
 *
 * Reads `X-Api-Key` (or `Authorization: Bearer syn_…`), resolves the key
 * by its self-identifying prefix, compares hashes in constant time, then
 * binds the request-scoped context: CurrentTenant from the KEY row (a
 * test key IS sandbox-pinned, a live key production-pinned) and
 * ApiKeyContext for scopes/app identity. A per-key fixed-window rate
 * limit runs right after resolution, with X-RateLimit-* headers on every
 * response (documented in /developer/docs).
 *
 * Rejections mirror ApiAuthFilter: build the canonical envelope via
 * ApiExceptionFilter::fromThrowable and return the Response to
 * short-circuit.
 */
final class ApiKeyAuthFilter implements FilterInterface
{
    private const WINDOW_SECONDS = 60;

    /**
     * @return RequestInterface|ResponseInterface Returning a Response
     *         short-circuits the request.
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $raw = $this->presentedKey($request);
        if ($raw === '') {
            return $this->reject(ApiException::unauthorized('auth.api_key_missing'));
        }

        try {
            $resolved = (new ApiKeyService())->verify($raw);
        } catch (ApiException $exception) {
            return $this->reject($exception);
        }

        $key = $resolved['key'];

        // A key IS a tenant-pinned principal: test keys land in the
        // sandbox tenant, live keys in the production tenant (D5).
        CurrentTenant::set((int) $key['tenant_id']);
        ApiKeyContext::bind($key, $resolved['app']);

        // Per-key fixed-window rate limit (mirror of ApiRateLimitFilter's
        // cache-bucket idiom; SafeMockCache implements increment in tests).
        $limit  = max(1, (int) $key['rate_limit_per_min']);
        $bucket = sprintf('rlkey_%d_%d', (int) $key['id'], (int) floor(microtime(true) / self::WINDOW_SECONDS));
        $cache  = Services::cache();
        // increment() initializes the bucket on first hit; the counter is
        // read back so both production handlers and the test double agree.
        $cache->increment($bucket, 1);
        $count   = (int) $cache->get($bucket);
        $resetAt = (int) (floor(microtime(true) / self::WINDOW_SECONDS) * self::WINDOW_SECONDS) + self::WINDOW_SECONDS;

        Services::response()->setHeader('X-RateLimit-Limit', (string) $limit);
        Services::response()->setHeader('X-RateLimit-Remaining', (string) max(0, $limit - $count));
        Services::response()->setHeader('X-RateLimit-Reset', (string) $resetAt);

        if ($count > $limit) {
            $response = Services::response()->setStatusCode(429);
            $response->setHeader('Retry-After', (string) self::WINDOW_SECONDS);

            return ApiExceptionFilter::fromThrowable(ApiException::rateLimited(), $response);
        }

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        if (ApiKeyContext::bound()) {
            try {
                (new ApiKeyService())->markUsed(ApiKeyContext::keyId());
            } finally {
                ApiKeyContext::reset();
            }
        }
        return $response;
    }

    private function presentedKey(RequestInterface $request): string
    {
        $header = trim((string) $request->getHeaderLine('X-Api-Key'));
        if ($header !== '') {
            return $header;
        }
        $auth = (string) $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(syn_.+)$/i', $auth, $m) === 1) {
            return trim($m[1]);
        }
        return '';
    }

    private function reject(ApiException $exception): ResponseInterface
    {
        $response = Services::response()->setStatusCode($exception->httpStatus);
        $response = ApiExceptionFilter::fromThrowable($exception, $response);
        if ($exception->httpStatus === 401) {
            $response->setHeader('WWW-Authenticate', 'ApiKey realm="synapse"');
        }
        return $response;
    }
}
