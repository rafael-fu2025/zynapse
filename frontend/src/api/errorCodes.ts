/**
 * Typed mirror of backend `App\Exceptions\ApiErrorCode`.
 *
 * Keep this in sync with the backend. The mapping here lets the SPA
 * surface consistent UX (the right toast variant, the right affordance)
 * regardless of who issued the error.
 */
export const ApiErrorCode = {
  AUTH_UNAUTHORIZED: 'auth.unauthorized',
  AUTH_REFRESH_MISSING: 'auth.refresh_missing',
  AUTH_REFRESH_INVALID: 'auth.refresh_invalid_or_replayed',
  AUTH_CREDENTIALS_INVALID: 'auth.credentials_invalid',
  AUTH_USER_NOT_FOUND: 'auth.user_not_found',

  RBAC_FORBIDDEN: 'rbac.forbidden',
  RBAC_PERMISSION_DENIED: 'rbac.permission_denied',
  REFERRAL_TEACHING_REQUIRED: 'referral.teaching_required',

  RESOURCE_NOT_FOUND: 'resource.not_found',
  RESOURCE_CONFLICT: 'resource.conflict',

  REQUEST_VALIDATION_FAILED: 'request.validation_failed',
  REQUEST_MALFORMED: 'request.malformed',
  REQUEST_METHOD_NOT_ALLOWED: 'request.method_not_allowed',
  REQUEST_INVALID: 'validation.invalid',

  RATELIMIT_EXCEEDED: 'ratelimit.exceeded',

  STATEMACHINE_INVALID_TRANSITION: 'statemachine.invalid_transition',
  STATEMACHINE_BMG_UNIT_BUSY: 'statemachine.bmg.unit_busy',
  STATEMACHINE_BMG_MASS_INVARIANT: 'statemachine.bmg.mass_invariant',

  ENCRYPTION_FAILED: 'encryption.failed',
  DECRYPTION_FAILED: 'decryption.failed',

  TRANSACTION_ROLLED_BACK: 'transaction.rolled_back',

  EXPORT_UNAVAILABLE: 'export.unavailable',
  AUDIT_EVENT_NOT_FOUND: 'audit.event_not_found',

  INTERNAL_ERROR: 'internal.error',
} as const;

export type ApiErrorCodeValue = (typeof ApiErrorCode)[keyof typeof ApiErrorCode];

export function isApiErrorCode(code: string, candidates: ReadonlyArray<ApiErrorCodeValue>): boolean {
  return candidates.includes(code as ApiErrorCodeValue);
}

export type ToastVariant = 'success' | 'error' | 'warning' | 'info';

export function variantForCode(code: string): ToastVariant {
  if (code.startsWith('auth.') || code.startsWith('rbac.') || code.startsWith('internal.')) {
    return 'error';
  }
  if (
    code === ApiErrorCode.STATEMACHINE_BMG_UNIT_BUSY ||
    code === ApiErrorCode.STATEMACHINE_INVALID_TRANSITION ||
    code === ApiErrorCode.RESOURCE_CONFLICT
  ) {
    return 'warning';
  }
  if (code === ApiErrorCode.RATELIMIT_EXCEEDED) {
    return 'warning';
  }
  if (code === ApiErrorCode.STATEMACHINE_BMG_MASS_INVARIANT) {
    return 'warning';
  }
  return 'error';
}

export function humanizeCode(code: string): string {
  switch (code) {
    case ApiErrorCode.AUTH_REFRESH_INVALID:
      return 'Your session has expired or was replayed. Please sign in again.';
    case ApiErrorCode.AUTH_REFRESH_MISSING:
      return 'No active session. Please sign in.';
    case ApiErrorCode.AUTH_CREDENTIALS_INVALID:
      return 'Email or password is incorrect.';
    case ApiErrorCode.RBAC_FORBIDDEN:
    case ApiErrorCode.RBAC_PERMISSION_DENIED:
      return 'You do not have permission for this action.';
    case ApiErrorCode.RESOURCE_NOT_FOUND:
      return 'Resource not found.';
    case ApiErrorCode.STATEMACHINE_BMG_UNIT_BUSY:
      return 'This unit already has an unfinished batch.';
    case ApiErrorCode.STATEMACHINE_BMG_MASS_INVARIANT:
      return 'Output weight exceeds total input. Reduce the output weight.';
    case ApiErrorCode.STATEMACHINE_INVALID_TRANSITION:
      return 'That lifecycle transition is not allowed.';
    case ApiErrorCode.RATELIMIT_EXCEEDED:
      return 'Too many requests. Please slow down.';
    case ApiErrorCode.REQUEST_VALIDATION_FAILED:
      return 'Some fields are invalid.';
    case ApiErrorCode.TRANSACTION_ROLLED_BACK:
      return 'The change was not saved. Please try again.';
    default:
      return code;
  }
}