/**
 * AnalyticsCard — yield / mass-reduction / ETA analytics for the batch.
 *
 * Input and output are deliberately absent: the batch summary above
 * already states them. Yield and mass reduction are computed against
 * harvested output, so before the first harvest they are not "0% / poor"
 * and "100%" — they are simply not measurable yet, and the card says so.
 */
import { LineChart, Loader2 } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useBatchAnalytics } from '@/hooks/useFacilities';
import { Stat } from './Stat';

export function AnalyticsCard({ batchId }: { batchId: number }) {
  const analytics = useBatchAnalytics(batchId);
  const a = analytics.data;
  const harvested = a !== undefined && a.output_kg > 0;

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <LineChart className="size-4 text-primary" /> Analytics
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        {analytics.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
        {analytics.isError && (
          <p className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
            {analytics.error.httpStatus === 403
              ? "You don't have access to this batch's analytics."
              : 'Failed to load analytics.'}
          </p>
        )}

        {a !== undefined && (
          <>
            <dl className="grid grid-cols-2 gap-2">
              <Stat
                label="Yield"
                value={harvested ? `${a.yield_pct}%` : '—'}
                hint={harvested ? a.yield_class : undefined}
              />
              <Stat label="Mass reduction" value={harvested ? `${a.mass_reduction_pct}%` : '—'} />
              {a.expected_yield_pct !== null && (
                <Stat label="Expected yield" value={`${a.expected_yield_pct}%`} />
              )}
              {a.expected_days !== null && (
                <Stat label="Expected duration" value={`${a.expected_days} days`} hint="mix-weighted" />
              )}
              {a.reference_duration_days !== null && (
                <Stat label="Reference (manual)" value={`${a.reference_duration_days} days`} />
              )}
            </dl>
            {!harvested && (
              <p className="text-xs text-muted-foreground">
                Yield and mass reduction appear once compost is harvested.
              </p>
            )}

            {a.composition.length > 0 && (
              <section className="space-y-2">
                <h3 className="text-xs font-medium text-muted-foreground">
                  Waste composition (weight ratio → expected days)
                </h3>
                <ul className="divide-y rounded-md border text-sm">
                  {a.composition.map((c) => (
                    <li key={c.category_id} className="flex items-center justify-between gap-3 px-3 py-2">
                      <span className="min-w-0 truncate">{c.category_name}</span>
                      <span className="shrink-0 text-xs tabular-nums text-muted-foreground">
                        {c.weight_kg} kg{c.ratio_pct !== null ? ` · ${c.ratio_pct}%` : ''}
                        {c.expected_days !== null ? ` · ~${c.expected_days}d` : ''}
                        {c.sample_count > 0 ? ` (${c.sample_count} trials)` : ' (no history)'}
                      </span>
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}
