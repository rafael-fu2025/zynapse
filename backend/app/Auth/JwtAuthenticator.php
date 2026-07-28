<?php

declare(strict_types=1);

namespace App\Auth;

use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Result;

/**
 * JwtAuthenticator — Shield-compatible authenticator stub.
 *
 * Real JWT validation is performed by `JwtService` (invoked from the
 * `api_auth` route filter). This class exists only so that Shield's
 * provider can wire `auth()->user()` semantics consistently for API
 * requests, allowing the rest of the codebase to use Shield idioms.
 */
final class JwtAuthenticator extends Session
{
    public function check(): bool
    {
        // Authentication is assumed resolved by the route filter;
        // defer to the bound CurrentUser subject.
        return CurrentUser::id() !== null;
    }

    /**
     * This authenticator does not parse credentials — token issuance
     * lives in the controllers and uses JwtService. We always return
     * a failed Result and rely on `JwtService` for sign/verify.
     */
    public function attempt(array $credentials): Result
    {
        return new Result(false, null, 'JwtAuthenticator does not handle credentials.');
    }
}