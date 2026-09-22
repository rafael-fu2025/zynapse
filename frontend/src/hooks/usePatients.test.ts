import { describe, expect, it } from 'vitest';

import { employeeListParams, employeeSearchParams } from './usePatients';

describe('employeeListParams', () => {
  it('omits the first-page cursor and the "all" facet sentinel', () => {
    const params = employeeListParams({
      cursor: null,
      limit: 25,
      includeArchived: false,
      teaching: 'all',
      department: 'all',
      position: 'all',
    });

    expect(params.toString()).toBe('limit=25');
    expect(params.has('cursor')).toBe(false);
    expect(params.has('department')).toBe(false);
    expect(params.has('position')).toBe(false);
  });

  it('carries a real cursor and both facets', () => {
    const params = employeeListParams({
      cursor: 'abc123',
      limit: 25,
      includeArchived: false,
      department: 'College of Nursing',
      position: 'Dean',
    });

    expect(params.get('cursor')).toBe('abc123');
    expect(params.get('department')).toBe('College of Nursing');
    expect(params.get('position')).toBe('Dean');
    expect(params.get('limit')).toBe('25');
  });

  it('never sends the literal "all" as a facet value', () => {
    // "all" is a UI sentinel. Sending it would filter on a department
    // literally named "all" and silently return nothing.
    const params = employeeListParams({
      cursor: null,
      limit: 25,
      includeArchived: false,
      department: 'all',
      position: 'all',
    });

    expect(params.getAll('department')).toEqual([]);
    expect(params.getAll('position')).toEqual([]);
    expect(params.toString()).not.toContain('all');
  });

  it('keeps an empty-string cursor off the wire', () => {
    const params = employeeListParams({
      cursor: '',
      limit: 25,
      includeArchived: false,
    });

    expect(params.has('cursor')).toBe(false);
  });

  it('flags archived inclusion and passes the retained teaching parameter through', () => {
    const params = employeeListParams({
      cursor: null,
      limit: 50,
      includeArchived: true,
      teaching: 'non_teaching',
    });

    expect(params.get('include_archived')).toBe('1');
    expect(params.get('teaching')).toBe('non_teaching');
  });
});

describe('employeeSearchParams', () => {
  it('sends only the query when no facet is active', () => {
    const params = employeeSearchParams({ q: 'Torres', department: 'all', position: 'all' });

    expect(params.toString()).toBe('q=Torres');
  });

  it('keeps the active facets in force during a search', () => {
    const params = employeeSearchParams({
      q: 'Torres',
      department: 'College of Law & Jurisprudence',
      position: 'Dean',
    });

    expect(params.get('q')).toBe('Torres');
    expect(params.get('department')).toBe('College of Law & Jurisprudence');
    expect(params.get('position')).toBe('Dean');
  });
});
