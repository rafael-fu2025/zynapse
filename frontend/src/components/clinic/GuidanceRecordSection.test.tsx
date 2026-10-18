/**
 * GuidanceRecordSection unit tests — the guidance-side record surface
 * shared by the Students and Employees tabs ("View guidance record").
 *
 * Vitest environment is node: rendered with renderToStaticMarkup and
 * asserted as markup. The session hook and the permission hook are
 * stubbed so each case pins exactly one behaviour.
 *
 * The privacy contract these tests lock (2026-10):
 *   - a viewer WITHOUT `counselling.records.read` gets no section at all;
 *   - a viewer WITH it gets the session history (date, counsellor, state);
 *   - the section needs a person identifier to fetch against.
 */
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import { GuidanceRecordSection } from './GuidanceRecordSection';

const h = vi.hoisted(() => {
  // Annotated (not asserted): `data` starts undefined but each case
  // replaces it with a session list.
  const sessions: { data: unknown; isLoading: boolean; isError: boolean } = {
    data: undefined,
    isLoading: false,
    isError: false,
  };
  return { canView: true, canRestore: false, archived: '', sessions, patientSessions: vi.fn(), restore: vi.fn(), setArchived: vi.fn() };
});

vi.mock('@/hooks/useCan', () => ({
  useCan: (code: string) => code === 'counselling.records.read' ? h.canView : h.canRestore,
}));

vi.mock('@/hooks/useCounselling', () => ({
  usePatientSessions: (...args: unknown[]) => { h.patientSessions(...args); return h.sessions; },
    useUnarchiveSession: () => ({ mutate: h.restore, isPending: false }),
}));

vi.mock('@/hooks/useUrlFilter', () => ({
  useUrlFilter: () => [h.archived, h.setArchived, h.archived],
}));

function renderSection(patientSchoolId: string | null | undefined): string {
  return renderToStaticMarkup(<GuidanceRecordSection patientSchoolId={patientSchoolId} />);
}

describe('GuidanceRecordSection', () => {
  it('defaults to active and hides archive controls without oversight', () => {
    h.archived = '1';
    h.canRestore = false;
    h.sessions = { data: [], isLoading: false, isError: false };
    const html = renderSection('2021-0001');
    expect(h.patientSessions).toHaveBeenLastCalledWith('2021-0001', true, false);
    expect(html).not.toContain('Show archived');
    expect(html).not.toContain('Restore');
    h.archived = '';
  });

  it('shows archived history read-only with Restore for oversight', () => {
    h.archived = '1';
    h.canRestore = true;
    h.sessions = { data: [{ id: 12, started_at: '2026-10-01 01:30:00', ended_at: null,
      counsellor_name: 'Dr. Reyes', archived_at: '2026-10-02 01:30:00' }], isLoading: false, isError: false };
    const html = renderSection('2021-0001');
    expect(h.patientSessions).toHaveBeenLastCalledWith('2021-0001', true, true);
    expect(html).toContain('Archived');
    expect(html).toContain('read-only');
    expect(html).toContain('Restore session #12');
    expect(html).not.toContain('Open');
    expect(html).not.toContain('Edit');
    expect(html).not.toContain('href=');
    h.archived = '';
    h.canRestore = false;
  });
  it('renders nothing for a viewer without counselling.records.read', () => {
    h.canView = false;
    h.sessions = { data: [], isLoading: false, isError: false };

    expect(renderSection('2021-0001')).toBe('');

    h.canView = true;
  });

  it('renders nothing when the person has no identifier to fetch against', () => {
    h.sessions = { data: [], isLoading: false, isError: false };

    expect(renderSection(null)).toBe('');
    expect(renderSection(undefined)).toBe('');
    expect(renderSection('')).toBe('');
  });

  it('lists a session with its date, counsellor and open state', () => {
    h.sessions = {
      data: [
        {
          id: 7,
          patient_school_id: '2021-0001',
          counsellor_user_id: 3,
          counsellor_name: 'Dr. Reyes',
          started_at: '2026-10-01 01:30:00',
          ended_at: null,
        },
      ],
      isLoading: false,
      isError: false,
    };

    const html = renderSection('2021-0001');

    expect(html).toContain('Guidance record');
    expect(html).toContain('Dr. Reyes');
    expect(html).toContain('Open');
    // The date is rendered through the Manila-day formatter.
    expect(html).toContain('Oct');
  });

  it('marks a closed session and falls back to Unassigned for a missing counsellor', () => {
    h.sessions = {
      data: [
        {
          id: 8,
          patient_school_id: 'EMP-001',
          counsellor_user_id: 3,
          counsellor_name: null,
          started_at: '2026-09-15 02:00:00',
          ended_at: '2026-09-15 03:00:00',
        },
      ],
      isLoading: false,
      isError: false,
    };

    const html = renderSection('EMP-001');

    expect(html).toContain('Closed');
    expect(html).toContain('Unassigned');
  });

  it('shows the empty state when the person has no sessions', () => {
    h.sessions = { data: [], isLoading: false, isError: false };

    expect(renderSection('2021-0001')).toContain('No counselling sessions on record.');
  });

  it('shows a load failure instead of an empty record', () => {
    h.sessions = { data: undefined, isLoading: false, isError: true };

    expect(renderSection('2021-0001')).toContain('Failed to load the guidance record.');
  });
});
