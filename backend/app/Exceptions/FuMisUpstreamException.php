<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * FuMisUpstreamException — the university MIS API is unreachable or
 * erroring (timeout, connection refused, upstream 5xx, malformed
 * response). Maps to HTTP 502/503 and error code `auth.mis_unavailable`.
 */
final class FuMisUpstreamException extends ApiException
{
    public function __construct(string $errorCode = 'auth.mis_unavailable', int $status = 503)
    {
        parent::__construct($errorCode, $status);
    }
}
