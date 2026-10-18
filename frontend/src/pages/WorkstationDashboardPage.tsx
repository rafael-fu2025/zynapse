import { formatInTimeZone } from 'date-fns-tz';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/components/PageHeader';
import { TableStateRows } from '@/components/TableStates';
import { TrendLineChart, StatusDoughnutChart } from '@/components/reports/charts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useWorkstationDashboard } from '@/hooks/useWorkstationDashboard';
import { workstationFor } from '@/lib/workstations';
import type { WorkstationDashboard, WorkstationUnit } from '@/schemas/workstationDashboard';
import { hasPermission, useAuthStore } from '@/store/auth';
import { parseUtc } from '@/utils/date';
import { statusLabel } from '@/utils/status';

function manilaTime(value: string): string {
  return formatInTimeZone(parseUtc(value), 'Asia/Manila', 'MMM d, yyyy · h:mm:ss a');
}

function recentHref(unit: WorkstationUnit, id: number, canReadQueue: boolean): string | null {
  if (unit === 'facilities') return `/facilities/drums/${id}`;
  if (unit === 'clinic') return `/clinic?encounter=${id}`;
  // CounsellingPage removes session context when Queue is not accessible.
  return canReadQueue ? `/counselling?session=${id}` : null;
}

export default function WorkstationDashboardPage({ unit }: { unit: WorkstationUnit }) {
  const state = useAuthStore();
  const definition = workstationFor(unit);
  const query = useWorkstationDashboard(unit);
  const data = query.enabled ? query.data : undefined;
  const loading = query.enabled && query.isPending;
  const retry = () => { if (query.enabled) void query.refetch(); };
  const ownSessions = unit === 'guidance' && data?.scope === 'own';
  const recentTitle = ownSessions ? 'Your sessions' : data?.recent.title ?? `Recent ${definition.activityUnit}`;
  const rows = data?.recent.rows.slice(0, 6) ?? [];
  const actions = definition.actions.filter((action) => hasPermission(state, action.permission));
  const canReadGuidanceQueue = hasPermission(state, 'counselling.queue.read');
  const items = data?.breakdown.items ?? [];
  const total = items.reduce((sum, item) => sum + item.value, 0);
  const headers = unit === 'facilities'
    ? ['Drum', 'Name / device code', 'State', 'Device', 'Device last seen · Manila']
    : [unit === 'clinic' ? 'Encounter' : 'Session', 'Patient', 'Status', unit === 'clinic' ? 'Attending' : 'Counsellor', 'Started · Manila'];

  return (
    <main className="space-y-6 p-4 md:p-6">
      <PageHeader title={`${definition.label} dashboard`} actions={
        <>
          <span className="text-xs text-muted-foreground" role="status">
            {data ? `Updated ${manilaTime(data.generated_at)} · Manila` : 'No snapshot loaded'}
          </span>
          <Button variant="outline" disabled={!query.enabled || query.isFetching} onClick={retry}>
            {query.isFetching ? 'Refreshing…' : 'Refresh'}
          </Button>
        </>
      } />

      <section className="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-gradient-to-br from-[#ac1e37] to-[#751020] p-6 text-white shadow-sm" aria-labelledby="workstation-welcome">
        <div className="min-w-0">
          <h2 id="workstation-welcome" className="break-words text-xl font-semibold">Welcome back{state.email ? `, ${state.email}` : ''}!</h2>
          <p className="mt-1 text-sm text-white/85">{definition.welcome}</p>
          <p className="mt-2 text-xs text-white/80">{data ? `${data.day} · ` : ''}Asia/Manila · Refreshes every minute</p>
        </div>
        <span className="border border-white/30 bg-white/10 px-3 py-2 text-sm font-medium">{definition.label} workstation</span>
      </section>

      {!query.enabled && <p role="alert" className="border bg-card p-4 text-sm">Sign in with workstation access to load this dashboard.</p>}
      {query.isError && query.enabled && (
        <div role="alert" className="flex flex-wrap items-center justify-between gap-3 border border-destructive/40 bg-card p-4 text-sm">
          <p>{data ? 'Refresh failed. The last successful snapshot is still shown.' : `Unable to load the ${definition.label.toLowerCase()} dashboard. No metrics are available.`}</p>
          <Button variant="outline" size="sm" disabled={query.isFetching} onClick={retry}>Try again</Button>
        </div>
      )}

      <section aria-label={`${definition.label} key metrics`} className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {loading && Array.from({ length: 4 }, (_, index) => <Skeleton key={index} className="h-36 w-full" />)}
        {data?.metrics.map((metric) => <Metric key={metric.key} metric={metric} />)}
        {data?.metrics.length === 0 && <p className="text-sm text-muted-foreground sm:col-span-2 xl:col-span-4">No metrics are available for your permissions.</p>}
      </section>

      {ownSessions && <p className="text-sm text-muted-foreground"><strong className="text-foreground">Your sessions</strong> — session metrics, activity and recent records cover only sessions assigned to you. Queue and referral metrics, when available, use the scope described on each card.</p>}

      <div className="grid min-w-0 grid-cols-1 items-start gap-6 xl:grid-cols-3">
        <div className="min-w-0 space-y-6 xl:col-span-2">
          {(data || loading) && <TrendLineChart
            title={data?.trend.title ?? `${definition.label} activity`}
            subtitle={`${ownSessions ? 'Your sessions · ' : ''}${data?.trend.description ?? 'Last seven Manila calendar days, including today.'}`}
            unit={definition.activityUnit} points={data?.trend.points ?? []} loading={loading} area height={300}
          />}
          <section className="min-w-0 border bg-card" aria-labelledby="workstation-recent">
            <div className="border-b p-4">
              <h2 id="workstation-recent" className="text-sm font-semibold">{recentTitle}</h2>
              <p className="mt-1 text-xs text-muted-foreground">Up to six recent {unit === 'facilities' ? 'drums. Device last seen is not the drum update time or live motor state.' : `${definition.activityUnit} in scope.`}</p>
            </div>
            <Table ariaLabel={recentTitle}>
              <TableHeader>
                <TableRow>
                  <TableHead>{headers[0]}</TableHead>
                  <TableHead>{headers[1]}</TableHead>
                  <TableHead>{headers[2]}</TableHead>
                  <TableHead>{headers[3]}</TableHead>
                  <TableHead>{headers[4]}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableStateRows colSpan={5} isLoading={loading} isError={query.isError || !query.enabled} isEmpty={rows.length === 0} onRetry={retry} pending={query.isFetching}
                  empty={{ title: definition.emptyRecent }} errorMessage="Recent records are unavailable." />
                {rows.map((row) => {
                  const href = recentHref(unit, row.id, canReadGuidanceQueue);
                  return <TableRow key={row.id}>
                    <TableCell className="font-medium">{href ? <Link className="text-primary underline-offset-4 hover:underline focus-visible:underline" to={href}>{row.code}</Link> : row.code}</TableCell>
                    <TableCell><span className="font-medium">{row.title}</span>{row.subtitle && <span className="block text-xs text-muted-foreground">{row.subtitle}</span>}</TableCell>
                    <TableCell><Badge variant="secondary">{statusLabel(row.status)}</Badge></TableCell>
                    <TableCell>{row.owner ?? '—'}</TableCell>
                    <TableCell className="text-xs">{row.occurred_at ? manilaTime(row.occurred_at) : '—'}</TableCell>
                  </TableRow>;
                })}
              </TableBody>
            </Table>
          </section>
        </div>
        <aside className="min-w-0 space-y-6" aria-label={`${definition.label} status and actions`}>
          {(data || loading) && <StatusDoughnutChart
            title={data?.breakdown.title ?? `${definition.label} breakdown`}
            subtitle={`${ownSessions ? 'Your sessions · ' : ''}${data?.breakdown.description ?? 'Recorded states in scope.'}`}
            items={items} centerValue={data ? total.toLocaleString() : '—'} centerLabel="Recorded" loading={loading} height={300}
          />}
          <section className="border bg-card p-4" aria-labelledby="workstation-actions">
            <h2 id="workstation-actions" className="text-sm font-semibold">{definition.hub}</h2>
            <p className="mt-1 text-xs text-muted-foreground">Quick actions</p>
            <div className="mt-4 grid gap-2">
              {actions.map((action) => <Button key={action.href} asChild variant="outline" className="h-auto min-h-10 justify-start whitespace-normal text-left"><Link to={action.href}>{action.label}</Link></Button>)}
              {actions.length === 0 && <p className="text-sm text-muted-foreground">No quick actions are available for your permissions.</p>}
            </div>
          </section>
        </aside>
      </div>
    </main>
  );
}

function Metric({ metric }: { metric: WorkstationDashboard['metrics'][number] }) {
  const kilograms = metric.unit === 'kg';
  return (
    <article className="border border-t-2 border-t-[#ac1e37] bg-card p-4 shadow-sm">
      <h2 className="text-sm font-medium text-muted-foreground">{metric.label}</h2>
      <p className="mt-2 text-3xl font-semibold tabular-nums text-foreground">
        {metric.value.toLocaleString(undefined, kilograms ? { minimumFractionDigits: 1, maximumFractionDigits: 1 } : undefined)}
        {metric.unit && <span className="ml-1 text-sm font-normal text-muted-foreground">{metric.unit}</span>}
      </p>
      <p className="mt-2 text-xs leading-relaxed text-muted-foreground">{metric.description}</p>
    </article>
  );
}
