import { describe, expect, it } from 'vitest';

import {
  employeeListParams,
  employeeSearchParams,
  parseArchiveStatus,
  patientMedicalSegment,
  studentListParams,
  studentSearchParams,
} from './usePatients';

/**
 * patientMedicalSegment — the person-level medical record is shared by
 * both registry tabs, but each kind reaches it through its OWN route
 * prefix. Getting this wrong would send an employee's allergy write to
 * the students route (404) or worse, the wrong person.
 */
describe('patientMedicalSegment', () => {
  it('routes employees to the employees segment and everyone else to students', () => {
    expect(patientMedicalSegment('employee')).toBe('employees');
    expect(patientMedicalSegment('student')).toBe('students');
  });
});

describe('parseArchiveStatus', () => {
  it('accepts the three scopes', () => {
    expect(parseArchiveStatus('active')).toBe('active');
    expect(parseArchiveStatus('archived')).toBe('archived');
    expect(parseArchiveStatus('all')).toBe('all');
  });

  it('treats anything else as active so a hand-edited URL never widens the view', () => {
    expect(parseArchiveStatus(null)).toBe('active');
    expect(parseArchiveStatus(undefined)).toBe('active');
    expect(parseArchiveStatus('')).toBe('active');
    expect(parseArchiveStatus('1')).toBe('active');
    expect(parseArchiveStatus('everything')).toBe('active');
  });
});

describe('employeeListParams', () => {
  it('omits the first-page cursor and the "all" facet sentinel', () => {
    const params = employeeListParams({
      cursor: null,
      limit: 25,
      status: 'active',
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
      status: 'active',
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
      status: 'active',
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
      status: 'active',
    });

    expect(params.has('cursor')).toBe(false);
  });

  it('requests archived-only and passes the retained teaching parameter through', () => {
    const params = employeeListParams({
      cursor: null,
      limit: 50,
      status: 'archived',
      teaching: 'non_teaching',
    });

    expect(params.get('archived')).toBe('archived');
    expect(params.get('teaching')).toBe('non_teaching');
  });

  it('requests live + archived together with status all', () => {
    const params = employeeListParams({ cursor: null, limit: 25, status: 'all' });

    expect(params.get('archived')).toBe('all');
  });

  it('sends nothing for the active default and never the legacy include flag', () => {
    const params = employeeListParams({ cursor: null, limit: 25, status: 'active' });

    expect(params.has('archived')).toBe(false);
    expect(params.has('include_archived')).toBe(false);
    expect(employeeListParams({ cursor: null, limit: 25 }).has('archived')).toBe(false);
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

  it('carries the archive status so archived rows do not vanish when typing', () => {
    expect(employeeSearchParams({ q: 'Torres', status: 'archived' }).get('archived')).toBe('archived');
    expect(employeeSearchParams({ q: 'Torres', status: 'active' }).has('archived')).toBe(false);
  });
});

describe('studentListParams', () => {
  it('omits the first-page cursor and the "all" facet sentinels', () => {
    const params = studentListParams({
      cursor: null,
      limit: 25,
      status: 'active',
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
      status: 'active',
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
      status: 'active',
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
      status: 'active',
    });

    expect(params.has('cursor')).toBe(false);
  });

  it('requests archived-only, live + archived, or nothing for the active default', () => {
    expect(studentListParams({ cursor: null, limit: 50, status: 'archived' }).get('archived')).toBe('archived');
    expect(studentListParams({ cursor: null, limit: 50, status: 'all' }).get('archived')).toBe('all');

    const active = studentListParams({ cursor: null, limit: 50, status: 'active' });
    expect(active.has('archived')).toBe(false);
    expect(active.has('include_archived')).toBe(false);
  });

  it('carries the archive status into the search too', () => {
    expect(studentSearchParams({ q: 'Dino', status: 'archived' }).get('archived')).toBe('archived');
    expect(studentSearchParams({ q: 'Dino' }).has('archived')).toBe(false);
  });

  it('sends a year level as a numeric value, never a display label', () => {
    // MIS numbers levels per scheme (College 1-5, ELEM Grade 1-6), so the
    // wire value is the bare number and the "Year n" label lives in the UI.
    const params = studentListParams({
      cursor: null,
      limit: 25,
      status: 'active',
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
