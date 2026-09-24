import { describe, expect, it } from 'vitest';

import {
  adminUserListParams,
  personKindLabel,
  UNLINKED_KIND,
  type AdminUsersFilters,
} from './useAdminUsers';

const NO_FILTERS: AdminUsersFilters = {
  search: '',
  status: 'all',
  group: 'all',
  sort: 'newest',
  kind: 'all',
};

describe('adminUserListParams', () => {
  it('omits the first-page cursor and every default filter', () => {
    const params = adminUserListParams(null, 25, NO_FILTERS);

    expect(params.toString()).toBe('limit=25');
    for (const key of ['cursor', 'q', 'status', 'group', 'sort', 'kind']) {
      expect(params.has(key)).toBe(false);
    }
  });

  it('keeps an empty-string cursor off the wire', () => {
    const params = adminUserListParams('', 25, NO_FILTERS);

    expect(params.has('cursor')).toBe(false);
  });

  it('carries a real cursor and every active filter', () => {
    const params = adminUserListParams('abc123', 50, {
      search: 'torres',
      status: 'active',
      group: 'counsellor',
      sort: 'oldest',
      kind: 'student',
    });

    expect(params.get('cursor')).toBe('abc123');
    expect(params.get('limit')).toBe('50');
    expect(params.get('q')).toBe('torres');
    expect(params.get('status')).toBe('active');
    expect(params.get('group')).toBe('counsellor');
    expect(params.get('sort')).toBe('oldest');
    expect(params.get('kind')).toBe('student');
  });

  it('sends "unlinked" as a real filter value, not a sentinel', () => {
    // `all` means "no filter", but `unlinked` selects the kind IS NULL
    // bucket — dropping it like the facet sentinels would silently widen
    // the list back to every person type.
    const params = adminUserListParams(null, 25, { ...NO_FILTERS, kind: UNLINKED_KIND });

    expect(params.get('kind')).toBe('unlinked');
  });

  it('does not send "all" as a person type', () => {
    const params = adminUserListParams(null, 25, { ...NO_FILTERS, kind: 'all' });

    expect(params.has('kind')).toBe(false);
    expect(params.toString()).not.toContain('kind');
  });
});

describe('personKindLabel', () => {
  it('labels every known person type', () => {
    expect(personKindLabel('student')).toBe('Student');
    expect(personKindLabel('employee')).toBe('Employee');
    expect(personKindLabel('contractor')).toBe('Contractor');
    expect(personKindLabel('alumni')).toBe('Alumni');
    expect(personKindLabel(UNLINKED_KIND)).toBe('Unlinked');
  });

  it('falls back to the raw value for an unknown kind', () => {
    expect(personKindLabel('wizard')).toBe('wizard');
  });
});
