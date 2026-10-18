import { describe, expect, it } from 'vitest';

import {
  adminUserListParams,
  directorySyncBody,
  directorySyncJobSchema,
  directorySyncActive,
  directorySyncCommitted,
  directorySyncPollInterval,
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

describe('directorySyncBody', () => {
  it('sends kind and dry_run, omitting unset paging options', () => {
    expect(directorySyncBody({ kind: 'student', dryRun: true })).toEqual({ kind: 'student', dry_run: true });
  });

  it('maps camelCase paging options to the snake_case API fields', () => {
    expect(directorySyncBody({ kind: 'all', dryRun: false, pageSize: 100, maxPages: 50 })).toEqual({
      kind: 'all',
      dry_run: false,
      page_size: 100,
      max_pages: 50,
    });
  });
});

describe('queued directory sync contract', () => {
  const counts = { pages: 1, fetched: 2, created: 1, updated: 0, unchanged: 0, skipped: 1, duplicates: 0 };
  const queued = {
    id: 5, tenant_id: 1, kind: 'all', dry_run: false, status: 'queued', kinds: {},
    current_kind: null, current_page: 0, total_pages: null,
    created_at: '2026-10-13 00:00:00', started_at: null, finished_at: null, error: null,
  };

  it('accepts queued empty-object counts, unknown totals and extra fields', () => {
    expect(directorySyncJobSchema.parse({ ...queued, extra: true })).toMatchObject({ kinds: {}, extra: true });
    expect(directorySyncJobSchema.safeParse({ ...queued, kinds: [] }).success).toBe(false);
  });

  it('accepts nullable per-kind totals and rejects synchronous summaries', () => {
    expect(directorySyncJobSchema.parse({ ...queued, kinds: { student: { ...counts, total_pages: null } } }).kinds.student?.total_pages).toBeNull();
    expect(directorySyncJobSchema.safeParse({ success: true, dry_run: true, kinds: {} }).success).toBe(false);
  });

  it.each(['queued', 'running', 'completed', 'failed', 'interrupted'] as const)('polls and invalidates honestly for %s', (status) => {
    const job = directorySyncJobSchema.parse({ ...queued, status, kinds: { student: counts } });
    const active = status === 'queued' || status === 'running';
    expect(directorySyncActive(job)).toBe(active);
    expect(directorySyncPollInterval(job, false)).toBe(active ? 3000 : false);
    expect(directorySyncCommitted(job)).toBe(!active);
    expect(directorySyncCommitted({ ...job, dry_run: true })).toBe(false);
    expect(directorySyncCommitted({ ...job, kinds: {} })).toBe(false);
  });

  it('retries unknown transport status even for terminal or absent cached data', () => {
    expect(directorySyncPollInterval(null, true)).toBe(3000);
    expect(directorySyncPollInterval(directorySyncJobSchema.parse({ ...queued, status: 'completed' }), true)).toBe(3000);
    expect(directorySyncPollInterval(null, false)).toBe(false);
  });
});
