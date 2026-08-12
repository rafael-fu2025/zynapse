/**
 * ClinicAnalyticsView — poster-style clinic data visualization.
 *
 * Shared by the Reports "Clinic" tab and (for clinic-role users) the
 * dashboard, so clinic staff see the analytics immediately on login.
 *
 * Content mirrors the clinic-statistics poster: patient-care KPIs, a
 * monthly visits bar chart, three distribution pies (status, complaint
 * categories, patient type), the daily trend, the most-used medications
 * ranking and the monthly summary table. Pure presentational — the
 * caller owns the report query and passes data + loading/error state.
 */
import { format, parseISO } from 'date-fns';
import { useMemo } from 'react';
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { QueryErrorState } from '@/components/QueryErrorState';
import { ChartCard, chartColor } from '@/components/reports/ChartCard';
import { ReportDataTable, type ReportTableRow } from '@/components/reports/ReportDataTable';
import { TrendChart } from '@/components/reports/TrendChart';
import { Skeleton } from '@/components/ui/skeleton';
import { titleCase } from '@/lib/utils';
import type { ClinicReport } from '@/schemas/reports';

function rows(input: Array<Array<string | number>>, prefix: string): ReportTableRow[] {
  return input.map((cells) => ({ id: prefix + ':' + cells.join(':'), cells }));
}

/** `2026-08` -> `Aug 2026` (or the raw value when unparseable). */
function monthLabel(month: string): string {
  const parsed = parseISO(month + '-01');
  return Number.isNaN(parsed.getTime()) ? month : format(parsed, 'MMM yyyy');
}

/** Clinic KPI tile — poster-style summary number. */
function ClinicKpi({ label, value, detail }: { label: string; value: string; detail: string }) {
  return (
    <div className="rounded-xl border bg-card px-4 py-3">
      <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
      <dd className="mt-1 text-xl font-semibold tabular-nums text-foreground">{value}</dd>
      <p className="mt-0.5 text-xs text-muted-foreground">{detail}</p>
    </div>
  );
}

/** Ranked "most used medications" list with proportional bars. */
function MedicationRankList({
  items,
  loading,
}: {
  items: Array<{ generic_name: string; brand_name: string | null; unit: string; qty: number }>;
  loading: boolean;
}) {
  if (loading) {
    return (
      <div className="space-y-3 py-4" role="status" aria-label="Loading most used medications">
        <Skeleton className="h-3 w-2/3" />
        <Skeleton className="h-3 w-1/2" />
        <Skeleton className="h-3 w-3/4" />
        <span className="sr-only">Loading most used medications.</span>
      </div>
    );
  }
  if (items.length === 0) {
    return <p className="py-12 text-center text-sm text-muted-foreground">No medication dispensing in this range.</p>;
  }
  const max = Math.max(1, ...items.map((item) => item.qty));
  return (
    <ol className="space-y-2.5">
      {items.map((item, index) => (
        <li key={item.generic_name} className="flex items-center gap-3">
          <span className="w-5 shrink-0 text-right font-mono text-xs text-muted-foreground">{index + 1}</span>
          <div className="min-w-0 flex-1">
            <div className="flex items-baseline justify-between gap-2 text-xs">
              <span className="truncate font-medium text-foreground">
                {item.generic_name}{item.brand_name ? ` · ${item.brand_name}` : ''}
              </span>
              <span className="shrink-0 font-mono tabular-nums text-muted-foreground">{item.qty} {item.unit}</span>
            </div>
            <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
              <div className="h-full rounded-full bg-primary" style={{ width: `${Math.round((item.qty / max) * 100)}%` }} />
            </div>
          </div>
        </li>
      ))}
    </ol>
  );
}

