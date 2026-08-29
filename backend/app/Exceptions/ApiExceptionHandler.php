<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\ApiResponse;
use CodeIgniter\Debug\BaseExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * ApiExceptionHandler — renders EVERY uncaught throwable as the
 * canonical JSON envelope (Phase 6 runtime retrofit).
 *
 * Uncaught exceptions never pass through after-filters, so the
 * envelope MUST be produced here, not in `ApiExceptionFilter`.
 * `ApiException` keeps its status + error list; anything else is a
 * redacted 500 — no messages, no traces (directive: never leak).
 */
final class ApiExceptionHandler extends BaseExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        $this->renderToResponse($exception, $response);
        $response->send();

        exit($exitCode);
    }

    /**
     * Map a throwable onto the canonical failure envelope and stage it on
     * the response WITHOUT sending or exiting.
     *
     * `handle()` is the production edge (send + exit, unwritable in a
     * test process); this method carries the actual status/error mapping
     * so the feature suite can render controller exceptions through the
     * exact same rules instead of duplicating them.
     */
    public function renderToResponse(Throwable $exception, ResponseInterface $response): ResponseInterface
    {
        if ($exception instanceof ApiException) {
            $status = $exception->httpStatus;
            $errors = $exception->errors ?? [[
                'code'    => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]];
        } else {
            $status = 500;
            $errors = [['code' => 'internal.error', 'message' => 'An internal error occurred.']];
            log_message('error', 'Unhandled {type} #{id}', [
                'type' => $exception::class,
                'id'   => spl_object_id($exception),
            ]);
        }

        $payload = ApiResponse::failure($errors, $status);

        // setJSON (not setBody) — ResponseTrait::getJSON() re-encodes the
        // body through the JSON formatter unless bodyFormat === 'json',
        // which setBody never sets.
        return $response
            ->setStatusCode($status)
            ->setJSON($payload['body']);
    }
}
