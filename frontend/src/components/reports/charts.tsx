/**
 * Report chart patterns ported from the NiceAdmin `charts-chartjs.html`
 * reference, rendered through Chart.js via `ChartCanvas` and wrapped in
 * the module's `ChartCard` chrome (title/subtitle/skeleton/fixed height).
 *
 * Three of the reference's patterns cover every report dataset:
 *  - `TrendLineChart`        — the basic/area line chart (tension 0.4,
 *                              index-mode tooltips from the interactive demo)
 *  - `BreakdownBarChart`     — vertical or horizontal (`indexAxis: 'y'`) bars
 *  - `StatusDoughnutChart`   — doughnut with a center value overlay
 *                              (the `doughnutCenterChart` pattern)
 *
 * Every chart keeps an sr-only data table so the analytics remain readable
 * without canvas — the accessibility contract the old hand-rolled SVG
 * TrendChart established.
 */
import { useMemo } from 'react';
import { ChartCanvas, cssVar, useThemeKey, withAlpha } from '@/components/ui/chart-canvas';
import { ChartCard } from '@/components/reports/ChartCard';
import type { ChartData, ChartOptions } from 'chart.js';

export interface TrendPoint {
  day: string;
  value: number;
}

export interface ChartItem {
  label: string;
  value: number;
}

/** Maroon-anchored categorical palette; index 0 resolves the live --chart-1
 *  (#800000, identical to light --primary; dark keeps the salmon #d47575 —
 *  dark --primary is white, which would render data as white bars). */
export function useReportPalette(): string[] {
  const themeKey = useThemeKey();
  // The joined string participates in the returned array's construction, so
  // the theme flip (themeKey change) produces a new array identity and any
  // memoized chart data using the palette rebuilds with re-resolved colors.
  return useMemo(() => {
    void themeKey;
    return [
      cssVar('--chart-1'),
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
  }, [themeKey]);
}

function SrOnlyTable({ caption, head, rows }: { caption: string; head: string[]; rows: string[][] }) {
  return (
    <table className="sr-only">
      <caption>{caption} data</caption>
      <thead><tr>{head.map((cell) => <th key={cell} scope="col">{cell}</th>)}</tr></thead>
      <tbody>
        {rows.map((row) => <tr key={row.join(':')}>{row.map((cell, index) => <td key={index}>{cell}</td>)}</tr>)}
      </tbody>
    </table>
  );
}

export function TrendLineChart({
  title,
  subtitle,
  unit,
  points,
  loading = false,
  area = false,
  height = 240,
}: {
  title: string;
  subtitle?: string;
  unit: string;
  points: TrendPoint[];
  loading?: boolean;
  /** Fill under the line (the reference's area chart). */
  area?: boolean;
  height?: number;
}) {
  const palette = useReportPalette();
  const data = useMemo<ChartData<'line'>>(() => ({
    labels: points.map((point) => point.day),
    datasets: [{
      label: unit,
      data: points.map((point) => point.value),
      borderColor: palette[0],
      backgroundColor: withAlpha(palette[0] ?? '', 0.12),
      fill: area,
      tension: 0.4,
      pointRadius: points.length <= 45 ? 3 : 0,
      pointHoverRadius: 5,
    }],
  }), [points, unit, area, palette]);

  const options = useMemo<ChartOptions<'line'>>(() => ({
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: (item) => `${item.parsed.y} ${unit}` } },
    },
    scales: {
      x: {
        border: { display: false },
        ticks: { maxTicksLimit: 7, maxRotation: 0, callback(value) { return String(this.getLabelForValue(Number(value))).slice(5); } },
      },
      y: { border: { display: false }, beginAtZero: true, ticks: { precision: 0 } },
    },
  }), [unit]);

  const peak = Math.max(0, ...points.map((point) => point.value));

  return (
    <ChartCard title={title} {...(subtitle !== undefined ? { subtitle } : {})} loading={loading} height={height}>
      {points.length === 0 ? (
        <p className="py-14 text-center text-sm text-muted-foreground">No trend data in this range.</p>
      ) : (
        <>
          <ChartCanvas type="line" data={data} options={options} ariaLabel={`${title}. ${points.length} daily values, peak ${peak} ${unit}.`} />
          <SrOnlyTable caption={title} head={['Date', unit]} rows={points.map((point) => [point.day, String(point.value)])} />
        </>
      )}
    </ChartCard>
  );
}

