/**
 * StudentPortalPage — overview card unit tests: the guidance
 * announcements preview, the upcoming-appointment shortcut, and the
 * password entry point that used to live in the "Account access" card.
 *
 * The vitest environment is node on purpose — no jsdom — so the page
 * is rendered with `renderToStaticMarkup` inside a MemoryRouter and
 * asserted as markup. Every data hook is stubbed and the heavy tab
 * children are mocked out so their module graphs (QR libs, dialogs)
 * never load here.
 */
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import StudentPortalPage from './StudentPortalPage';

// Deliberate SSR-style rendering: Radix (Tabs) and react-router use
// useLayoutEffect, which React warns about during server render. That
// warning is expected noise here — filter ONLY it; every other
// console.error still reaches the real console.
const realConsoleError = console.error;
beforeAll(() => {
  vi.spyOn(console, 'error').mockImplementation((...args: unknown[]) => {
    if (String(args[0]).startsWith('Warning: useLayoutEffect does nothing on the server')) return;
    realConsoleError(...args);
  });
});

const h = vi.hoisted(() => {
  const stub = (data: unknown, opts: { isLoading?: boolean } = {}) => ({
    data,
    isLoading: opts.isLoading ?? false,
    isError: false,
    isFetching: opts.isLoading ?? false,
    error: undefined,
    refetch: () => {},
  });

  const ANNOUNCEMENTS = [
    {
      id: 1,
      title: 'Guidance week orientation',
      body: 'Join the orientation for graduating students at the main auditorium.',
      action_url: null,
      action_label: null,
      is_required: false,
      severity: 'normal',
      publish_at: '2999-01-05 01:30:00',
    },
    {
      id: 2,
      title: 'Clearance signing schedule',
      body: 'Claim your clearance form at Window 2 of the Guidance Office.',
      action_url: 'https://forms.foundationu.com/clearance',
      action_label: 'Open form',
      is_required: true,
      severity: 'urgent',
      publish_at: '2999-01-04 00:00:00',
    },
    {
      id: 3,
      title: 'Peer counselling intake',
      body: 'Applications for the peer counselling circle are open.',
      action_url: null,
      action_label: null,
      is_required: false,
      severity: 'normal',
      publish_at: '2999-01-03 00:00:00',
    },
    // Newest-first feed → this one must NOT make the top-3 preview.
    {
      id: 4,
      title: 'Overflow post',
      body: 'Older than the three above.',
      action_url: null,
      action_label: null,
      is_required: false,
      severity: 'normal',
      publish_at: '2999-01-02 00:00:00',
    },
  ];

  const APPOINTMENTS = {
    // Backend returns newest first; the confirmed January slot is the
    // nearest UPCOMING one even though it is not the first row.
    upcoming: [
      {
        id: 12,
        patient_school_id: '20230877',
        provider_user_id: 7,
        provider_name: null,
        scheduled_at: '2999-02-10 01:00:00',
        status: 'scheduled',
        reason: null,
        created_at: '2998-12-01 00:00:00',
      },
      {
        id: 11,
        patient_school_id: '20230877',
        provider_user_id: 8,
        provider_name: 'Marisol Cruz',
        scheduled_at: '2999-01-10 01:00:00',
        status: 'confirmed',
        reason: 'Annual physical',
        created_at: '2998-12-01 00:00:00',
      },
      {
        id: 13,
        patient_school_id: '20230877',
        provider_user_id: 8,
        provider_name: 'Marisol Cruz',
        scheduled_at: '2020-01-10 01:00:00',
        status: 'completed',
        reason: null,
        created_at: '2019-12-01 00:00:00',
      },
    ],
    pastOnly: [
      {
        id: 13,
        patient_school_id: '20230877',
        provider_user_id: 8,
        provider_name: 'Marisol Cruz',
        scheduled_at: '2020-01-10 01:00:00',
        status: 'completed',
        reason: null,
        created_at: '2019-12-01 00:00:00',
      },
    ],
  };

  const state = {
    profile: stub({
      id: 1,
      kind: 'student',
      student_number: '20230877',
      first_name: 'Rafael',
      middle_name: 'Bucles',
      last_name: 'Udtohan',
      course: 'BSIT',
      year_level: 3,
      section: null,
      date_of_birth: null,
      gender: 'male',
      blood_type: null,
      has_qr: true,
      has_rfid: false,
      consecutive_no_shows: 0,
      archived: false,
      created_at: '2026-01-01 00:00:00',
    }),
    visits: stub([]),
    announcements: stub(ANNOUNCEMENTS),
    appointments: stub(APPOINTMENTS.upcoming),
    me: stub({ email: 'rafael.udtohan@foundationu.com', has_local_password: true }),
    notifications: stub([]),
  };

  return { stub, state, ANNOUNCEMENTS, APPOINTMENTS };
});

