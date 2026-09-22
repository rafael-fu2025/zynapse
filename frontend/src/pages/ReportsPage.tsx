import {
  Download,
  Factory,
  FileText,
  Loader2,
  MessagesSquare,
  Minus,
  Package,
  Share2,
  Sparkles,
  Stethoscope,
  TrendingDown,
  TrendingUp,
} from 'lucide-react';
import { formatInTimeZone } from 'date-fns-tz';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { QueryErrorState } from '@/components/QueryErrorState';
import { ClinicAnalyticsView } from '@/components/reports/ClinicAnalyticsView';
import { BreakdownBarChart, StatusDoughnutChart, TrendLineChart } from '@/components/reports/charts';
import ReportPdfView from '@/components/reports/ReportPdfView';
import { ReportDataTable, type ReportTableRow } from '@/components/reports/ReportDataTable';
import { SavedReportsSection } from '@/components/reports/SavedReportsSection';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/PageHeader';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { TabSections, type TabSection } from '@/components/TabSections';
import {
  useClinicReport,
  useCounsellingReport,
  useFacilitiesReport,
  useInventoryForecast,
  useInventoryPurchases,
  useInventoryReport,
  useReferralReport,
  useReportExport,
  useReportNarrative,
  useReportPdfExport,
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
import { titleCase } from '@/lib/utils';

const APP_TIMEZONE = 'Asia/Manila';

/**
 * Default window: the current academic year to date — Aug 1 through today
 * in Manila (Foundation University's AY opens in August). The report is
 * fully range-driven; this only seeds the first load and never constrains
 * what the picker can select.
 */
function defaultRange(): { start: string; end: string } {
  const now = new Date();
  const today = formatInTimeZone(now, APP_TIMEZONE, 'yyyy-MM-dd');
  const year = Number(today.slice(0, 4));
  const month = Number(today.slice(5, 7));
  const startYear = month >= 8 ? year : year - 1;
  return { start: `${startYear}-08-01`, end: today };
}

function isValidRange(start: string, end: string): boolean {
  return reportRangeSchema.safeParse({ start, end }).success;
}

export function moduleLabel(module: ReportModule): string {
  return module.charAt(0).toUpperCase() + module.slice(1);
}

/** Section nav for the analytics modules (sidebar on wide screens). */
const MODULE_ICONS: Record<ReportModule, TabSection['icon']> = {
  clinic: Stethoscope,
  counselling: MessagesSquare,
  inventory: Package,
  referrals: Share2,
  facilities: Factory,
};
const REPORT_TABS: readonly TabSection[] = REPORT_MODULES.map((module) => ({
  value: module,
  label: moduleLabel(module),
  icon: MODULE_ICONS[module],
}));

function rows(input: Array<Array<string | number>>, prefix: string): ReportTableRow[] {
  return input.map((cells) => ({ id: prefix + ':' + cells.join(':'), cells }));
}

/** Compact period-over-period delta; the prior absolute sits in a tooltip. */
function Delta({ value, prior }: { value: number | null; prior?: number }) {
  if (value === null) return <span className="text-xs text-muted-foreground">No prior baseline</span>;
  if (value === 0) {
    return (
      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground" title={prior !== undefined ? `Prior period: ${prior.toLocaleString()}` : undefined}>
        <Minus className="size-3" /> 0%
      </span>
    );
  }
  const Icon = value > 0 ? TrendingUp : TrendingDown;
  return (
    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground" title={prior !== undefined ? `Prior period: ${prior.toLocaleString()}` : undefined}>
      <Icon className="size-3" /> {value > 0 ? '+' : ''}{value}%
    </span>
  );
}

function Metric({
  label,
  value,
  detail,
  delta,
  prior,
}: {
  label: string;
  value: number;
  detail: string;
  delta: number | null;
  prior?: number;
}) {
  return (
    <div className="min-w-0 px-4 py-3">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-xl font-semibold tabular-nums text-foreground">{value.toLocaleString()}</dd>
      <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
      <Delta value={delta} {...(prior !== undefined ? { prior } : {})} />
    </div>
  );
}

/** Per-tab KPI tile for metrics that are NOT already in the overview strip. */
function KpiCard({ label, value, detail }: { label: string; value: string; detail?: string }) {
  return (
    <div className="min-w-0 rounded-xl border bg-card px-4 py-3">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-xl font-semibold tabular-nums text-foreground">{value}</dd>
      {detail !== undefined && <p className="mt-0.5 text-xs text-muted-foreground">{detail}</p>}
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
  const forecast = useInventoryForecast(tab === 'inventory');
  const purchases = useInventoryPurchases(start, end, tab === 'inventory');
  const referrals = useReferralReport(start, end, tab === 'referrals');
  const facilities = useFacilitiesReport(start, end, tab === 'facilities');
  const exporter = useReportExport();
  const pdfExporter = useReportPdfExport();
  const pdfNodeRef = useRef<HTMLDivElement>(null);
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

  const equipmentByCategory = useMemo(() => {
    const totals = new Map<string, number>();
    for (const item of inventory.data?.equipment.items ?? []) {
      const key = item.category ?? 'Uncategorized';
      totals.set(key, (totals.get(key) ?? 0) + item.working + item.for_repair + item.for_replacement);
    }
    return [...totals.entries()]
      .map(([label, value]) => ({ label, value }))
      .sort((a, b) => b.value - a.value);
  }, [inventory.data]);

  function setTab(next: string): void {
    const parsed = reportModuleSchema.safeParse(next);
    if (!parsed.success) return;
    const nextParams = new URLSearchParams(params);
    if (parsed.data === 'clinic') nextParams.delete('tab');
    else nextParams.set('tab', parsed.data);
    setParams(nextParams, { replace: true });
  }

  function commitRange(nextStart: string, nextEnd: string): void {
    const parsed = reportRangeSchema.safeParse({ start: nextStart, end: nextEnd });
    if (!parsed.success) {
      // Surface the cap instead of silently snapping back to the previous
      // range — the schema owns the message (e.g. the 366-day limit).
      toast.error(parsed.error.issues[0]?.message ?? 'Invalid date range.');
      return;
    }
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

  function exportPdf(): void {
    // Capture the ReportPdfView root (first child of the fixed wrapper), NOT
    // the wrapper itself — the wrapper carries `left: -20000px` and
    // html-to-image clones at that offset, producing a blank PDF. The inner
    // node has its own width (794px) and captures correctly with the clone
    // style override in useReportPdfExport.
    const inner = pdfNodeRef.current?.firstElementChild as HTMLElement | null;
    if (inner === null) {
      toast.error('Report is not ready to export yet.');
      return;
    }
    pdfExporter.mutate({ module: tab, start, end, node: inner });
  }

  const pdfData = activeQuery.data;

  const currentNarrative = narratives[narrativeKey];

  return (
    <main className="space-y-4 p-6">
      <Tabs value={tab} onValueChange={setTab} className="space-y-4">
      <PageHeader
        title="Reports and Analytics"
        description="Aggregated, privacy-reviewed metrics on Asia/Manila days. Every download is audited."
        actions={
          <>
            {activeQuery.isFetching && !activeQuery.isLoading && (
              <span role="status" className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" /> Refreshing
              </span>
            )}
            {canConfigure && (
              <Button size="sm" variant="outline" onClick={generateSummary} disabled={narrative.isPending}>
                {narrative.isPending ? <Loader2 className="animate-spin" /> : <Sparkles />} Generate Narrative
              </Button>
            )}
            {canExport && (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button size="sm" variant="outline" disabled={exporter.isPending || pdfExporter.isPending}>
                    {exporter.isPending || pdfExporter.isPending ? <Loader2 className="animate-spin" /> : <Download />} Export {moduleLabel(tab)}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                  <DropdownMenuItem onClick={exportCurrent} disabled={exporter.isPending || activeQuery.data === undefined}>
                    <Download className="size-4" />
                    <span className="flex-1">Export CSV</span>
                    {exporter.isPending && <Loader2 className="size-4 animate-spin" />}
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={exportPdf} disabled={pdfExporter.isPending || activeQuery.data === undefined}>
                    <FileText className="size-4" />
                    <span className="flex-1">Export PDF</span>
                    {pdfExporter.isPending && <Loader2 className="size-4 animate-spin" />}
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            )}
          </>
        }
      />

      <section aria-labelledby="overview-heading" className="overflow-hidden rounded-xl border bg-card">
        <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-b px-4 py-3">
          <div className="min-w-0">
            <h2 id="overview-heading" className="text-sm font-semibold">Institution Overview</h2>
            <p className="mt-0.5 text-xs text-muted-foreground">Compared with the preceding period of equal length.</p>
          </div>
          <div className="w-full space-y-1.5 sm:w-auto sm:shrink-0">
            {/* block is required: Label is inline, and the picker's
                lg:w-[310px] trigger would otherwise sit BESIDE the label
                instead of below it (same lesson as AuditPage's FilterField). */}
            <Label htmlFor="report-range" className="block">Date Range</Label>
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
            {activeQuery.dataUpdatedAt > 0 && (
              <p className="text-xs text-muted-foreground">
                Report updated {fmtUtcToApp(new Date(activeQuery.dataUpdatedAt).toISOString())}.
              </p>
            )}
          </div>
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
              <Metric label="Clinic Encounters" value={summary.data.clinic.encounters} detail={summary.data.clinic.checkins + ' kiosk check-ins'} delta={summary.data.clinic.encounters_delta_pct} prior={summary.data.clinic.previous_encounters} />
              <Metric label="Counselling Appointments" value={summary.data.counselling.appointments} detail={summary.data.counselling.sessions + ' sessions opened'} delta={summary.data.counselling.appointments_delta_pct} prior={summary.data.counselling.previous_appointments} />
              <Metric label="Units Dispensed" value={summary.data.inventory.dispensed_qty} detail={summary.data.inventory.active_batches + ' active batches'} delta={summary.data.inventory.dispensed_delta_pct} prior={summary.data.inventory.previous_dispensed_qty} />
              <Metric label="Referrals Created" value={summary.data.referrals.created} detail="New referral activity" delta={summary.data.referrals.created_delta_pct} prior={summary.data.referrals.previous_created} />
              <Metric label="Facilities Batches Completed" value={summary.data.facilities.completed_batches} detail="Completion activity" delta={summary.data.facilities.completed_delta_pct} prior={summary.data.facilities.previous_completed_batches} />
            </dl>
            <p className="border-t px-4 py-2 text-xs text-muted-foreground">
              Inventory snapshot retrieved {fmtUtcToApp(summary.data.snapshot_at)}.
            </p>
          </>
        ) : null}
      </section>

        {currentNarrative !== undefined && (
          <section className="mt-4 rounded-xl border bg-muted/30 p-4" aria-labelledby="narrative-heading" aria-live="polite">
            <div className="flex flex-wrap items-start justify-between gap-2">
              <h2 id="narrative-heading" className="text-sm font-semibold">{moduleLabel(tab)} Narrative</h2>
              <span className="text-xs text-muted-foreground">{currentNarrative.range.start} to {currentNarrative.range.end} · generated {fmtUtcToApp(currentNarrative.generated_at)}</span>
            </div>
            <p className="mt-2 max-w-4xl text-sm leading-relaxed text-foreground">{currentNarrative.narrative}</p>
            <p className="mt-2 text-xs text-muted-foreground">Deterministic template summary. No external AI model is used.</p>
          </section>
        )}

        <TabSections tabs={REPORT_TABS} ariaLabel="Analytics module">
          <TabsContent value="clinic" className="space-y-4">
            <ClinicAnalyticsView
              report={clinic.data}
              isLoading={clinic.isLoading}
              isError={clinic.isError}
              isFetching={clinic.isFetching}
              onRetry={() => void clinic.refetch()}
            />
          </TabsContent>

        <TabsContent value="counselling" className="space-y-4">
          {counselling.isError && counselling.data === undefined ? (
            <QueryErrorState message="Failed to load counselling analytics." onRetry={() => void counselling.refetch()} pending={counselling.isFetching} />
          ) : (
            <>
              <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiCard label="Sessions Opened" value={(counselling.data?.sessions_opened ?? 0).toLocaleString()} detail="Counselling sessions in range" />
                <KpiCard label="No-Shows" value={(counselling.data?.no_show_count ?? 0).toLocaleString()} detail="Missed appointments" />
                <KpiCard label="No-Show Rate" value={(counselling.data?.no_show_rate ?? 0) + '%'} detail="Of all appointments" />
              </dl>
              <TrendLineChart title="Appointment Trend" subtitle="Booked appointments per day" unit="appointments" area loading={counselling.isLoading} points={(counselling.data?.daily_trend ?? []).map((point) => ({ day: point.day, value: point.cnt }))} />
              <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                <StatusDoughnutChart title="Appointment Status" items={(counselling.data?.status_breakdown ?? []).map((item) => ({ label: titleCase(item.status), value: item.cnt }))} centerValue={(counselling.data?.total_appointments ?? 0).toLocaleString()} centerLabel="appointments" loading={counselling.isLoading} />
                <BreakdownBarChart title="Appointment Type" unit="appointments" horizontal items={(counselling.data?.type_breakdown ?? []).map((item) => ({ label: titleCase(item.type), value: item.cnt }))} loading={counselling.isLoading} />
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
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <h2 id="inventory-current-heading" className="text-base font-semibold">Current Inventory Health</h2>
                  {inventory.data !== undefined && <span className="text-xs text-muted-foreground">Live snapshot · {fmtUtcToApp(inventory.data.snapshot_at)}</span>}
                </div>
                <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                  <KpiCard label="Medicines Tracked" value={(inventory.data?.total_medicines ?? 0).toLocaleString()} />
                  <KpiCard label="Low Stock" value={(inventory.data?.low_stock.length ?? 0).toLocaleString()} detail="At or below reorder threshold" />
                  <KpiCard label="Expired" value={(inventory.data?.expired.length ?? 0).toLocaleString()} detail="Active batches past expiry" />
                  <KpiCard label="Expiring in 90 Days" value={(inventory.data?.expiring.length ?? 0).toLocaleString()} detail="Active batches" />
                </dl>
                <div className="grid min-w-0 gap-4 xl:grid-cols-3">
                  <ReportDataTable title="Low Stock Now" columns={['Medicine', 'On Hand', 'Threshold']} loading={inventory.isLoading} rows={rows((inventory.data?.low_stock ?? []).map((item) => [item.generic_name + (item.brand_name !== null ? ' (' + item.brand_name + ')' : ''), item.total_stock + ' ' + item.unit, item.reorder_threshold]), 'inventory-low')} />
                  <ReportDataTable title="Expired Stock Now" columns={['Medicine', 'Batch', 'Remaining', 'Expired']} loading={inventory.isLoading} rows={rows((inventory.data?.expired ?? []).map((item) => [item.generic_name, item.batch_number, item.quantity_remaining + ' ' + item.unit, item.expiration_date]), 'inventory-expired')} emptyMessage="No expired active stock." />
                  <ReportDataTable title="Expiring in the Next 90 Days" columns={['Medicine', 'Batch', 'Remaining', 'Expires']} loading={inventory.isLoading} rows={rows((inventory.data?.expiring ?? []).map((item) => [item.generic_name, item.batch_number, item.quantity_remaining + ' ' + item.unit, item.expiration_date]), 'inventory-expiring')} emptyMessage="No active batches expire in the next 90 days." />
                </div>
              </section>
              <section className="space-y-3" aria-labelledby="inventory-activity-heading">
                <h2 id="inventory-activity-heading" className="text-base font-semibold">Dispensing Activity</h2>
                <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                  <TrendLineChart title="Dispensing Trend" subtitle={(inventory.data?.total_dispensed ?? 0).toLocaleString() + ' units dispensed in range'} unit="units" loading={inventory.isLoading} points={(inventory.data?.dispensing_trend ?? []).map((point) => ({ day: point.day, value: point.qty }))} />
                  <BreakdownBarChart title="Top Dispensed Medicines" unit="units" horizontal items={(inventory.data?.top_dispensed ?? []).map((item) => ({ label: item.generic_name + (item.brand_name !== null ? ' (' + item.brand_name + ')' : ''), value: item.qty }))} loading={inventory.isLoading} height={280} />
                </div>
              </section>
              <section className="space-y-3" aria-labelledby="inventory-equipment-heading">
                <h2 id="inventory-equipment-heading" className="text-base font-semibold">Equipment Status</h2>
                <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                  <StatusDoughnutChart title="Equipment by Status" subtitle="Current state — not range-bound" items={[
                    { label: 'Working', value: inventory.data?.equipment.status_summary.working ?? 0 },
                    { label: 'For Repair', value: inventory.data?.equipment.status_summary.for_repair ?? 0 },
                    { label: 'For Replacement', value: inventory.data?.equipment.status_summary.for_replacement ?? 0 },
                    { label: 'Retired', value: inventory.data?.equipment.status_summary.retired ?? 0 },
                  ]} centerValue={(inventory.data?.equipment.total_items ?? 0).toLocaleString()} centerLabel="equipment items" loading={inventory.isLoading} />
                  <BreakdownBarChart title="Active Units by Category" subtitle="Working, for repair, and for replacement — excludes retired" unit="units" horizontal items={equipmentByCategory} loading={inventory.isLoading} />
                </div>
                <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                  <ReportDataTable title="For Replacement" columns={['Equipment', 'Location', 'Units', 'Flagged Since']} loading={inventory.isLoading} rows={rows((inventory.data?.equipment.needs_replacement ?? []).map((item) => [item.name, item.location ?? '—', String(item.units), item.oldest_flagged !== null ? fmtUtcToApp(item.oldest_flagged) : '—']), 'inventory-equipment-replace')} emptyMessage="No equipment is flagged for replacement." />
                  <ReportDataTable title="For Repair" columns={['Equipment', 'Location', 'Units']} loading={inventory.isLoading} rows={rows((inventory.data?.equipment.items ?? []).filter((item) => item.for_repair > 0).map((item) => [item.name, item.location ?? '—', String(item.for_repair)]), 'inventory-equipment-repair')} emptyMessage="No equipment is waiting for repair." />
                </div>
              </section>
              <section className="space-y-3" aria-labelledby="inventory-forecast-heading">
                <h2 id="inventory-forecast-heading" className="text-base font-semibold">Stockout Forecast</h2>
                {forecast.isError ? (
                  <QueryErrorState message="Failed to load the stockout forecast." onRetry={() => void forecast.refetch()} pending={forecast.isFetching} />
                ) : forecast.isLoading ? (
                  <Skeleton className="h-48 w-full" />
                ) : forecast.data !== undefined && forecast.data.items.length > 0 ? (
                  <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                    <BreakdownBarChart title="Days Until Stockout" subtitle={'Within ' + forecast.data.within_days + ' days · trailing 30-day usage model'} unit="days" horizontal items={forecast.data.items.slice(0, 8).map((item) => ({ label: item.generic_name + (item.brand_name !== null ? ' (' + item.brand_name + ')' : ''), value: Math.max(0, Math.round((new Date(item.stockout_date).getTime() - new Date(forecast.data.generated_at).getTime()) / 86_400_000)) }))} height={280} />
                    <ReportDataTable title="At-Risk Medicines" columns={['Medicine', 'On Hand', 'Daily Use', 'Stockout', 'Reorder By']} loading={false} rows={rows(forecast.data.items.map((item) => [item.generic_name + (item.brand_name !== null ? ' (' + item.brand_name + ')' : ''), item.total_stock + ' ' + item.unit, String(item.predicted_daily_usage), item.stockout_date, item.reorder_date]), 'inventory-forecast')} />
                  </div>
                ) : (
                  <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">No medicine is projected to run out within {forecast.data?.within_days ?? 90} days.</p>
                )}
              </section>
              <section className="space-y-3" aria-labelledby="inventory-purchases-heading">
                <h2 id="inventory-purchases-heading" className="text-base font-semibold">Purchases</h2>
                {purchases.isError ? (
                  <QueryErrorState message="Failed to load purchase analytics." onRetry={() => void purchases.refetch()} pending={purchases.isFetching} />
                ) : (
                  <>
                    <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                      <KpiCard label="Purchase Requests" value={(purchases.data?.total_purchases ?? 0).toLocaleString()} detail="All statuses in range" />
                      <KpiCard label="Units Requested" value={(purchases.data?.total_units ?? 0).toLocaleString()} />
                    </dl>
                    <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                      <TrendLineChart title="Purchase Trend" subtitle="Requests created per day" unit="purchases" area loading={purchases.isLoading} points={(purchases.data?.daily_trend ?? []).map((point) => ({ day: point.day, value: point.cnt }))} />
                      <StatusDoughnutChart title="Purchase Pipeline" subtitle="Requests by current status" items={(purchases.data?.by_status ?? []).map((item) => ({ label: titleCase(item.status), value: item.cnt }))} centerValue={(purchases.data?.total_purchases ?? 0).toLocaleString()} centerLabel="requests" loading={purchases.isLoading} />
                    </div>
                  </>
                )}
              </section>
            </>
          )}
        </TabsContent>

        <TabsContent value="referrals" className="space-y-4">
          {referrals.isError && referrals.data === undefined ? (
            <QueryErrorState message="Failed to load referral analytics." onRetry={() => void referrals.refetch()} pending={referrals.isFetching} />
          ) : (
            <>
              <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiCard label="Closed" value={(referrals.data?.closed_count ?? 0).toLocaleString()} detail="Resolved referrals" />
                <KpiCard label="Closure Rate" value={(referrals.data?.closed_rate ?? 0) + '%'} detail="Of all referrals in range" />
              </dl>
              <TrendLineChart title="Referral Trend" subtitle="Referrals created per day" unit="referrals" area loading={referrals.isLoading} points={(referrals.data?.daily_trend ?? []).map((point) => ({ day: point.day, value: point.cnt }))} />
              <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                <StatusDoughnutChart title="Referral Status" items={(referrals.data?.status_breakdown ?? []).map((item) => ({ label: titleCase(item.status), value: item.cnt }))} centerValue={(referrals.data?.total_referrals ?? 0).toLocaleString()} centerLabel="referrals" loading={referrals.isLoading} />
                <BreakdownBarChart title="Referral Flows" subtitle="Source → target" unit="referrals" horizontal items={(referrals.data?.flow_breakdown ?? []).map((item) => ({ label: titleCase(item.source_module) + ' → ' + titleCase(item.target_module), value: item.cnt }))} loading={referrals.isLoading} />
              </div>
            </>
          )}
        </TabsContent>

        <TabsContent value="facilities" className="space-y-4">
          {facilities.isError && facilities.data === undefined ? (
            <QueryErrorState message="Failed to load facilities analytics." onRetry={() => void facilities.refetch()} pending={facilities.isFetching} />
          ) : (
            <>
              <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <KpiCard label="Batches Started" value={(facilities.data?.total_batches ?? 0).toLocaleString()} />
                <KpiCard label="Batches Completed" value={(facilities.data?.completed_batches ?? 0).toLocaleString()} />
                <KpiCard label="Yield Rate" value={(facilities.data?.yield_rate ?? 0) + '%'} detail={(facilities.data?.input_kg ?? 0) + ' kg in · ' + (facilities.data?.output_kg ?? 0) + ' kg out'} />
              </dl>
              <div className="grid min-w-0 gap-4 xl:grid-cols-2">
                <TrendLineChart title="Batch-Start Trend" unit="batches" area loading={facilities.isLoading} points={(facilities.data?.daily_trend ?? []).map((point) => ({ day: point.day, value: point.cnt }))} />
                <StatusDoughnutChart title="Batch Status" items={(facilities.data?.status_breakdown ?? []).map((item) => ({ label: titleCase(item.status), value: item.cnt }))} centerValue={(facilities.data?.completed_batches ?? 0).toLocaleString()} centerLabel="completed" loading={facilities.isLoading} />
              </div>
              <BreakdownBarChart title="Waste Categories" unit="batches" horizontal items={(facilities.data?.category_breakdown ?? []).map((item) => ({ label: item.category, value: item.cnt }))} loading={facilities.isLoading} />
            </>
          )}
        </TabsContent>
        </TabSections>
      </Tabs>

      <SavedReportsSection start={start} end={end} canConfigure={canConfigure} canExport={canExport} />

      {/* Off-screen printable node for the PDF export. Kept mounted (not
          display:none) so html-to-image can rasterize it on demand. */}
      <div
        ref={pdfNodeRef}
        aria-hidden
        style={{ position: 'fixed', left: -20000, top: 0, zIndex: -1, pointerEvents: 'none' }}
      >
        <ReportPdfView module={tab} start={start} end={end} data={pdfData} />
      </div>
    </main>
  );
}
