import type { ComponentProps } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { BreakdownBarChart, StatusDoughnutChart, TrendLineChart } from '@/components/reports/charts';
import type { DashboardCounters } from '@/hooks/useDashboard';
import type * as AuthModule from '@/store/auth';
import { workstationDashboardSchema, type WorkstationDashboard, type WorkstationUnit } from '@/schemas/workstationDashboard';
import DashboardPage from './DashboardPage';

const h = vi.hoisted(() => {
  const query = <T,>(data: T | undefined, options: { isError?: boolean; isPending?: boolean; isFetching?: boolean; enabled?: boolean } = {}) => ({
    data,
    isError: false,
    isPending: false,
    isFetching: false,
    enabled: true,
    refetch: vi.fn(),
    ...options,
  });
  return {
    query,
    auth: { permissions: ['*'] as string[] },
    counters: vi.fn<() => ReturnType<typeof query<DashboardCounters>>>(),
    workstation: vi.fn<(unit: WorkstationUnit) => ReturnType<typeof query<WorkstationDashboard>>>(),
    queries: {} as Record<WorkstationUnit, ReturnType<typeof query<WorkstationDashboard>>>,
  };
});

vi.mock('@/store/auth', async (importOriginal) => ({
  ...await importOriginal<typeof AuthModule>(),
  useAuthStore: () => h.auth,
}));
vi.mock('@/hooks/useDashboard', () => ({ useDashboardCounters: h.counters }));
vi.mock('@/hooks/useWorkstationDashboard', () => ({ useWorkstationDashboard: h.workstation }));
// Do not load the dedicated dashboard's dialogs, charts or data dependencies.
vi.mock('@/pages/WorkstationDashboardPage', () => ({
  default: ({ unit }: { unit: WorkstationUnit }) => <main aria-label="Own workstation">{unit} staff dashboard</main>,
}));

// Node SSR tests exercise the page's chart inputs, not Chart.js or canvas.
vi.mock('@/components/reports/charts', () => ({
  TrendLineChart: ({ title, subtitle, unit, points }: ComponentProps<typeof TrendLineChart>) => (
    <figure aria-label={title}>
      <figcaption>{title}</figcaption><p>{subtitle}</p>
      <ul>{points.map(({ day, value }) => <li key={day}>{day}: {value} {unit}</li>)}</ul>
    </figure>
  ),
  StatusDoughnutChart: ({ title, subtitle, items, centerValue, centerLabel }: ComponentProps<typeof StatusDoughnutChart>) => (
    <figure aria-label={title}>
      <figcaption>{title}</figcaption><p>{subtitle}</p><p>{centerLabel}: {centerValue}</p>
      <ul>{items.map(({ label, value }) => <li key={label}>{label}: {value}</li>)}</ul>
    </figure>
  ),
  BreakdownBarChart: ({ title, subtitle, items, unit }: ComponentProps<typeof BreakdownBarChart>) => (
    <figure aria-label={title}>
      <figcaption>{title}</figcaption><p>{subtitle}</p>
      <ul>{items.map(({ label, value }) => <li key={label}>{label}: {value} {unit}</li>)}</ul>
    </figure>
  ),
}));

const COUNTERS: DashboardCounters = {
  clinic: { open_encounters: 17, closed_encounters: 41 },
  counselling: { open_sessions: 9, closed_sessions: 23 },
  facilities: { units_idle: 5, units_processing: 6, units_awaiting: 2, at_risk: 3 },
  referrals: { submitted: 4, acknowledged: 7, under_review: 2, closed: 19 },
  audit: { events_last_24h: 1234 },
};
const DAYS = ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18'];

function snapshot(unit: WorkstationUnit): WorkstationDashboard {
  const definitions = {
    clinic: { trend: 'Encounter starts', breakdown: 'Triage priorities', description: 'Nonarchived encounters with a recorded triage priority; unassessed encounters excluded.', kind: 'encounters', values: [3, 5, 0, 7, 2, 6, 4], items: [{ key: 'urgent', label: 'Urgent', value: 8 }, { key: 'routine', label: 'Routine', value: 21 }] },
    guidance: { trend: 'Session starts', breakdown: 'Session states', description: 'Nonarchived open and closed sessions in the session scope.', kind: 'sessions', values: [1, 2, 0, 4, 3, 2, 5], items: [{ key: 'open', label: 'Open', value: 9 }, { key: 'closed', label: 'Closed', value: 23 }] },
    facilities: { trend: 'Batch starts', breakdown: 'Drum lifecycle states', description: 'Persisted unit states; not live motor telemetry.', kind: 'drums', values: [0, 1, 2, 0, 3, 1, 2], items: [{ key: 'idle', label: 'Idle', value: 5 }, { key: 'processing', label: 'Processing', value: 6 }, { key: 'awaiting', label: 'Awaiting', value: 2 }] },
  };
  const definition = definitions[unit];
  return workstationDashboardSchema.parse({
    unit, day: '2026-10-18', generated_at: '2026-10-18T03:45:00Z', scope: 'tenant',
    metrics: [],
    trend: {
      title: definition.trend,
      description: 'Last seven Manila calendar days, including today; nonarchived records in scope.',
      points: DAYS.map((day, index) => ({ day, value: definition.values[index] })),
    },
    breakdown: { title: definition.breakdown, description: definition.description, items: definition.items },
    recent: { kind: definition.kind, title: 'Recent activity', rows: [] },
  });
}

