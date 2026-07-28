<?php

declare(strict_types=1);

namespace App\Filters;

use App\Exceptions\ApiException;
use App\Http\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Throwable;

/**
 * ApiExceptionFilter — final envelope generator.
 *
 * Converts `ApiException` into the canonical JSON envelope, and converts
 * any other Throwable into a redacted 500 so raw stack traces never leak.
 */
final class ApiExceptionFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): RequestInterface
    {
        return $request;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        if ($response->getStatusCode() < 400) {
            return $response;
        }

        $body = $response->getBody();
        $decoded = $body !== '' ? json_decode($body, true) : null;

        // If a controller has already populated an envelope, leave it alone.
        if (is_array($decoded) && array_key_exists('success', $decoded)) {
            return $response;
        }

        // Map response status -> envelope.
        $envelope = match ($response->getStatusCode()) {
            400 => ApiResponse::failure([['code' => 'request.malformed', 'message' => 'Request payload is malformed.']], 400),
            401 => ApiResponse::failure([['code' => 'auth.unauthorized',  'message' => 'Authentication is required.']],     401),
            403 => ApiResponse::failure([['code' => 'rbac.forbidden',      'message' => 'You do not have permission.']],      403),
            404 => ApiResponse::failure([['code' => 'resource.not_found',  'message' => 'Resource was not found.']],           404),
            405 => ApiResponse::failure([['code' => 'request.method_not_allowed', 'message' => 'Method not allowed.']],      405),
            422 => ApiResponse::failure([['code' => 'request.validation_failed', 'message' => 'Validation failed.']],          422),
            429 => ApiResponse::failure([['code' => 'ratelimit.exceeded',  'message' => 'Too many requests.']],              429),
            default => null,
        };

        if ($envelope === null) {
            // 5xx — redaction applies; never expose trace or message.
            $envelope = ApiResponse::failure([['code' => 'internal.error', 'message' => 'An internal error occurred.']], 500);
        }

        return ApiResponse::apply($response, $envelope);
    }

    /**
     * Static helper — usable from controllers when catching a Throwable.
     */
    public static function fromThrowable(Throwable $t, ResponseInterface $response): ResponseInterface
    {
        $logger = Services::logger();
        // Sanitized: drop message for untrusted exceptions, keep the type.
        $logger->error('API exception: ' . $t::class . ' #' . spl_object_id($t));

        if ($t instanceof ApiException) {
            return ApiResponse::apply($response, [
                'status' => $t->httpStatus,
                'body'   => [
                    'success' => false,
                    'data'    => null,
                    'errors'  => $t->errors ?? [['code' => $t->errorCode, 'message' => $t->getMessage()]],
                    'meta'    => null,
                ],
            ]);
        }

        return ApiResponse::apply($response, ApiResponse::failure(
            [['code' => 'internal.error', 'message' => 'An internal error occurred.']],
            500
        ));
    }
}