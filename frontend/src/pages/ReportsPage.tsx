import {
  Download,
  Loader2,
  Minus,
  Sparkles,
  TrendingDown,
  TrendingUp,
} from 'lucide-react';
import { subDays } from 'date-fns';
import { formatInTimeZone } from 'date-fns-tz';
import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { QueryErrorState } from '@/components/QueryErrorState';
import { ReportDataTable, type ReportTableRow } from '@/components/reports/ReportDataTable';
import { SavedReportsSection } from '@/components/reports/SavedReportsSection';
import { TrendChart } from '@/components/reports/TrendChart';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  useClinicReport,
  useCounsellingReport,
  useFacilitiesReport,
  useInventoryReport,
  useReferralReport,
  useReportExport,
  useReportNarrative,
  useReportSummary,
} from '@/hooks/useReports';
import {
  REPORT_MODULES,
  reportModuleSchema,
  reportRangeSchema,
  type ReportModule,
  type ReportNarrative,
} from '@/schemas/reports';
import { hasPermission, useAuthStore } from '@/store/auth';
import { fmtUtcToApp } from '@/utils/date';

const APP_TIMEZONE = 'Asia/Manila';

function defaultRange(): { start: string; end: string } {
  const now = new Date();
  return {
    start: formatInTimeZone(subDays(now, 29), APP_TIMEZONE, 'yyyy-MM-dd'),
    end: formatInTimeZone(now, APP_TIMEZONE, 'yyyy-MM-dd'),
  };
}

function isValidRange(start: string, end: string): boolean {
  return reportRangeSchema.safeParse({ start, end }).success;
}

function moduleLabel(module: ReportModule): string {
  return module.charAt(0).toUpperCase() + module.slice(1);
}

function rows(input: Array<Array<string | number>>, prefix: string): ReportTableRow[] {
  return input.map((cells) => ({ id: prefix + ':' + cells.join(':'), cells }));
}

function Delta({ value }: { value: number | null }) {
  if (value === null) return <span className="text-xs text-muted-foreground">No prior baseline</span>;
  if (value === 0) return <span className="inline-flex items-center gap-1 text-xs text-muted-foreground"><Minus className="size-3" /> No change</span>;
  const Icon = value > 0 ? TrendingUp : TrendingDown;
  return (
    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
      <Icon className="size-3" /> {value > 0 ? '+' : ''}{value}% from prior period
    </span>
  );
}

function Metric({
  label,
  value,
  detail,
  delta,
}: {
  label: string;
  value: number;
  detail: string;
  delta: number | null;
}) {
  return (
    <div className="min-w-0 px-4 py-3">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-xl font-semibold tabular-nums text-foreground">{value.toLocaleString()}</dd>
      <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
      <Delta value={delta} />
    </div>
  );
}

