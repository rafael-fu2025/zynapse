<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * ApiException — domain exception that carries a stable error code and
 * optional field-level context. The exception filter (`ApiExceptionFilter`)
 * translates these into the canonical JSON envelope.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<int, array{code:string,message:string,field?:string,details?:array}>|null $errors
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus = 400,
        public readonly ?array $errors = null,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, 0, $previous);
    }

    public static function unauthorized(string $code = ApiErrorCode::AUTH_UNAUTHORIZED): self
    {
        return new self($code, 401);
    }

    public static function forbidden(string $code = ApiErrorCode::RBAC_FORBIDDEN): self
    {
        return new self($code, 403);
    }

    public static function notFound(string $code = ApiErrorCode::RESOURCE_NOT_FOUND): self
    {
        return new self($code, ApiErrorCode::defaultStatus($code));
    }

    public static function conflict(string $code = ApiErrorCode::RESOURCE_CONFLICT, string $message = ''): self
    {
        return new self($code, 409, null, $message);
    }

    public static function validationFailure(array $errors): self
    {
        return new self(ApiErrorCode::REQUEST_VALIDATION_FAILED, 422, $errors);
    }

    public static function rateLimited(string $code = ApiErrorCode::RATELIMIT_EXCEEDED): self
    {
        return new self($code, 429);
    }
}