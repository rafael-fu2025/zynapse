<?php

declare(strict_types=1);

namespace App\Filters;

use App\Auth\CurrentUser;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * ApiAuthFilter — extracts and validates JWT, populates CurrentUser.
 *
 * Public routes (auth/login, auth/refresh, health) MUST be excluded in
 * `Filters.php` — this filter expects a valid Bearer token.
 */
final class ApiAuthFilter implements FilterInterface
{
    /**
     * @return RequestInterface|ResponseInterface Returning a Response
     *         short-circuits the request with 401.
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $auth = (string) $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m) !== 1) {
            return $this->reject();
        }

        try {
            $payload = Services::jwt()->verify($m[1]);
        } catch (\Throwable) {
            return $this->reject();
        }

        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId < 1) {
            return $this->reject();
        }

        // Hydrate the request-scoped CurrentUser.
        CurrentUser::bind($userId);

        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        return $response;
    }

    private function reject(): ResponseInterface
    {
        $response = Services::response()->setStatusCode(401);
        $response = ApiExceptionFilter::fromThrowable(
            \App\Exceptions\ApiException::unauthorized(),
            $response,
        );
        // Tag the response with a specific challenge hint.
        $response->setHeader('WWW-Authenticate', 'Bearer realm="synapse"');
        return $response;
    }
}