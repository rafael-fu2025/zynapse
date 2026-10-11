/**
 * BatchSummary — the one place the drum page states what the batch is:
 * code, age, category, weights, start time and ETA progress. Everything
 * below it (analytics, ledgers) adds detail without repeating these.
 */
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { describeEta, formatDayCount, formatKg, formatProgress } from '@/lib/bmgFormat';
import { cn } from '@/lib/utils';
import type { ActiveBatch } from '@/schemas/facilities';
import { fmtShort, fmtUtcToApp } from '@/utils/date';
import { Stat } from './Stat';

export function BatchSummary({ batch }: { batch: ActiveBatch }) {
  // Wording comes from the shared formatters so this page and the drum
  // grid can never describe the same batch differently.
  const eta = describeEta(batch.days_until_expected);
  const progress = formatProgress(batch.progress_pct);

  return (
    <Card>
      <CardContent className="space-y-4 p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="min-w-0">
            <p className="text-xs text-muted-foreground">Batch</p>
            <p className="truncate font-mono text-sm font-semibold">{batch.batch_code}</p>
          </div>
          <Badge variant={batch.days_active > 30 ? 'warning' : 'info'}>
            {formatDayCount(batch.days_active)} active
          </Badge>
        </div>

        <dl className="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <Stat label="Waste category" value={batch.category_name ?? '—'} />
          <Stat label="Input" value={formatKg(batch.input_kg)} />
          <Stat label="Output" value={formatKg(batch.output_kg)} />
          <Stat
            label="Started"
            value={fmtUtcToApp(batch.started_at, 'MMM d, yyyy')}
            hint={fmtUtcToApp(batch.started_at, 'h:mm a')}
          />
        </dl>

        <div className="space-y-1.5">
          <div className="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
            <span className="text-muted-foreground">Expected completion</span>
            <span>
              <span className="font-semibold tabular-nums">
                {batch.expected_completion_date !== null ? fmtShort(batch.expected_completion_date) : '—'}
              </span>
              {batch.days_until_expected !== null && (
                <span
                  className={cn(
                    'ml-2 text-xs font-medium',
                    eta.tone === 'danger' ? 'text-destructive' : 'text-muted-foreground',
                  )}
                >
                  {eta.label}
                </span>
              )}
            </span>
          </div>
          <div className="flex items-center gap-3">
            <div
              className="h-2 flex-1 overflow-hidden rounded-full bg-muted"
              role="progressbar"
              aria-label={`Decomposition progress for ${batch.unit_code}`}
              aria-valuenow={progress.value}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuetext={`${progress.label} toward expected completion`}
            >
              <div
                className="h-full rounded-full bg-gradient-to-r from-primary/70 to-primary transition-[width] duration-500"
                style={{ width: `${progress.value}%` }}
              />
            </div>
            <span className="w-9 text-right text-xs tabular-nums text-muted-foreground">
              {progress.label}
            </span>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