export function BreakdownBarChart({
  title,
  subtitle,
  items,
  unit = '',
  horizontal = false,
  loading = false,
  height = 240,
}: {
  title: string;
  subtitle?: string;
  items: ChartItem[];
  unit?: string;
  /** Horizontal bars (`indexAxis: 'y'`) — for ranked/label-heavy breakdowns. */
  horizontal?: boolean;
  loading?: boolean;
  height?: number;
}) {
  const palette = useReportPalette();
  const data = useMemo<ChartData<'bar'>>(() => ({
    labels: items.map((item) => item.label),
    datasets: [{
      label: unit !== '' ? unit : title,
      data: items.map((item) => item.value),
      backgroundColor: items.map((_, index) => palette[index % palette.length] ?? palette[0]),
      borderRadius: 4,
      maxBarThickness: 32,
    }],
  }), [items, unit, title, palette]);

  const options = useMemo<ChartOptions<'bar'>>(() => ({
    responsive: true,
    indexAxis: horizontal ? 'y' : 'x',
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: (item) => `${item.parsed[horizontal ? 'x' : 'y'] as number}${unit !== '' ? ' ' + unit : ''}` } },
    },
    scales: {
      x: { border: { display: false }, beginAtZero: true, ticks: { precision: 0 } },
      y: { border: { display: false }, beginAtZero: true, ticks: { precision: 0, autoSkip: false } },
    },
  }), [horizontal, unit]);

  return (
    <ChartCard title={title} {...(subtitle !== undefined ? { subtitle } : {})} loading={loading} height={height}>
      {items.length === 0 ? (
        <p className="py-14 text-center text-sm text-muted-foreground">No data to chart.</p>
      ) : (
        <>
          <ChartCanvas type="bar" data={data} options={options} ariaLabel={`${title}. ${items.map((item) => `${item.label}: ${item.value}`).join(', ')}.`} />
          <SrOnlyTable caption={title} head={[title, 'Count']} rows={items.map((item) => [item.label, String(item.value)])} />
        </>
      )}
    </ChartCard>
  );
}

export function StatusDoughnutChart({
  title,
  subtitle,
  items,
  centerValue,
  centerLabel,
  loading = false,
  height = 240,
}: {
  title: string;
  subtitle?: string;
  items: ChartItem[];
  /** Big value rendered in the doughnut hole (the reference's center-text overlay). */
  centerValue: string;
  centerLabel: string;
  loading?: boolean;
  height?: number;
}) {
  const palette = useReportPalette();
  const legendTotal = items.reduce((sum, item) => sum + item.value, 0);
  const data = useMemo<ChartData<'doughnut'>>(() => ({
    labels: items.map((item) => item.label),
    datasets: [{
      data: items.map((item) => item.value),
      backgroundColor: items.map((_, index) => palette[index % palette.length] ?? palette[0]),
      borderColor: cssVar('--background'),
      borderWidth: 2,
      hoverOffset: 4,
    }],
  }), [items, palette]);

  const options = useMemo<ChartOptions<'doughnut'>>(() => ({
    responsive: true,
    cutout: '70%',
    // Legend is rendered as HTML below the chart area (not as a Chart.js
    // canvas legend) so the ring owns the full box and the center overlay
    // can be centered with plain CSS — a canvas legend shrinks the chart
    // area by a wrapping-dependent amount and breaks exact centering.
    plugins: {
      legend: { display: false },
    },
  }), []);

  return (
    <ChartCard title={title} {...(subtitle !== undefined ? { subtitle } : {})} loading={loading} height={height}>
      {items.length === 0 ? (
        /* No slices in range: keep the ring's centre reading `0` with its
           label so the panel states the zero explicitly instead of going
           blank (and the label casing stays consistent with the live state). */
        <div className="flex h-full items-center justify-center">
          <div className="flex size-40 flex-col items-center justify-center rounded-full border-[10px] border-muted">
            <span className="text-2xl font-semibold tabular-nums text-foreground">{centerValue}</span>
            <span className="text-xs text-muted-foreground">{centerLabel}</span>
          </div>
        </div>
      ) : (
        <>
          <div className="flex h-full min-h-0 flex-col">
            <div className="relative min-h-0 flex-1">
              <ChartCanvas type="doughnut" data={data} options={options} ariaLabel={`${title}. ${items.map((item) => `${item.label}: ${item.value}`).join(', ')}.`} />
              {/* Center-text overlay (doughnutCenterChart pattern) — exact
                  geometric center of the ring's box. */}
              <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-2xl font-semibold tabular-nums text-foreground">{centerValue}</span>
                <span className="text-xs text-muted-foreground">{centerLabel}</span>
              </div>
            </div>
            <ul className="mt-2 flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
              {items.map((item, index) => (
                <li key={item.label} className="flex items-center gap-1.5 text-xs text-muted-foreground">
                  <span className="size-2 shrink-0 rounded-sm" style={{ backgroundColor: palette[index % palette.length] ?? palette[0] }} />
                  {item.label}
                  <span className="tabular-nums text-foreground">{item.value.toLocaleString()}</span>
                  <span>({legendTotal > 0 ? Math.round((item.value / legendTotal) * 100) : 0}%)</span>
                </li>
              ))}
            </ul>
          </div>
          <SrOnlyTable caption={title} head={['Category', 'Count']} rows={items.map((item) => [item.label, String(item.value)])} />
        </>
      )}
    </ChartCard>
  );
}
