/**
 * ChartCard — shared panel chrome for the Recharts visualizations in
 * the reports module.
 *
 * Provides the bordered-card look used across the report (matching
 * `TrendChart` / `ReportDataTable`), a maroon-anchored categorical
 * palette, a loading skeleton, and a styled tooltip body so every
 * chart stays visually consistent and accessible.
 */
import type { ReactNode } from 'react';
import { Skeleton } from '@/components/ui/skeleton';

/** Maroon-anchored categorical palette (matches the app theme). */
export const CHART_COLORS = [
  'var(--color-primary)',
  '#b04545',
  '#d98a8a',
  '#4a8fc1',
  '#7aa85f',
  '#d2a13b',
  '#8f6fb5',
  '#59a18a',
  '#c76a93',
  '#6f7fae',
];

/** Cycle-safe palette lookup (array index typing returns `string | undefined`). */
export function chartColor(index: number): string {
  return CHART_COLORS[index % CHART_COLORS.length] ?? 'var(--color-primary)';
}

export function ChartCard({
  title,
  subtitle,
  children,
  loading = false,
  height = 240,
  className,
}: {
  title: string;
  subtitle?: string;
  children: ReactNode;
  loading?: boolean;
  height?: number;
  className?: string;
}) {
  return (
    <section className={'min-w-0 rounded-xl border bg-card p-4 ' + (className ?? '')}>
      <div className="mb-3">
        <h3 className="text-sm font-semibold text-foreground">{title}</h3>
        {subtitle !== undefined && <p className="mt-0.5 text-xs text-muted-foreground">{subtitle}</p>}
      </div>
      {loading ? (
        <div className="space-y-3 py-4" role="status" aria-label={'Loading ' + title.toLowerCase()}>
          <Skeleton className="h-32 w-full" />
          <Skeleton className="h-3 w-2/3" />
          <span className="sr-only">Loading {title.toLowerCase()}.</span>
        </div>
      ) : (
        <div style={{ height }}>{children}</div>
      )}
    </section>
  );
}

/** Shared tooltip chrome for Recharts `Tooltip content`. */
export function ChartTooltipBody({
  title,
  rows,
}: {
  title?: string;
  rows: Array<{ name: string; value: string; color: string }>;
}) {
  return (
    <div className="rounded-md border bg-popover px-3 py-2 text-xs shadow-md">
      {title !== undefined && <p className="mb-1 font-medium text-popover-foreground">{title}</p>}
      <ul className="space-y-0.5">
        {rows.map((row) => (
          <li key={row.name} className="flex items-center gap-2 text-muted-foreground">
            <span className="size-2 shrink-0 rounded-sm" style={{ backgroundColor: row.color }} />
            <span className="capitalize">{row.name}</span>
            <span className="ml-auto pl-3 font-medium tabular-nums text-popover-foreground">{row.value}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
