import { describe, expect, it } from 'vitest';
import { ApiEnvelopeError } from './envelope';

/**
 * Contract tests for ApiEnvelopeError normalization. The axios layer
 * feeds it whatever the backend put in `errors`, so every shape drift is
 * normalized here — these tests pin the rules the whole SPA relies on
 * for toasts and form field errors.
 */
describe('ApiEnvelopeError', () => {
  it('keeps well-formed error objects, including field and details', () => {
    const err = new ApiEnvelopeError(422, [
      { code: 'validation.field', message: 'Email is invalid.', field: 'email' },
      { code: 'rbac.forbidden', message: 'No.', details: { role: 'student' } },
    ]);

    expect(err.httpStatus).toBe(422);
    expect(err.errors).toHaveLength(2);
    expect(err.errors[0]).toEqual({
      code: 'validation.field',
      message: 'Email is invalid.',
      field: 'email',
    });
    expect(err.errors[1]?.details).toEqual({ role: 'student' });
    expect(err.message).toBe('Email is invalid.');
  });

  it('wraps a bare string entry as request.failed', () => {
    const err = new ApiEnvelopeError(500, ['Something exploded']);

    expect(err.errors).toEqual([{ code: 'request.failed', message: 'Something exploded' }]);
    expect(err.message).toBe('Something exploded');
  });

  it('wraps a single object (not array) as a one-element list', () => {
    const err = new ApiEnvelopeError(401, { code: 'auth.credentials_invalid', message: 'Nope' });

    expect(err.errors).toEqual([{ code: 'auth.credentials_invalid', message: 'Nope' }]);
  });

  it('substitutes request.failed for an object without a message', () => {
    const err = new ApiEnvelopeError(403, [{ code: 'rbac.forbidden' }]);

    expect(err.errors).toEqual([{ code: 'request.failed', message: 'Request failed (HTTP 403).' }]);
  });

  it('keeps a message-less entry with an empty-string message out but not silently', () => {
    // Empty message fails the trim() check; the list falls back to the
    // generic HTTP message rather than shipping a blank toast.
    const err = new ApiEnvelopeError(400, [{ message: '   ' }]);

    expect(err.errors).toEqual([{ code: 'request.failed', message: 'Request failed (HTTP 400).' }]);
  });

  it('falls back to a generic message when errors is null or empty', () => {
    expect(new ApiEnvelopeError(404, null).errors).toEqual([
      { code: 'request.failed', message: 'Request failed (HTTP 404).' },
    ]);
    expect(new ApiEnvelopeError(404, []).errors).toEqual([
      { code: 'request.failed', message: 'Request failed (HTTP 404).' },
    ]);
  });

  it('drops primitive entries that are not strings', () => {
    const err = new ApiEnvelopeError(400, [42, null]);

    expect(err.errors).toEqual([{ code: 'request.failed', message: 'Request failed (HTTP 400).' }]);
  });
});
