import { describe, expect, it } from 'vitest';
import {
  ApiErrorCode,
  humanizeCode,
  isApiErrorCode,
  variantForCode,
} from './errorCodes';

/**
 * The error-code → UX mapping. These pin the toast variant rules and the
 * humanized copy so a backend code added without a frontend mapping
 * degrades to the visible default (`code` passthrough) instead of an
 * unhandled crash.
 */
describe('variantForCode', () => {
  it('renders auth/rbac/internal failures as errors', () => {
    expect(variantForCode('auth.credentials_invalid')).toBe('error');
    expect(variantForCode('rbac.forbidden')).toBe('error');
    expect(variantForCode('internal.error')).toBe('error');
  });

  it('renders lifecycle conflicts and rate limits as warnings', () => {
    expect(variantForCode(ApiErrorCode.STATEMACHINE_BMG_UNIT_BUSY)).toBe('warning');
    expect(variantForCode(ApiErrorCode.STATEMACHINE_INVALID_TRANSITION)).toBe('warning');
    expect(variantForCode(ApiErrorCode.STATEMACHINE_BMG_MASS_INVARIANT)).toBe('warning');
    expect(variantForCode(ApiErrorCode.RESOURCE_CONFLICT)).toBe('warning');
    expect(variantForCode(ApiErrorCode.RATELIMIT_EXCEEDED)).toBe('warning');
  });

  it('defaults unknown codes to error, not warning', () => {
    expect(variantForCode('kiosk.media.upload_failed')).toBe('error');
  });
});

describe('humanizeCode', () => {
  it('maps the codes the UI surfaces verbatim to staff-facing copy', () => {
    expect(humanizeCode(ApiErrorCode.AUTH_CREDENTIALS_INVALID)).toBe(
      'Email or password is incorrect.',
    );
    expect(humanizeCode(ApiErrorCode.AUTH_REFRESH_INVALID)).toBe(
      'Your session has expired or was replayed. Please sign in again.',
    );
    expect(humanizeCode(ApiErrorCode.STATEMACHINE_BMG_MASS_INVARIANT)).toBe(
      'Output weight exceeds total input. Reduce the output weight.',
    );
    expect(humanizeCode(ApiErrorCode.RBAC_FORBIDDEN)).toBe(
      'You do not have permission for this action.',
    );
  });

  it('passes unknown codes through so nothing is silently swallowed', () => {
    expect(humanizeCode('clinic.queue.custom_code')).toBe('clinic.queue.custom_code');
  });
});

describe('isApiErrorCode', () => {
  it('narrows only codes present in the candidate list', () => {
    expect(isApiErrorCode('auth.unauthorized', [ApiErrorCode.AUTH_UNAUTHORIZED])).toBe(true);
    expect(isApiErrorCode('auth.unauthorized', [ApiErrorCode.RBAC_FORBIDDEN])).toBe(false);
  });
});