export function ClinicAnalyticsView({
  report,
  isLoading,
  isError,
  isFetching,
  onRetry,
}: {
  report: ClinicReport | undefined;
  isLoading: boolean;
  isError: boolean;
  isFetching: boolean;
  onRetry: () => void;
}) {
  const monthlyVisits = useMemo(
    () => (report?.monthly_visits ?? []).map((item) => ({ ...item, label: monthLabel(item.month) })),
    [report],
  );
  const statusData = useMemo(
    () => (report?.status_breakdown ?? []).map((item) => ({ name: titleCase(item.status), value: item.cnt })),
    [report],
  );
  const complaintData = useMemo(
    () => (report?.complaint_categories ?? []).map((item) => ({ name: item.category, value: item.cnt })),
    [report],
  );
  const patientTypeData = useMemo(
    () => (report?.patient_type_breakdown ?? []).map((item) => ({ name: titleCase(item.kind), value: item.cnt })),
    [report],
  );

  if (isError && report === undefined) {
    return <QueryErrorState message="Failed to load clinic analytics." onRetry={onRetry} pending={isFetching} />;
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">{report?.total_encounters.toLocaleString() ?? 'Loading'} encounters in the selected range.</p>

      {/* Patient-care KPI strip. */}
      <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <ClinicKpi label="Total visits" value={(report?.total_encounters ?? 0).toLocaleString()} detail="Encounters in range" />
        <ClinicKpi label="Unique patients" value={(report?.unique_patients ?? 0).toLocaleString()} detail="Distinct patients seen" />
        <ClinicKpi label="Avg visits / patient" value={(report?.avg_visits_per_patient ?? 0).toFixed(1)} detail="Encounters per patient" />
        <ClinicKpi label="Avg visits / day" value={(report?.avg_per_day ?? 0).toFixed(1)} detail="Across the selected range" />
      </dl>

      {/* Charts: monthly bar (full width) + three pies. */}
      <div className="grid min-w-0 gap-4 md:grid-cols-3">
        <ChartCard title="Monthly visits" subtitle="Encounters by month (Asia/Manila)" className="md:col-span-3" loading={isLoading} height={260}>
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={monthlyVisits} margin={{ top: 4, right: 8, left: -16, bottom: 0 }}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
              <XAxis dataKey="label" tickLine={false} axisLine={false} tick={{ fontSize: 12 }} />
              <YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 12 }} />
              <Tooltip
                cursor={{ fill: 'var(--accent)', opacity: 0.35 }}
                contentStyle={{ backgroundColor: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }}
                labelStyle={{ fontWeight: 600 }}
                itemStyle={{ color: 'var(--muted-foreground)' }}
              />
              <Bar dataKey="cnt" name="Visits" fill="var(--color-primary)" radius={[4, 4, 0, 0]} maxBarSize={48} />
            </BarChart>
          </ResponsiveContainer>
        </ChartCard>

        <ChartCard title="Encounter status" subtitle="Share by status" loading={isLoading} height={250}>
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie data={statusData} dataKey="value" nameKey="name" innerRadius="52%" outerRadius="80%" paddingAngle={2} isAnimationActive={false}>
                {statusData.map((entry, index) => <Cell key={entry.name} fill={chartColor(index)} />)}
              </Pie>
              <Tooltip contentStyle={{ backgroundColor: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} itemStyle={{ color: 'var(--muted-foreground)' }} />
              <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
          </ResponsiveContainer>
        </ChartCard>

        <ChartCard title="Complaint categories" subtitle="Privacy-safe groups" loading={isLoading} height={250}>
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie data={complaintData} dataKey="value" nameKey="name" innerRadius="52%" outerRadius="80%" paddingAngle={2} isAnimationActive={false}>
                {complaintData.map((entry, index) => <Cell key={entry.name} fill={chartColor(index)} />)}
              </Pie>
              <Tooltip contentStyle={{ backgroundColor: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} itemStyle={{ color: 'var(--muted-foreground)' }} />
              <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
          </ResponsiveContainer>
        </ChartCard>

        <ChartCard title="Patient type" subtitle="Students / employees / guests" loading={isLoading} height={250}>
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie data={patientTypeData} dataKey="value" nameKey="name" innerRadius="52%" outerRadius="80%" paddingAngle={2} isAnimationActive={false}>
                {patientTypeData.map((entry, index) => <Cell key={entry.name} fill={chartColor(index)} />)}
              </Pie>
              <Tooltip contentStyle={{ backgroundColor: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8, fontSize: 12 }} itemStyle={{ color: 'var(--muted-foreground)' }} />
              <Legend iconType="circle" iconSize={8} wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
          </ResponsiveContainer>
        </ChartCard>
      </div>

      {/* Daily detail. */}
      <TrendChart title="Daily encounter trend" unit="encounters" loading={isLoading} points={(report?.daily_trend ?? []).map((point) => ({ day: point.day, value: point.cnt }))} />

      {/* Tables: most-used meds + monthly + existing breakdowns. */}
      <div className="grid min-w-0 gap-4 md:grid-cols-2">
        <section className="min-w-0 rounded-xl border bg-card p-4">
          <h3 className="text-sm font-semibold text-foreground">Most used medications</h3>
          <p className="mt-0.5 text-xs text-muted-foreground">Top 10 by units dispensed</p>
          <div className="mt-3">
            <MedicationRankList items={report?.most_common_medications ?? []} loading={isLoading} />
          </div>
        </section>

        <ReportDataTable title="Monthly summary" columns={['Month', 'Visits']} loading={isLoading} rows={rows((report?.monthly_visits ?? []).map((item) => [monthLabel(item.month), item.cnt]), 'clinic-monthly')} />

        <ReportDataTable title="Kiosk outcomes" columns={['Outcome', 'Count']} loading={isLoading} rows={rows((report?.checkin_outcomes ?? []).map((item) => [titleCase(item.outcome), item.cnt]), 'clinic-checkin')} />

        <ReportDataTable title="Referral flows" columns={['Source', 'Target', 'Status', 'Count']} loading={isLoading} rows={rows((report?.referral_flows ?? []).map((item) => [titleCase(item.source_module), titleCase(item.target_module), titleCase(item.status), item.cnt]), 'clinic-referral')} />
      </div>
    </div>
  );
}