export default function ReportsPage() {
  const [params, setParams] = useSearchParams();
  const defaults = useMemo(defaultRange, []);
  const parsedTab = reportModuleSchema.safeParse(params.get('tab') ?? 'clinic');
  const tab: ReportModule = parsedTab.success ? parsedTab.data : 'clinic';
  const requestedStart = params.get('start') ?? defaults.start;
  const requestedEnd = params.get('end') ?? defaults.end;
  const rangeValid = isValidRange(requestedStart, requestedEnd);
  const start = rangeValid ? requestedStart : defaults.start;
  const end = rangeValid ? requestedEnd : defaults.end;
  const [draftRange, setDraftRange] = useState({ start, end });
  const auth = useAuthStore();
  const canExport = hasPermission(auth, 'reports.export');
  const canConfigure = hasPermission(auth, 'reports.configure');

  useEffect(() => {
    if (
      !parsedTab.success
      || !rangeValid
      || params.get('start') !== start
      || params.get('end') !== end
    ) {
      const canonical = new URLSearchParams(params);
      if (tab === 'clinic') canonical.delete('tab');
      else canonical.set('tab', tab);
      canonical.set('start', start);
      canonical.set('end', end);
      setParams(canonical, { replace: true });
    }
  }, [end, params, parsedTab.success, rangeValid, setParams, start, tab]);

  useEffect(() => setDraftRange({ start, end }), [start, end]);

  const summary = useReportSummary(start, end);
  const clinic = useClinicReport(start, end, tab === 'clinic');
  const counselling = useCounsellingReport(start, end, tab === 'counselling');
  const inventory = useInventoryReport(start, end, tab === 'inventory');
  const referrals = useReferralReport(start, end, tab === 'referrals');
  const facilities = useFacilitiesReport(start, end, tab === 'facilities');
  const exporter = useReportExport();
  const narrative = useReportNarrative();
  const [narratives, setNarratives] = useState<Record<string, ReportNarrative>>({});
  const narrativeKey = tab + ':' + start + ':' + end;

  const activeQuery = tab === 'clinic'
    ? clinic
    : tab === 'counselling'
      ? counselling
      : tab === 'inventory'
        ? inventory
        : tab === 'referrals'
          ? referrals
          : facilities;

  function setTab(next: string): void {
    const parsed = reportModuleSchema.safeParse(next);
    if (!parsed.success) return;
    const nextParams = new URLSearchParams(params);
    if (parsed.data === 'clinic') nextParams.delete('tab');
    else nextParams.set('tab', parsed.data);
    setParams(nextParams, { replace: true });
  }

  function commitRange(nextStart: string, nextEnd: string): void {
    if (!isValidRange(nextStart, nextEnd)) return;
    const nextParams = new URLSearchParams(params);
    nextParams.set('start', nextStart);
    nextParams.set('end', nextEnd);
    setParams(nextParams, { replace: true });
  }

  function generateSummary(): void {
    narrative.mutate(
      { module: tab, start, end },
      { onSuccess: (result) => setNarratives((current) => ({ ...current, [narrativeKey]: result })) },
    );
  }

  function exportCurrent(): void {
    exporter.mutate({ module: tab, start, end });
  }

  const currentNarrative = narratives[narrativeKey];

  return (
    <main className="mx-auto max-w-7xl space-y-6 px-3 py-4 sm:px-5 sm:py-6">
      <header className="flex flex-col gap-4 border-b pb-5 lg:flex-row lg:items-end lg:justify-between">
        <div className="min-w-0">
          <h1 className="text-xl font-semibold text-foreground">Reports and analytics</h1>
          <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
            Asia/Manila calendar dates. Analytics exports contain aggregated, privacy-reviewed data and every download is audited.
          </p>
        </div>
        <div className="w-full space-y-1 lg:w-auto">
          <Label htmlFor="report-range">Date range</Label>
          <DateRangePicker
            id="report-range"
            start={draftRange.start}
            end={draftRange.end}
            toYear={new Date().getFullYear()}
            onChange={({ start: nextStart, end: nextEnd }) => {
              setDraftRange({ start: nextStart, end: nextEnd });
              if (nextStart !== '' && nextEnd !== '') commitRange(nextStart, nextEnd);
            }}
            className="min-h-10 w-full lg:w-[310px]"
          />
          <p className="text-xs text-muted-foreground">{start} to {end}, maximum 366 days</p>
        </div>
      </header>

      <section aria-labelledby="overview-heading" className="overflow-hidden rounded-xl border bg-card">
        <div className="border-b px-4 py-3">
          <h2 id="overview-heading" className="text-sm font-semibold">Institution overview</h2>
          <p className="text-xs text-muted-foreground">Compared with the immediately preceding period of equal length.</p>
        </div>
        {summary.isError ? (
          <div className="p-4">
            <QueryErrorState message="Failed to load the analytics overview. Values are unknown, not zero." onRetry={() => void summary.refetch()} pending={summary.isFetching} />
          </div>
        ) : summary.isLoading ? (
          <div className="grid gap-px bg-border sm:grid-cols-2 xl:grid-cols-5" role="status">
            {REPORT_MODULES.map((module) => (
              <div key={module} className="space-y-2 bg-card p-4">
                <Skeleton className="h-3 w-24" />
                <Skeleton className="h-7 w-16" />
                <Skeleton className="h-3 w-32" />
              </div>
            ))}
            <span className="sr-only">Loading overview metrics.</span>
          </div>
        ) : summary.data !== undefined ? (
          <>
            <dl className="grid divide-y sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-5">
              <Metric label="Clinic encounters" value={summary.data.clinic.encounters} detail={summary.data.clinic.checkins + ' kiosk check-ins'} delta={summary.data.clinic.encounters_delta_pct} />
              <Metric label="Counselling appointments" value={summary.data.counselling.appointments} detail={summary.data.counselling.sessions + ' sessions opened'} delta={summary.data.counselling.appointments_delta_pct} />
              <Metric label="Units dispensed" value={summary.data.inventory.dispensed_qty} detail={summary.data.inventory.active_batches + ' active batches now'} delta={summary.data.inventory.dispensed_delta_pct} />
              <Metric label="Referrals created" value={summary.data.referrals.created} detail="New referral activity" delta={summary.data.referrals.created_delta_pct} />
              <Metric label="Facilities batches completed" value={summary.data.facilities.completed_batches} detail="Completion activity" delta={summary.data.facilities.completed_delta_pct} />
            </dl>
            <p className="border-t px-4 py-2 text-xs text-muted-foreground">
              Current inventory snapshot retrieved {fmtUtcToApp(summary.data.snapshot_at)}.
            </p>
          </>
        ) : null}
      </section>

      <Tabs value={tab} onValueChange={setTab}>
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <TabsList aria-label="Analytics module">
            {REPORT_MODULES.map((module) => <TabsTrigger key={module} value={module}>{moduleLabel(module)}</TabsTrigger>)}
          </TabsList>
          <div className="flex flex-wrap items-center gap-2">
            {activeQuery.isFetching && !activeQuery.isLoading && (
              <span role="status" className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" /> Refreshing
              </span>
            )}
            {canConfigure && (
              <Button size="sm" variant="outline" onClick={generateSummary} disabled={narrative.isPending}>
                {narrative.isPending ? <Loader2 className="animate-spin" /> : <Sparkles />} Generate narrative
              </Button>
            )}
            {canExport && (
              <Button size="sm" variant="outline" onClick={exportCurrent} disabled={exporter.isPending}>
                {exporter.isPending ? <Loader2 className="animate-spin" /> : <Download />} Export {moduleLabel(tab)}
              </Button>
            )}
          </div>
        </div>

        {currentNarrative !== undefined && (
          <section className="mt-4 rounded-xl border bg-muted/30 p-4" aria-labelledby="narrative-heading" aria-live="polite">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <h2 id="narrative-heading" className="text-sm font-semibold">{moduleLabel(tab)} narrative</h2>
              <span className="text-xs text-muted-foreground">{currentNarrative.range.start} to {currentNarrative.range.end} · generated {fmtUtcToApp(currentNarrative.generated_at)}</span>
            </div>
            <p className="mt-2 max-w-4xl text-sm leading-relaxed text-foreground">{currentNarrative.narrative}</p>
            <p className="mt-2 text-xs text-muted-foreground">Deterministic template summary. No external AI model is used.</p>
          </section>
        )}

        <TabsContent value="clinic" className="space-y-4">
          {clinic.isError && clinic.data === undefined ? (
            <QueryErrorState message="Failed to load clinic analytics." onRetry={() => void clinic.refetch()} pending={clinic.isFetching} />
          ) : (
            <>
              <p className="text-sm text-muted-foreground">{clinic.data?.total_encounters.toLocaleString() ?? 'Loading'} encounters in the selected range.</p>
              <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                <TrendChart title="Encounter trend" unit="encounters" loading={clinic.isLoading} points={(clinic.data?.daily_trend ?? []).map((point) => ({ day: point.day, value: point.cnt }))} />
                <ReportDataTable title="Encounter status" columns={['Status', 'Count']} loading={clinic.isLoading} error={clinic.isError} fetching={clinic.isFetching} onRetry={() => void clinic.refetch()} rows={rows((clinic.data?.status_breakdown ?? []).map((item) => [item.status.replace(/_/g, ' '), item.cnt]), 'clinic-status')} />
                <ReportDataTable title="Privacy-safe complaint categories" columns={['Category', 'Count']} loading={clinic.isLoading} rows={rows((clinic.data?.complaint_categories ?? []).map((item) => [item.category, item.cnt]), 'clinic-complaint')} />
                <ReportDataTable title="Kiosk outcomes" columns={['Outcome', 'Count']} loading={clinic.isLoading} rows={rows((clinic.data?.checkin_outcomes ?? []).map((item) => [item.outcome.replace(/_/g, ' '), item.cnt]), 'clinic-checkin')} />
                <ReportDataTable title="Referral flows" columns={['Source', 'Target', 'Status', 'Count']} loading={clinic.isLoading} rows={rows((clinic.data?.referral_flows ?? []).map((item) => [item.source_module, item.target_module, item.status.replace(/_/g, ' '), item.cnt]), 'clinic-referral')} />
              </div>
            </>
          )}
        </TabsContent>

        <TabsContent value="counselling" className="space-y-4">
          {counselling.isError && counselling.data === undefined ? (
            <QueryErrorState message="Failed to load counselling analytics." onRetry={() => void counselling.refetch()} pending={counselling.isFetching} />
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <span>{counselling.data?.total_appointments.toLocaleString() ?? 'Loading'} appointments in range.</span>
                {counselling.data !== undefined && <Badge variant="secondary">No-show rate {counselling.data.no_show_rate}%</Badge>}
              </div>
              <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                <TrendChart title="Appointment trend" unit="appointments" loading={counselling.isLoading} points={(counselling.data?.daily_trend ?? []).map((point) => ({ day: point.day, value: point.cnt }))} />
                <ReportDataTable title="Appointment status" columns={['Status', 'Count']} loading={counselling.isLoading} rows={rows((counselling.data?.status_breakdown ?? []).map((item) => [item.status.replace(/_/g, ' '), item.cnt]), 'counselling-status')} />
                <ReportDataTable title="Appointment type" columns={['Type', 'Count']} loading={counselling.isLoading} rows={rows((counselling.data?.type_breakdown ?? []).map((item) => [item.type.replace(/_/g, ' '), item.cnt]), 'counselling-type')} />
              </div>
            </>
          )}
        </TabsContent>

        <TabsContent value="inventory" className="space-y-5">
          {inventory.isError && inventory.data === undefined ? (
            <QueryErrorState message="Failed to load inventory analytics." onRetry={() => void inventory.refetch()} pending={inventory.isFetching} />
          ) : (
            <>
              <section className="space-y-3" aria-labelledby="inventory-current-heading">
                <div>
                  <h2 id="inventory-current-heading" className="text-base font-semibold">Current inventory health</h2>
                  <p className="text-xs text-muted-foreground">
                    Live snapshot{inventory.data !== undefined ? ' retrieved ' + fmtUtcToApp(inventory.data.snapshot_at) : ''}. It is not a historical balance.
                  </p>
                </div>
                <div className="grid min-w-0 gap-4 xl:grid-cols-3">
                  <ReportDataTable title="Low stock now" columns={['Medicine', 'On hand', 'Threshold']} loading={inventory.isLoading} rows={rows((inventory.data?.low_stock ?? []).map((item) => [item.generic_name + (item.brand_name !== null ? ' (' + item.brand_name + ')' : ''), item.total_stock + ' ' + item.unit, item.reorder_threshold]), 'inventory-low')} />
                  <ReportDataTable title="Expired stock now" columns={['Medicine', 'Batch', 'Remaining', 'Expired']} loading={inventory.isLoading} rows={rows((inventory.data?.expired ?? []).map((item) => [item.generic_name, item.batch_number, item.quantity_remaining + ' ' + item.unit, item.expiration_date]), 'inventory-expired')} emptyMessage="No expired active stock." />
                  <ReportDataTable title="Expiring in the next 90 days" columns={['Medicine', 'Batch', 'Remaining', 'Expires']} loading={inventory.isLoading} rows={rows((inventory.data?.expiring ?? []).map((item) => [item.generic_name, item.batch_number, item.quantity_remaining + ' ' + item.unit, item.expiration_date]), 'inventory-expiring')} emptyMessage="No active batches expire in the next 90 days." />
                </div>
              </section>
              <section className="space-y-3" aria-labelledby="inventory-activity-heading">
                <div>
                  <h2 id="inventory-activity-heading" className="text-base font-semibold">Dispensing activity</h2>
                  <p className="text-sm text-muted-foreground">{inventory.data?.total_dispensed.toLocaleString() ?? 'Loading'} units dispensed from {start} to {end}.</p>
                </div>
                <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                  <TrendChart title="Dispensing trend" unit="units" loading={inventory.isLoading} points={(inventory.data?.dispensing_trend ?? []).map((point) => ({ day: point.day, value: point.qty }))} />
                  <ReportDataTable title="Top dispensed medicines" columns={['Medicine', 'Quantity']} loading={inventory.isLoading} rows={rows((inventory.data?.top_dispensed ?? []).map((item) => [item.generic_name + (item.brand_name !== null ? ' (' + item.brand_name + ')' : ''), item.qty + ' ' + item.unit]), 'inventory-top')} />
                </div>
              </section>
            </>
          )}
        </TabsContent>

        <TabsContent value="referrals" className="space-y-4">
          {referrals.isError && referrals.data === undefined ? (
            <QueryErrorState message="Failed to load referral analytics." onRetry={() => void referrals.refetch()} pending={referrals.isFetching} />
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <span>{referrals.data?.total_referrals.toLocaleString() ?? 'Loading'} referrals created.</span>
                {referrals.data !== undefined && <Badge variant="secondary">Closure rate {referrals.data.closed_rate}%</Badge>}
              </div>
              <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                <TrendChart title="Referral trend" unit="referrals" loading={referrals.isLoading} points={(referrals.data?.daily_trend ?? []).map((point) => ({ day: point.day, value: point.cnt }))} />
                <ReportDataTable title="Referral status" columns={['Status', 'Count']} loading={referrals.isLoading} rows={rows((referrals.data?.status_breakdown ?? []).map((item) => [item.status.replace(/_/g, ' '), item.cnt]), 'referrals-status')} />
                <ReportDataTable title="Referral direction" columns={['Source', 'Target', 'Count']} loading={referrals.isLoading} rows={rows((referrals.data?.flow_breakdown ?? []).map((item) => [item.source_module, item.target_module, item.cnt]), 'referrals-flow')} />
              </div>
            </>
          )}
        </TabsContent>

        <TabsContent value="facilities" className="space-y-4">
          {facilities.isError && facilities.data === undefined ? (
            <QueryErrorState message="Failed to load facilities analytics." onRetry={() => void facilities.refetch()} pending={facilities.isFetching} />
          ) : (
            <>
              <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <span>{facilities.data?.total_batches.toLocaleString() ?? 'Loading'} batches started.</span>
                {facilities.data !== undefined && <Badge variant="secondary">Yield {facilities.data.yield_rate}%</Badge>}
                {facilities.data !== undefined && <span>{facilities.data.input_kg} kg input · {facilities.data.output_kg} kg output</span>}
              </div>
              <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                <TrendChart title="Batch-start trend" unit="batches" loading={facilities.isLoading} points={(facilities.data?.daily_trend ?? []).map((point) => ({ day: point.day, value: point.cnt }))} />
                <ReportDataTable title="Batch status" columns={['Status', 'Count']} loading={facilities.isLoading} rows={rows((facilities.data?.status_breakdown ?? []).map((item) => [item.status.replace(/_/g, ' '), item.cnt]), 'facilities-status')} />
                <ReportDataTable title="Waste categories" columns={['Category', 'Count']} loading={facilities.isLoading} rows={rows((facilities.data?.category_breakdown ?? []).map((item) => [item.category, item.cnt]), 'facilities-category')} />
              </div>
            </>
          )}
        </TabsContent>
      </Tabs>

      <SavedReportsSection start={start} end={end} canConfigure={canConfigure} canExport={canExport} />
    </main>
  );
}