function renderDashboard(): string {
  return renderToStaticMarkup(<MemoryRouter initialEntries={['/dashboard']}><DashboardPage /></MemoryRouter>);
}

function metric(html: string, label: string): string {
  const article = html.match(/<article\b[^>]*>[\s\S]*?<\/article>/g)?.find((item) => item.includes(`>${label}</h2>`));
  expect(article, `Metric ${label} should be visible`).toBeDefined();
  return article!;
}

function chart(html: string, title: string): string {
  const figure = html.match(/<figure\b[^>]*>[\s\S]*?<\/figure>/g)?.find((item) => item.includes(`aria-label="${title}"`));
  expect(figure, `Chart ${title} should be visible`).toBeDefined();
  return figure!;
}

beforeEach(() => {
  // MemoryRouter's SSR layout-effect warning is expected; do not hide other errors.
  const realConsoleError = console.error;
  vi.spyOn(console, 'error').mockImplementation((...args: unknown[]) => {
    if (String(args[0]).startsWith('Warning: useLayoutEffect does nothing on the server')) return;
    realConsoleError(...args);
  });
  h.auth.permissions = ['*'];
  h.counters.mockReset().mockReturnValue(h.query(COUNTERS));
  h.queries = {
    clinic: h.query(snapshot('clinic')),
    guidance: h.query(snapshot('guidance')),
    facilities: h.query(snapshot('facilities')),
  };
  h.workstation.mockReset().mockImplementation((unit) => h.queries[unit]);
});
afterEach(() => vi.restoreAllMocks());

