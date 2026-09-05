import {
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  LineChart,
  Loader2,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { QueryErrorRow } from '@/components/QueryErrorState';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useRecomputeAnalytics, useSchedulingAnalytics } from '@/hooks/useSchedule';
import { useUrlFilter } from '@/hooks/useUrlFilter';
import { hasPermission, useAuthStore } from '@/store/auth';
import { DAY_NAMES, type SlotAnalytics } from '@/schemas/schedule';

type SortKey = 'total_appointments' | 'total_no_shows' | 'no_show_rate' | 'recommended_overbooking';

export function AnalyticsTab() {
  const auth = useAuthStore();
  const canMutate = hasPermission(auth, 'counselling.schedule.manage') || hasPermission(auth, 'counselling.schedule.team_manage');
  const analytics = useSchedulingAnalytics();
  const recompute = useRecomputeAnalytics();

  const [sortKey, setSortKey] = useUrlFilter('sort_key', { default: 'no_show_rate' });
  const [sortDir, setSortDir] = useUrlFilter('sort_dir', { default: 'desc' });

  const validSortKey: SortKey = ['total_appointments', 'total_no_shows', 'no_show_rate', 'recommended_overbooking'].includes(sortKey)
    ? (sortKey as SortKey)
    : 'no_show_rate';
  const validSortDir: 'asc' | 'desc' = sortDir === 'asc' ? 'asc' : 'desc';

  function toggleSort(key: SortKey) {
    if (validSortKey === key) {
      setSortDir(validSortDir === 'asc' ? 'desc' : 'asc');
    } else {
      setSortKey(key);
      setSortDir('desc');
    }
  }

  const sorted = [...(analytics.data ?? [])].sort((a, b) => {
    const delta = a[validSortKey] - b[validSortKey];
    return validSortDir === 'asc' ? delta : -delta;
  });

  function SortHeader({ label, k }: { label: string; k: SortKey }) {
    const active = validSortKey === k;
    return (
      <TableHead
        className="px-3"
        aria-sort={active ? (validSortDir === 'asc' ? 'ascending' : 'descending') : 'none'}
      >
        <button
          type="button"
          onClick={() => toggleSort(k)}
          className="inline-flex items-center gap-1 rounded-sm hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
        >
          {label}
          {active ? (
            validSortDir === 'asc' ? (
              <ArrowUp className="size-3" aria-hidden />
            ) : (
              <ArrowDown className="size-3" aria-hidden />
            )
          ) : (
            <ArrowUpDown className="size-3 opacity-40" aria-hidden />
          )}
        </button>
      </TableHead>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-sm font-semibold text-foreground">Scheduling analytics</h2>
          <p className="text-xs text-muted-foreground">
            Deterministic no-show optimizer. Recomputes per-slot attendance rates and overbooking guidance.
          </p>
        </div>
        {canMutate && (
          <Button size="sm" onClick={() => recompute.mutate()} disabled={recompute.isPending}>
            {recompute.isPending ? <Loader2 className="mr-1 size-3.5 animate-spin" /> : <LineChart className="mr-1 size-3.5" />}
            Recompute
          </Button>
        )}
      </div>

      <section className="overflow-hidden rounded-xl border bg-card">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="px-3">Counsellor</TableHead>
              <TableHead className="px-3">Day</TableHead>
              <TableHead className="px-3">Slot</TableHead>
              <SortHeader label="Appts" k="total_appointments" />
              <SortHeader label="No-shows" k="total_no_shows" />
              <SortHeader label="No-show rate" k="no_show_rate" />
              <SortHeader label="Rec. overbooking" k="recommended_overbooking" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {analytics.isLoading && (
              <TableRow>
                <TableCell colSpan={7} className="px-3 py-8 text-center text-muted-foreground">
                  <Loader2 className="mx-auto size-5 animate-spin" />
                </TableCell>
              </TableRow>
            )}
            {!analytics.isLoading && (analytics.data?.length ?? 0) === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="px-3 py-12 text-center text-muted-foreground">
                  <p className="font-medium">No analytics computed yet.</p>
                  <p className="mt-1 text-xs">Recompute to calculate slot statistics and overbooking recommendations from appointment history.</p>
                  {canMutate && (
                    <Button className="mt-3" size="sm" variant="outline" onClick={() => recompute.mutate()} disabled={recompute.isPending}>
                      <LineChart className="size-3.5" /> Run initial computation
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            )}
            {analytics.isError && !analytics.isLoading && (
              <QueryErrorRow colSpan={7} message="Failed to load analytics." onRetry={() => void analytics.refetch()} pending={analytics.isFetching} />
            )}
            {sorted.map((s: SlotAnalytics) => (
              <TableRow key={s.id}>
                <TableCell className="px-3 font-mono text-xs">#{s.counsellor_user_id}</TableCell>
                <TableCell className="px-3 text-xs">{DAY_NAMES[s.day_of_week]}</TableCell>
                <TableCell className="px-3 font-mono text-xs">{s.time_slot.slice(0, 5)}</TableCell>
                <TableCell className="px-3 text-xs">{s.total_appointments}</TableCell>
                <TableCell className="px-3 text-xs">{s.total_no_shows}</TableCell>
                <TableCell className="px-3">
                  <Badge variant={s.no_show_rate >= 0.30 ? 'destructive' : s.no_show_rate >= 0.15 ? 'warning' : 'success'}>
                    {(s.no_show_rate * 100).toFixed(1)}%
                  </Badge>
                </TableCell>
                <TableCell className="px-3">
                  {s.recommended_overbooking > 0 ? (
                    <Badge variant="info">+{s.recommended_overbooking}</Badge>
                  ) : (
                    <span className="text-xs text-muted-foreground">—</span>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </section>
    </div>
  );
}
