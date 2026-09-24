import { describe, expect, it } from 'vitest';

import { employeeListParams, employeeSearchParams, studentListParams, studentSearchParams } from './usePatients';

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

describe('studentListParams', () => {
  it('omits the first-page cursor and the "all" facet sentinels', () => {
    const params = studentListParams({
      cursor: null,
      limit: 25,
      includeArchived: false,
      department: 'all',
      course: 'all',
      yearLevel: 'all',
    });

    expect(params.toString()).toBe('limit=25');
    expect(params.has('cursor')).toBe(false);
    expect(params.has('department')).toBe(false);
    expect(params.has('course')).toBe(false);
    expect(params.has('year_level')).toBe(false);
  });

  it('carries a real cursor and all three facets', () => {
    const params = studentListParams({
      cursor: 'abc123',
      limit: 25,
      includeArchived: false,
      department: 'CCS',
      course: 'BSIT',
      yearLevel: '3',
    });

    expect(params.get('cursor')).toBe('abc123');
    expect(params.get('department')).toBe('CCS');
    expect(params.get('course')).toBe('BSIT');
    expect(params.get('year_level')).toBe('3');
    expect(params.get('limit')).toBe('25');
  });

  it('never sends the literal "all" as a facet value', () => {
    // "all" is a UI sentinel. Sending it would filter on a department
    // literally named "all" and silently return nothing.
    const params = studentListParams({
      cursor: null,
      limit: 25,
      includeArchived: false,
      department: 'all',
      course: 'all',
      yearLevel: 'all',
    });

    expect(params.getAll('department')).toEqual([]);
    expect(params.getAll('course')).toEqual([]);
    expect(params.getAll('year_level')).toEqual([]);
    expect(params.toString()).not.toContain('all');
  });

  it('keeps an empty-string cursor off the wire', () => {
    const params = studentListParams({
      cursor: '',
      limit: 25,
      includeArchived: false,
    });

    expect(params.has('cursor')).toBe(false);
  });

  it('flags archived inclusion', () => {
    const params = studentListParams({
      cursor: null,
      limit: 50,
      includeArchived: true,
    });

    expect(params.get('include_archived')).toBe('1');
  });

  it('sends a year level as a numeric value, never a display label', () => {
    // MIS numbers levels per scheme (College 1-5, ELEM Grade 1-6), so the
    // wire value is the bare number and the "Year n" label lives in the UI.
    const params = studentListParams({
      cursor: null,
      limit: 25,
      includeArchived: false,
      yearLevel: '2',
    });

    expect(params.get('year_level')).toBe('2');
    expect(Number(params.get('year_level'))).toBe(2);
  });
});

describe('studentSearchParams', () => {
  it('sends only the query when no facet is active', () => {
    const params = studentSearchParams({
      q: 'Santos',
      department: 'all',
      course: 'all',
      yearLevel: 'all',
    });

    expect(params.toString()).toBe('q=Santos');
  });

  it('keeps the active facets in force during a search', () => {
    const params = studentSearchParams({
      q: 'Santos',
      department: 'NURSING',
      course: 'BSN',
      yearLevel: '1',
    });

    expect(params.get('q')).toBe('Santos');
    expect(params.get('department')).toBe('NURSING');
    expect(params.get('course')).toBe('BSN');
    expect(params.get('year_level')).toBe('1');
  });
});