describe('DashboardPage system overview', () => {
  it('shows admin counters from the snapshot, including pending-referral aggregation', () => {
    const html = renderDashboard();
    expect(html).toContain('University operations at a glance');
    expect(html).toContain('System key metrics');
    for (const [label, value] of [
      ['Open clinic encounters', '17'], ['Open guidance sessions', '9'],
      ['Processing drums', '6'], ['At-risk batches', '3'],
      ['Pending referrals', '13'], ['Audit events · 24h', (1234).toLocaleString()],
    ]) expect(metric(html, label!)).toContain(`>${value}</p>`);
    expect(html).not.toContain('Your open guidance sessions');
    expect(html).not.toContain('Own workstation');
  });

  it('passes seven Manila-day values and recorded-state totals to subsystem visualizations', () => {
    const html = renderDashboard();
    for (const [unit, label, activityUnit] of [
      ['clinic', 'Clinic', 'encounters'], ['guidance', 'Guidance', 'sessions'], ['facilities', 'Facilities', 'batches'],
    ] as const) {
      const data = h.queries[unit].data!;
      expect(html).toContain(`aria-label="${label} analytics"`);
      const trend = chart(html, `${label} · ${data.trend.title}`);
      for (const point of data.trend.points) expect(trend).toContain(`${point.day}: ${point.value} ${activityUnit}`);
      const breakdown = chart(html, `${label} · ${data.breakdown.title}`);
      for (const item of data.breakdown.items) expect(breakdown).toContain(`${item.label}: ${item.value}`);
      expect(breakdown).toContain(`Recorded: ${data.breakdown.items.reduce((sum, item) => sum + item.value, 0)}`);
      expect(breakdown).toContain(data.breakdown.description);
    }
    expect(html).toContain('Snapshot: Oct 18, 11:45 AM · Manila');
    const referrals = chart(html, 'Referral workflow');
    for (const text of ['Submitted: 4 referrals', 'Acknowledged: 7 referrals', 'Under review: 2 referrals', 'Closed: 19 referrals']) expect(referrals).toContain(text);
  });

  it('keeps module shortcuts secondary in a collapsed disclosure after analytics', () => {
    const html = renderDashboard();
    expect(html).toMatch(/<details\s[^>]*><summary[^>]*>Module shortcuts<\/summary>/);
    expect(html).not.toMatch(/<details\b[^>]*\bopen(?:[\s=>])/);
    expect(html.indexOf('<details')).toBeGreaterThan(html.indexOf('aria-label="Referral workflow"'));
    for (const path of ['/clinic/dashboard', '/counselling/dashboard', '/facilities/dashboard', '/patients', '/inventory', '/appointments', '/referrals', '/audit', '/admin/users']) expect(html).toContain(`href="${path}"`);
  });

  it('hides unauthorized domains, metrics, referral charts and shortcuts even when mock data exists', () => {
    h.auth.permissions = ['rbac.read', 'clinic.encounters.read'];
    const html = renderDashboard();
    expect(html).toContain('aria-label="Clinic analytics"');
    expect(metric(html, 'Open clinic encounters')).toContain('>17</p>');
    for (const text of ['Guidance analytics', 'Facilities analytics', 'guidance sessions', 'Processing drums', 'At-risk batches', 'Pending referrals', 'Audit events · 24h', 'Referral workload', 'Referral workflow']) expect(html).not.toContain(text);
    for (const path of ['/counselling/dashboard', '/facilities/dashboard', '/patients', '/inventory', '/appointments', '/referrals', '/audit', '/admin/users']) expect(html).not.toContain(`href="${path}"`);
    expect(html).toContain('href="/clinic/dashboard"');
  });

  it('shows no system metrics when no domain permissions are granted', () => {
    h.auth.permissions = ['rbac.read'];
    const html = renderDashboard();
    expect(html).toContain('No system metrics are available for your current permissions.');
    expect(html).not.toContain('<figure');
    expect(html).not.toContain('Subsystem activity');
    expect(html).not.toContain('Open clinic encounters');
  });

  it('labels Guidance counters and both charts as own-scope without read-any permission', () => {
    h.auth.permissions = ['clinic.encounters.read', 'counselling.records.read'];
    h.queries.guidance.data!.scope = 'own';
    const html = renderDashboard();
    const ownMetric = metric(html, 'Your open guidance sessions');
    expect(ownMetric).toContain('>9</p>');
    expect(ownMetric).toContain('Only sessions assigned to you.');
    expect(html).toContain('Manila · Your sessions only');
    expect(chart(html, 'Guidance · Session starts')).toContain('Your sessions only. Last seven Manila calendar days');
    expect(chart(html, 'Guidance · Session states')).toContain('Your sessions only. Nonarchived open and closed sessions');
  });

  it('renders failed requests as unavailable rather than fabricated zero counts or empty charts', () => {
    h.counters.mockReturnValue(h.query<DashboardCounters>(undefined, { isError: true }));
    for (const unit of ['clinic', 'guidance', 'facilities'] as const) h.queries[unit] = h.query<WorkstationDashboard>(undefined, { isError: true });
    const html = renderDashboard();
    expect(html).toContain('System counters are unavailable. Missing counts are not shown as zero.');
    expect(html).toContain('Retry counters');
    for (const label of ['Open clinic encounters', 'Open guidance sessions', 'Processing drums', 'At-risk batches', 'Pending referrals', 'Audit events · 24h']) {
      expect(metric(html, label)).toContain('>—</p>');
      expect(metric(html, label)).not.toContain('>0</p>');
    }
    for (const label of ['Clinic', 'Guidance', 'Facilities']) {
      expect(html).toContain(`${label} analytics could not be loaded.`);
      expect(html).toContain(`Retry ${label.toLowerCase()}`);
    }
    expect(html).toContain('Referral visualization unavailable.');
    expect(html).not.toContain('<figure');
    expect(html.match(/role="alert"/g)).toHaveLength(4);
  });

  it('retains previous counts and charts when a background refresh fails', () => {
    h.counters.mockReturnValue(h.query(COUNTERS, { isError: true }));
    h.queries.guidance.isError = true;
    const html = renderDashboard();
    expect(html).toContain('Counter refresh failed. Previously loaded counts are still shown.');
    expect(metric(html, 'Pending referrals')).toContain('>13</p>');
    expect(html).toContain('Guidance refresh failed; the previous snapshot is shown.');
    expect(chart(html, 'Guidance · Session states')).toContain('Recorded: 32');
    expect(html).not.toContain('Guidance analytics could not be loaded.');
  });

  it.each([
    ['clinic', 'clinic.encounters.read'],
    ['guidance', 'counselling.records.read'],
    ['facilities', 'facilities.units.read'],
  ] as const)('keeps single-workstation %s staff on their own dashboard', (unit, permission) => {
    h.auth.permissions = [permission];
    const html = renderDashboard();
    expect(html).toContain('aria-label="Own workstation"');
    expect(html).toContain(`${unit} staff dashboard`);
    expect(html).not.toContain('University operations at a glance');
    expect(h.counters).not.toHaveBeenCalled();
    expect(h.workstation).not.toHaveBeenCalled();
  });

  it.each(['rbac.read', 'rbac.manage'])('keeps single-domain administrators with %s on the system overview', (permission) => {
    h.auth.permissions = ['clinic.encounters.read', permission];
    const html = renderDashboard();
    expect(html).toContain('University operations at a glance');
    expect(html).not.toContain('Own workstation');
  });
});
