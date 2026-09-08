<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * FuMisInvalidCredentialsException — the MIS API rejected the
 * identifier/password in BOTH namespaces (student and employee login
 * both returned 401). Maps to HTTP 401 with the same user-facing
 * semantics as local `auth.credentials_invalid`.
 *
 * The attempted identifier is intentionally NOT carried on the
 * exception (privacy directive: identifiers are never logged or
 * audited in plaintext).
 */
final class FuMisInvalidCredentialsException extends ApiException
{
    public function __construct()
    {
        parent::__construct(ApiErrorCode::AUTH_CREDENTIALS_INVALID, 401);
    }
}
