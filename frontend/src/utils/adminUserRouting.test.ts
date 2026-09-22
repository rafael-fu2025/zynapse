import { describe, expect, it } from 'vitest';
import { resolveRoleSaveRoute } from './adminUserRouting';

/**
 * Regression guard for the admin directory role flow.
 *
 * The bug this pins: a directory search hit (no local row) was shown with
 * a synthetic negative id, and the save action blindly sent that id to
 * `POST /admin/users/{id}/groups` — a numeric role update for a user that
 * does not exist. Directory saves must instead route through the
 * identifier-based provision endpoint, and synthetic ids must never be
 * dispatched as numeric updates.
 */
describe('resolveRoleSaveRoute', () => {
  it('routes a directory student to the provision endpoint', () => {
    const route = resolveRoleSaveRoute({
      id: -1,
      is_directory_record: true,
      directory_identifier: '20261234',
      person_kind: 'student',
    });
    expect(route).toEqual({ mode: 'provision', identifier: '20261234', kind: 'student' });
  });

  it('routes a directory employee to the provision endpoint', () => {
    const route = resolveRoleSaveRoute({
      id: -7,
      is_directory_record: true,
      directory_identifier: 'EMP7788',
      person_kind: 'employee',
    });
    expect(route).toEqual({ mode: 'provision', identifier: 'EMP7788', kind: 'employee' });
  });

  it('trims the directory identifier before routing', () => {
    const route = resolveRoleSaveRoute({
      id: -2,
      is_directory_record: true,
      directory_identifier: '  20260001  ',
      person_kind: 'student',
    });
    expect(route).toEqual({ mode: 'provision', identifier: '20260001', kind: 'student' });
  });

  it('rejects a directory record with a missing identifier instead of using its synthetic id', () => {
    const route = resolveRoleSaveRoute({
      id: -3,
      is_directory_record: true,
      person_kind: 'student',
    });
    expect(route.mode).toBe('invalid');
  });

  it('rejects a directory record with a blank identifier', () => {
    const route = resolveRoleSaveRoute({
      id: -4,
      is_directory_record: true,
      directory_identifier: '   ',
      person_kind: 'employee',
    });
    expect(route.mode).toBe('invalid');
  });

  it('rejects a directory record with an unsupported person type', () => {
    const route = resolveRoleSaveRoute({
      id: -5,
      is_directory_record: true,
      directory_identifier: '20269999',
      person_kind: 'contractor',
    });
    expect(route.mode).toBe('invalid');
  });

  it('rejects a directory record with a null person type', () => {
    const route = resolveRoleSaveRoute({
      id: -6,
      is_directory_record: true,
      directory_identifier: '20268888',
      person_kind: null,
    });
    expect(route.mode).toBe('invalid');
  });

  it('routes a real local account to the numeric replace endpoint', () => {
    const route = resolveRoleSaveRoute({
      id: 42,
      is_directory_record: false,
      person_kind: 'employee',
    });
    expect(route).toEqual({ mode: 'replace', id: 42 });
  });

  it('treats a missing is_directory_record flag as a real account', () => {
    const route = resolveRoleSaveRoute({ id: 9 });
    expect(route).toEqual({ mode: 'replace', id: 9 });
  });

  it('rejects a non-directory row whose id is not positive', () => {
    expect(resolveRoleSaveRoute({ id: 0, is_directory_record: false }).mode).toBe('invalid');
    expect(resolveRoleSaveRoute({ id: -11, is_directory_record: false }).mode).toBe('invalid');
  });

  it('rejects a non-directory row whose id is not an integer', () => {
    expect(resolveRoleSaveRoute({ id: 3.5, is_directory_record: false }).mode).toBe('invalid');
    expect(resolveRoleSaveRoute({ id: Number.NaN, is_directory_record: false }).mode).toBe('invalid');
  });
});
