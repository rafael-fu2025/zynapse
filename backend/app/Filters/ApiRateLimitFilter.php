<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * ApiRateLimitFilter — fixed-window counter per token, falling back
 * to per-IP.
 *
 * Buckets are keyed by `rl_<group>_<token_or_ip>_<minute>` for O(1)
 * checks against the configured cache handler (Redis via Predis in
 * production, file cache in dev — the algorithm is handler-agnostic).
 * Cache keys must avoid CI4's reserved characters `{}()/\@:`.
 *
 * Groups (the filter argument, e.g. `api_ratelimit:auth`):
 *   global — RATELIMIT_GLOBAL_PER_MIN (default 600), all `api/*` routes.
 *   auth   — RATELIMIT_AUTH_PER_MIN   (default 30), login/refresh only.
 */
final class ApiRateLimitFilter implements FilterInterface
{
    private const WINDOW_SECONDS = 60;

    /**
     * @return RequestInterface|ResponseInterface Returning a Response
     *         short-circuits the request with 429.
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $group = is_array($arguments) && $arguments !== [] ? (string) $arguments[0] : 'global';

        $key     = $this->bucketKey($group, $request);
        $limit   = $this->limitFor($group);
        $allowed = (int) Services::cache()->increment($key, 1);

        if ($allowed > $limit) {
            $response = Services::response()->setStatusCode(429);
            $response->setHeader('Retry-After', (string) self::WINDOW_SECONDS);

            return ApiExceptionFilter::fromThrowable(
                \App\Exceptions\ApiException::rateLimited(),
                $response,
            );
        }

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return $response;
    }

    private function limitFor(string $group): int
    {
        return match ($group) {
            'auth'  => (int) (getenv('RATELIMIT_AUTH_PER_MIN') ?: 30),
            default => (int) (getenv('RATELIMIT_GLOBAL_PER_MIN') ?: 600),
        };
    }

    private function bucketKey(string $group, RequestInterface $request): string
    {
        $tokenId = $this->extractTokenId($request);
        $subject = $tokenId !== '' ? $tokenId : ($request->getIPAddress() ?: 'unknown');
        // IPv6 addresses contain `:` — reserved by CI4 cache keys.
        $subject = (string) preg_replace('/[^a-zA-Z0-9._-]/', '-', $subject);

        return sprintf('rl_%s_%s_%d', $group, $subject, (int) floor(microtime(true) / self::WINDOW_SECONDS));
    }

    private function extractTokenId(RequestInterface $request): string
    {
        $auth = (string) $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return substr(hash('sha256', $m[1]), 0, 16);
        }
        return '';
    }
}