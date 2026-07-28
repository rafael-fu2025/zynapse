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

        $response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=UTF-8')
            ->setBody(json_encode($payload['body'], JSON_UNESCAPED_SLASHES))
            ->send();

        exit($exitCode);
    }
}
