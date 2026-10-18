import { formatInTimeZone } from 'date-fns-tz';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/components/PageHeader';
import { BreakdownBarChart, StatusDoughnutChart, TrendLineChart } from '@/components/reports/charts';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useDashboardCounters } from '@/hooks/useDashboard';
import { useWorkstationDashboard } from '@/hooks/useWorkstationDashboard';
import { WORKSTATIONS, workstationFor } from '@/lib/workstations';
import WorkstationDashboardPage from '@/pages/WorkstationDashboardPage';
import type { WorkstationUnit } from '@/schemas/workstationDashboard';
import { hasPermission, useAuthStore } from '@/store/auth';
import { parseUtc } from '@/utils/date';

const SHORTCUTS = [
  ...WORKSTATIONS.map(({ label, path, permission }) => ({ label: `${label} workstation`, href: path, permission })),
  { label: 'Records', href: '/patients', permission: 'clinic.patients.read' },
  { label: 'Inventory', href: '/inventory', permission: 'clinic.inventory.read' },
  { label: 'Appointments', href: '/appointments', permission: 'clinic.appointments.read' },
  { label: 'Referrals', href: '/referrals', permission: 'referrals.read' },
  { label: 'Audit evidence', href: '/audit', permission: 'audit.read' },
  { label: 'Users', href: '/admin/users', permission: 'rbac.manage' },
];

export default function DashboardPage() {
  const state = useAuthStore();
  const accessible = WORKSTATIONS.filter(({ permission }) => hasPermission(state, permission));
  const workstation = accessible.length === 1 ? accessible[0] : undefined;
  if (workstation && !hasPermission(state, '*') && !hasPermission(state, 'rbac.manage') && !hasPermission(state, 'rbac.read')) {
    return <WorkstationDashboardPage unit={workstation.unit} />;
  }
  return <DashboardOverview />;
}

function DashboardOverview() {
  const state = useAuthStore();
  const counters = useDashboardCounters();
  const clinic = useWorkstationDashboard('clinic');
  const guidance = useWorkstationDashboard('guidance');
  const facilities = useWorkstationDashboard('facilities');
  const queries = { clinic, guidance, facilities };
  const accessible = WORKSTATIONS.filter(({ permission }) => hasPermission(state, permission));
  const enabledQueries = accessible.map(({ unit }) => queries[unit]).filter((query) => query.enabled);
  const fetching = counters.isFetching || enabledQueries.some((query) => query.isFetching);
  const refresh = () => {
    void counters.refetch();
    enabledQueries.forEach((query) => { void query.refetch(); });
  };
  const c = counters.data;
  const ownGuidance = !hasPermission(state, 'counselling.records.read_any');
  const metrics = [
    { permission: 'clinic.encounters.read', label: 'Open clinic encounters', value: c?.clinic?.open_encounters, description: 'Nonarchived encounters currently open.' },
    { permission: 'counselling.records.read', label: ownGuidance ? 'Your open guidance sessions' : 'Open guidance sessions', value: c?.counselling?.open_sessions, description: ownGuidance ? 'Only sessions assigned to you.' : 'Nonarchived sessions not yet ended.' },
    { permission: 'facilities.units.read', label: 'Processing drums', value: c?.facilities?.units_processing, description: 'Drums with a recorded processing lifecycle state.' },
    { permission: 'facilities.units.read', label: 'At-risk batches', value: c?.facilities?.at_risk, description: 'Active batches with unacknowledged alerts; each batch counted once.' },
    { permission: 'referrals.read', label: 'Pending referrals', value: c?.referrals ? c.referrals.submitted + c.referrals.acknowledged + c.referrals.under_review : undefined, description: 'Submitted, acknowledged or under review.' },
    { permission: 'audit.read', label: 'Audit events · 24h', value: c?.audit?.events_last_24h, description: 'Recorded system activity over the last rolling 24 hours.' },
  ].filter(({ permission }) => hasPermission(state, permission));

  return (
    <main className="min-w-0 space-y-6 p-4 md:p-6">
      <PageHeader title="Dashboard" description="System overview · Clinic, Guidance and Facilities" actions={
        <Button variant="outline" disabled={fetching} onClick={refresh}>{fetching ? 'Refreshing…' : 'Refresh overview'}</Button>
      } />
      <section className="border-l-4 border-primary bg-card p-5" aria-labelledby="overview-title">
        <h2 id="overview-title" className="text-lg font-semibold">University operations at a glance</h2>
        <p className="mt-1 text-sm text-muted-foreground">Live operational counts and seven-day activity across your accessible subsystems. Dates use Asia/Manila; data refreshes every minute.</p>
      </section>
      {counters.isError && <div role="alert" className="flex flex-wrap items-center justify-between gap-3 border border-destructive/40 bg-card p-4 text-sm">
        <p>{c ? 'Counter refresh failed. Previously loaded counts are still shown.' : 'System counters are unavailable. Missing counts are not shown as zero.'}</p>
        <Button size="sm" variant="outline" disabled={counters.isFetching} onClick={() => void counters.refetch()}>Retry counters</Button>
      </div>}
      <section aria-label="System key metrics" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        {metrics.map((metric) => <article key={metric.label} className="min-w-0 border border-t-2 border-t-primary bg-card p-4 shadow-sm">
          <h2 className="text-sm font-medium text-muted-foreground">{metric.label}</h2>
          {counters.isPending ? <Skeleton className="my-2 h-9 w-20" /> : <p className="mt-2 text-3xl font-semibold tabular-nums">{metric.value === undefined ? '—' : metric.value.toLocaleString()}</p>}
          <p className="mt-2 text-xs leading-relaxed text-muted-foreground">{metric.description}</p>
        </article>)}
      </section>
      {accessible.length > 0 && <section aria-labelledby="activity-title" className="space-y-3">
        <div>
          <h2 id="activity-title" className="text-lg font-semibold">Subsystem activity</h2>
          <p className="text-sm text-muted-foreground">Last seven Manila calendar days, including today. Each subsystem uses its own activity measure.</p>
        </div>
        <div className="grid min-w-0 grid-cols-1 items-start gap-4 xl:grid-cols-3">
          {accessible.map(({ unit }) => <SubsystemOverview key={unit} unit={unit} query={queries[unit]} />)}
        </div>
      </section>}
      {hasPermission(state, 'referrals.read') && <section aria-label="Referral workload">
        {c?.referrals ? <BreakdownBarChart title="Referral workflow" subtitle="Nonarchived referrals by current status; all-time records in your tenant." horizontal height={240} unit="referrals" items={[
          { label: 'Submitted', value: c.referrals.submitted },
          { label: 'Acknowledged', value: c.referrals.acknowledged },
          { label: 'Under review', value: c.referrals.under_review },
          { label: 'Closed', value: c.referrals.closed },
        ]} /> : counters.isPending ? <Skeleton className="h-72 w-full" /> : <p className="border bg-card p-4 text-sm text-muted-foreground">Referral visualization unavailable.</p>}
      </section>}
      {accessible.length === 0 && metrics.length === 0 && <p className="border bg-card p-4 text-sm text-muted-foreground">No system metrics are available for your current permissions.</p>}
      <details className="border bg-card p-4">
        <summary className="cursor-pointer text-sm font-semibold">Module shortcuts</summary>
        <nav aria-label="Module shortcuts" className="mt-3 flex flex-wrap gap-2">
          {SHORTCUTS.filter(({ permission }) => hasPermission(state, permission)).map(({ href, label }) => <Button key={href} asChild size="sm" variant="outline"><Link to={href}>{label}</Link></Button>)}
        </nav>
      </details>
    </main>
  );
}

