import { useId } from 'react';
import { Skeleton } from '@/components/ui/skeleton';

interface TrendPoint {
  day: string;
  value: number;
}

export function TrendChart({ title, points, unit, loading = false }: { title: string; points: TrendPoint[]; unit: string; loading?: boolean }) {
  const titleId = useId();
  const descriptionId = useId();
  const width = 600;
  const height = 180;
  const insetX = 24;
  const insetY = 20;
  const max = Math.max(1, ...points.map((point) => point.value));
  const x = (index: number) => points.length <= 1
    ? width / 2
    : insetX + (index / (points.length - 1)) * (width - insetX * 2);
  const y = (value: number) => height - insetY - (value / max) * (height - insetY * 2);
  const path = points.map((point, index) => x(index) + ',' + y(point.value)).join(' ');

  return (
    <section className="min-w-0 rounded-xl border bg-card p-4" aria-labelledby={titleId}>
      <div className="mb-3 flex items-baseline justify-between gap-3">
        <h3 id={titleId} className="text-sm font-semibold text-foreground">{title}</h3>
        {!loading && points.length > 0 && <span className="text-xs tabular-nums text-muted-foreground">Peak {max} {unit}</span>}
      </div>
      {loading ? (
        <div className="space-y-3 py-4" role="status" aria-label={'Loading ' + title.toLowerCase()}>
          <Skeleton className="h-28 w-full" />
          <Skeleton className="h-3 w-2/3" />
          <span className="sr-only">Loading {title.toLowerCase()}.</span>
        </div>
      ) : points.length === 0 ? (
        <p className="py-14 text-center text-sm text-muted-foreground">No trend data in this range.</p>
      ) : (
        <>
          <svg
            viewBox={'0 0 ' + width + ' ' + height}
            className="h-44 w-full overflow-visible text-primary"
            role="img"
            aria-labelledby={titleId + ' ' + descriptionId}
          >
            <desc id={descriptionId}>{points.length} daily values. The highest value is {max} {unit}.</desc>
            <line x1={insetX} y1={height - insetY} x2={width - insetX} y2={height - insetY} className="stroke-border" />
            <line x1={insetX} y1={insetY} x2={width - insetX} y2={insetY} className="stroke-border" strokeDasharray="4 6" />
            <polyline points={path} fill="none" stroke="currentColor" strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" />
            {points.length <= 45 && points.map((point, index) => (
              <circle key={point.day} cx={x(index)} cy={y(point.value)} r="3.5" fill="currentColor">
                <title>{point.day}: {point.value} {unit}</title>
              </circle>
            ))}
            <text x={insetX} y={height - 3} className="fill-muted-foreground text-[11px]">{points[0]?.day}</text>
            <text x={width - insetX} y={height - 3} textAnchor="end" className="fill-muted-foreground text-[11px]">{points.at(-1)?.day}</text>
          </svg>
          <table className="sr-only">
            <caption>{title} data</caption>
            <thead><tr><th>Date</th><th>{unit}</th></tr></thead>
            <tbody>
              {points.map((point) => <tr key={point.day}><td>{point.day}</td><td>{point.value}</td></tr>)}
            </tbody>
          </table>
        </>
      )}
    </section>
  );
}