vi.mock('@/components/YourQueueCard', () => ({ YourQueueCard: () => null }));
vi.mock('@/components/GuidancePortalTab', () => ({ GuidancePortalTab: () => null }));
vi.mock('@/components/PortalAppointments', () => ({ PortalAppointments: () => null }));

vi.mock('@/hooks/useStudentPortal', () => ({
  useMyStudentProfile: () => h.state.profile,
  useMyStudentClinicVisits: () => h.state.visits,
  useMyStudentAppointments: () => h.state.appointments,
}));
vi.mock('@/hooks/useGuidanceContent', () => ({
  useMyGuidanceAnnouncements: () => h.state.announcements,
}));
vi.mock('@/hooks/useAuth', () => ({ useMe: () => h.state.me }));
vi.mock('@/hooks/useNotifications', () => ({ useNotifications: () => h.state.notifications }));

function renderOverview(): string {
  return renderToStaticMarkup(
    <MemoryRouter initialEntries={['/student-portal']}>
      <StudentPortalPage />
    </MemoryRouter>,
  );
}

describe('StudentPortalPage overview', () => {
  beforeEach(() => {
    h.state.announcements = h.stub(h.ANNOUNCEMENTS);
    h.state.appointments = h.stub(h.APPOINTMENTS.upcoming);
    h.state.me = h.stub({ email: 'rafael.udtohan@foundationu.com', has_local_password: true });
  });

  it('renders the full guidance announcements feed on the overview', () => {
    const html = renderOverview();

    // The overview is the only announcements surface now — the whole
    // feed renders, not a top-3 preview (the Guidance tab no longer
    // repeats it, so there is no "View all" destination).
    expect(html).toContain('Guidance week orientation');
    expect(html).toContain('Clearance signing schedule');
    expect(html).toContain('Peer counselling intake');
    expect(html).toContain('Overflow post');
    // Publish dates render through fmtUtcToApp (Manila), not raw UTC.
    expect(html).toContain('Jan 5, 2999');
    expect(html).toContain('Urgent');
    expect(html).toContain('Required');
    // Announcement action links survive the move from the Guidance tab.
    expect(html).toContain('href="https://forms.foundationu.com/clearance"');
    expect(html).toContain('Open form');
  });

  it('shows the announcements empty state', () => {
    h.state.announcements = h.stub([]);

    expect(renderOverview()).toContain('No announcements right now');
  });

  it('renders loading skeletons while the overview feeds load', () => {
    h.state.announcements = h.stub(undefined, { isLoading: true });
    h.state.appointments = h.stub(undefined, { isLoading: true });

    expect(renderOverview()).toContain('animate-pulse');
  });

  it('shows the nearest upcoming appointment and links it to the appointments tab', () => {
    const html = renderOverview();

    // 2999-01-10 01:00 UTC = 9:00 AM Manila; the later February slot
    // and the completed past row stay off the card.
    expect(html).toContain('Jan 10, 2999 · 9:00 AM');
    expect(html).toContain('Confirmed');
    expect(html).toContain('With Marisol Cruz');
    expect(html).not.toContain('Feb 10, 2999');
    expect(html).toContain('href="/student-portal?tab=appointments"');
  });

  it('offers booking when nothing upcoming is scheduled', () => {
    h.state.appointments = h.stub(h.APPOINTMENTS.pastOnly);

    const html = renderOverview();
    expect(html).toContain('No upcoming appointment');
    expect(html).toContain('href="/student-portal?tab=appointments"');
  });

  it('keeps the change-password entry point for local-password accounts', () => {
    expect(renderOverview()).toContain('href="/change-password"');
  });

  it('shows the managed-password note for university-managed accounts', () => {
    h.state.me = h.stub({ email: 'rafael.udtohan@foundationu.com', has_local_password: false });

    const html = renderOverview();
    expect(html).toContain('helpdesk@foundationu.com');
    expect(html).not.toContain('href="/change-password"');
  });
});