function SubsystemOverview({ unit, query }: { unit: WorkstationUnit; query: ReturnType<typeof useWorkstationDashboard> }) {
  const definition = workstationFor(unit);
  const data = query.enabled ? query.data : undefined;
  const loading = query.enabled && query.isPending;
  const own = data?.scope === 'own';
  const total = data?.breakdown.items.reduce((sum, item) => sum + item.value, 0) ?? 0;
  return <article className="min-w-0 space-y-3" aria-label={`${definition.label} analytics`}>
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h3 className="font-semibold">{definition.label}</h3>
      <Link to={definition.path} className="text-xs text-primary underline-offset-4 hover:underline">Open workstation</Link>
    </div>
    {data && <p className="text-xs text-muted-foreground">Snapshot: {formatInTimeZone(parseUtc(data.generated_at), 'Asia/Manila', 'MMM d, h:mm a')} · Manila{own ? ' · Your sessions only' : ''}</p>}
    {query.isError && <div role="alert" className="space-y-2 border border-destructive/40 bg-card p-4 text-sm">
      <p>{data ? `${definition.label} refresh failed; the previous snapshot is shown.` : `${definition.label} analytics could not be loaded.`}</p>
      <Button size="sm" variant="outline" disabled={query.isFetching} onClick={() => void query.refetch()}>Retry {definition.label.toLowerCase()}</Button>
    </div>}
    {(loading || data) && <>
      <TrendLineChart title={`${definition.label} · ${data?.trend.title ?? 'activity'}`} subtitle={`${own ? 'Your sessions only. ' : ''}${data?.trend.description ?? 'Loading seven-day activity…'}`} unit={definition.activityUnit} points={data?.trend.points ?? []} loading={loading} area height={240} />
      <StatusDoughnutChart title={`${definition.label} · ${data?.breakdown.title ?? 'breakdown'}`} subtitle={`${own ? 'Your sessions only. ' : ''}${data?.breakdown.description ?? 'Loading recorded states…'}`} items={data?.breakdown.items ?? []} centerValue={data ? total.toLocaleString() : '—'} centerLabel="Recorded" loading={loading} height={260} />
    </>}
  </article>;
}
