<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * ApiErrorCode — canonical, exhaustive list of error codes returned in
 * the envelope. Centralized so the front-end can map codes to UX
 * without relying on stringly-typed conditionals.
 *
 * Codes are dotted, lowercase, and categorize by subsystem:
 *   auth.*    — authentication / token problems
 *   rbac.*    — permission denied
 *   resource.*— missing / conflict
 *   request.* — validation / malformed
 *   ratelimit.* — throttle
 *   statemachine.* — invalid lifecycle transitions
 *   session.* — DB session problems
 *   import.*  — bulk import issues
 *   export.*  — bulk export issues
 *   internal.*— 5xx categories
 */
final class ApiErrorCode
{
    public const AUTH_UNAUTHORIZED              = 'auth.unauthorized';
    public const AUTH_REFRESH_MISSING           = 'auth.refresh_missing';
    public const AUTH_REFRESH_INVALID           = 'auth.refresh_invalid_or_replayed';
    public const AUTH_CREDENTIALS_INVALID       = 'auth.credentials_invalid';
    public const AUTH_USER_NOT_FOUND            = 'auth.user_not_found';
    public const AUTH_LOGIN_LOCKED              = 'auth.login_locked';
    public const AUTH_ACCOUNT_DISABLED          = 'auth.account_disabled';
    public const AUTH_PASSWORD_CHANGE_REQUIRED  = 'auth.password_change_required';

    public const RBAC_FORBIDDEN                 = 'rbac.forbidden';
    public const RBAC_PERMISSION_DENIED         = 'rbac.permission_denied';
    public const REFERRAL_TEACHING_REQUIRED     = 'referral.teaching_required';

    public const RESOURCE_NOT_FOUND             = 'resource.not_found';
    public const RESOURCE_CONFLICT              = 'resource.conflict';

    public const REQUEST_VALIDATION_FAILED      = 'request.validation_failed';
    public const REQUEST_MALFORMED              = 'request.malformed';
    public const REQUEST_METHOD_NOT_ALLOWED     = 'request.method_not_allowed';
    public const REQUEST_INVALID                = 'validation.invalid';

    public const RATELIMIT_EXCEEDED             = 'ratelimit.exceeded';

    public const STATEMACHINE_INVALID_TRANSITION = 'statemachine.invalid_transition';
    public const STATEMACHINE_BMG_UNIT_BUSY      = 'statemachine.bmg.unit_busy';
    public const STATEMACHINE_BMG_MASS_INVARIANT = 'statemachine.bmg.mass_invariant';

    public const ENCRYPTION_FAILED              = 'encryption.failed';
    public const DECRYPTION_FAILED              = 'decryption.failed';

    public const TRANSACTION_ROLLED_BACK        = 'transaction.rolled_back';

    public const EXPORT_UNAVAILABLE             = 'export.unavailable';

    public const AUDIT_EVENT_NOT_FOUND          = 'audit.event_not_found';

    public const INTERNAL_ERROR                 = 'internal.error';

    /**
     * Map an error code to a default HTTP status. Used by the exception
     * filter when a generic `ApiException` is thrown without an explicit
     * status.
     */
    public static function defaultStatus(string $code): int
    {
        return match (true) {
            str_starts_with($code, 'auth.login_locked')                => 429,
            $code === self::AUTH_PASSWORD_CHANGE_REQUIRED              => 403,
            str_starts_with($code, 'auth.')                            => 401,
            str_starts_with($code, 'rbac.')                            => 403,
            str_starts_with($code, 'resource.not_found')               => 404,
            str_starts_with($code, 'resource.conflict')                => 409,
            str_starts_with($code, 'request.validation_failed')        => 422,
            str_starts_with($code, 'validation.invalid')               => 422,
            str_starts_with($code, 'request.method_not_allowed')       => 405,
            str_starts_with($code, 'request.malformed')                => 400,
            str_starts_with($code, 'ratelimit.')                       => 429,
            str_starts_with($code, 'statemachine.')                    => 409,
            str_starts_with($code, 'encryption.'), str_starts_with($code, 'decryption.') => 500,
            str_starts_with($code, 'transaction.')                     => 503,
            str_starts_with($code, 'export.')                          => 500,
            str_starts_with($code, 'audit.')                           => 404,
            default                                                    => 500,
        };
    }
}
