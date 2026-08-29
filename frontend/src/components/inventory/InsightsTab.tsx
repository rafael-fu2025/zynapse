import {
  CalendarClock,
  CalendarX2,
  Loader2,
  Pill,
  TrendingUp,
  Truck,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { cn, titleCase } from '@/lib/utils';
import {
  useExpiringMedicines,
  useLowStockMedicines,
  useMedicineUsageSummary,
  useWrittenOffMedicines,
} from '@/hooks/useMedicines';
import { useReorders } from '@/hooks/useReorders';
import { fmtUtcToApp } from '@/utils/date';
import { ExpiryChip, StockBadge } from './badges';
import { BATCH_STATUS_VARIANT } from './constants';
import { daysUntil } from './format';

/**
 * InsightsTab — top-down view of the catalogue. Built from existing
 * analytics endpoints (`/medicines/low-stock`, `/medicines/expiring`,
 * `/reorders`) — no new backend surface. The "click a tile to dive
 * into the underlying tab" interaction uses the page-level tab state
 * so the four tabs still share the URL param.
 */
export function InsightsTab({ onJumpToTab }: { onJumpToTab: (tab: 'medicines' | 'supplies' | 'reorders') => void }) {
  const lowStock = useLowStockMedicines();
  const expiring = useExpiringMedicines(30);
  const writtenOff = useWrittenOffMedicines(90);
  const usage = useMedicineUsageSummary(30);
  // Fetch a small page of reorders just for the "in flight" count — we
  // only need the number, not the rows (the Reorders tab has the full
  // list with ETAs + actions from Gap 9).
  const reorders = useReorders(null, null, 50);

  const lowStockCount  = lowStock.data?.length ?? 0;
  const expiringCount  = expiring.data?.length ?? 0;
  const writtenOffCount = writtenOff.data?.length ?? 0;
  const usageAvg = usage.data?.avg_daily_units;
  const pendingReorderCount = (reorders.data?.data ?? []).filter((r) =>
    r.status === 'pending' || r.status === 'approved' || r.status === 'ordered',
  ).length;

  // Top-of-list slices — full data lives on the source tab.
  const lowStockTop = (lowStock.data ?? []).slice(0, 5);
  const expiringTop = (expiring.data ?? []).slice(0, 5);
  const writtenOffTop = (writtenOff.data ?? []).slice(0, 5);

  return (
    <div className="space-y-4">
      {/* Stat tiles — the morning stock-check dashboard. Color shifts
          from neutral → warning → destructive as the count grows. */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
        <StatTile
          icon={<Pill className="size-4" />}
          label="Needs to reorder"
          value={lowStockCount}
          tone={lowStockCount === 0 ? 'success' : lowStockCount <= 3 ? 'warning' : 'destructive'}
          loading={lowStock.isLoading}
          onClick={() => onJumpToTab('medicines')}
        />
        <StatTile
          icon={<CalendarClock className="size-4" />}
          label="Expiring in 30d"
          value={expiringCount}
          tone={expiringCount === 0 ? 'success' : expiringCount <= 3 ? 'warning' : 'destructive'}
          loading={expiring.isLoading}
          onClick={() => onJumpToTab('medicines')}
        />
        <StatTile
          icon={<CalendarX2 className="size-4" />}
          label="Expired (90d)"
          value={writtenOffCount}
          tone={writtenOffCount === 0 ? 'success' : 'destructive'}
          loading={writtenOff.isLoading}
          onClick={() => onJumpToTab('medicines')}
        />
        <StatTile
          icon={<Truck className="size-4" />}
          label="Reorders in flight"
          value={pendingReorderCount}
          tone={pendingReorderCount === 0 ? 'success' : 'info'}
          loading={reorders.isLoading}
          onClick={() => onJumpToTab('reorders')}
        />
        <StatTile
          icon={<TrendingUp className="size-4" />}
          label="Avg daily use (30d)"
          value={usageAvg !== undefined ? `${usageAvg}/day` : '—'}
          tone={usageAvg !== undefined && usageAvg > 0 ? 'info' : 'muted'}
          loading={usage.isLoading}
        />
      </div>

      {/* Two side-by-side detail panels: low-stock + expiring. Each
          shows up to 5 rows; "View all" jumps to the full Medicines tab
          where the rest of the work happens. */}
      <div className="grid gap-3 md:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-3">
            <div>
              <CardTitle className="text-base">Medicines that need reordering</CardTitle>
              <CardDescription>Below the reorder threshold</CardDescription>
            </div>
            <Button variant="ghost" size="sm" onClick={() => onJumpToTab('medicines')}>
              View all →
            </Button>
          </CardHeader>
          <CardContent>
            {lowStock.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
            {!lowStock.isLoading && lowStockCount === 0 && (
              <p className="text-sm text-muted-foreground">All medicines are above their reorder line.</p>
            )}
            {!lowStock.isLoading && lowStockCount > 0 && (
              <ul className="divide-y">
                {lowStockTop.map((m) => (
                  <li key={m.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                    <div className="min-w-0 flex-1">
                      <div className="truncate font-medium">{m.generic_name}</div>
                      <div className="truncate text-xs text-muted-foreground">
                        {[m.brand_name, m.dosage_strength].filter(Boolean).join(' · ')}
                      </div>
                    </div>
                    <StockBadge
                      onHand={m.quantity_on_hand}
                      threshold={m.reorder_threshold}
                      target={m.target_stock}
                      stockStatus={m.stock_status}
                      archived={m.archived}
                    />
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-3">
            <div>
              <CardTitle className="text-base">Expiring in next 30 days</CardTitle>
              <CardDescription>Active batches with stock remaining</CardDescription>
            </div>
            <Button variant="ghost" size="sm" onClick={() => onJumpToTab('medicines')}>
              View all →
            </Button>
          </CardHeader>
          <CardContent>
            {expiring.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
            {!expiring.isLoading && expiringCount === 0 && (
              <p className="text-sm text-muted-foreground">Nothing expires in the next 30 days.</p>
            )}
            {!expiring.isLoading && expiringCount > 0 && (
              <ul className="divide-y">
                {expiringTop.map((b) => {
                  const days = daysUntil(b.expiration_date);
                  return (
                    <li key={b.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                      <div className="min-w-0 flex-1">
                        <div className="truncate font-medium">{b.generic_name}</div>
                        <div className="truncate text-xs text-muted-foreground">
                          batch {b.batch_number} · {b.quantity_remaining}/{b.quantity_received} {b.unit}
                        </div>
                      </div>
                      <ExpiryChip days={days} />
                    </li>
                  );
                })}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Written-off panel — lots expired/recalled in the window. Each
          shows what was written off and when; the write-off happens on
          the Medicines tab's Batches dialog. */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between gap-2 space-y-0 pb-3">
          <div>
            <CardTitle className="text-base">Written off (expired / recalled)</CardTitle>
            <CardDescription>Lots zeroed in the last 90 days</CardDescription>
          </div>
          <Button variant="ghost" size="sm" onClick={() => onJumpToTab('medicines')}>
            View all →
          </Button>
        </CardHeader>
        <CardContent>
          {writtenOff.isLoading && <Loader2 className="mx-auto size-4 animate-spin text-muted-foreground" />}
          {!writtenOff.isLoading && writtenOffCount === 0 && (
            <p className="text-sm text-muted-foreground">No batches written off in the last 90 days.</p>
          )}
          {!writtenOff.isLoading && writtenOffCount > 0 && (
            <ul className="divide-y">
              {writtenOffTop.map((b) => (
                <li key={b.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-medium">{b.generic_name}</div>
                    <div className="truncate text-xs text-muted-foreground">
                      batch {b.batch_number} · wrote off {b.written_off ?? '?'} {b.unit}
                      {b.written_off_at !== null ? ` · ${fmtUtcToApp(b.written_off_at)}` : ''}
                    </div>
                  </div>
                  <Badge variant={BATCH_STATUS_VARIANT[b.status]}>{titleCase(b.status)}</Badge>
                </li>
              ))}
            </ul>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

/**
 * StatTile — single number + label, used by the Insights tab's top row.
 * The whole card is a button so clicking it jumps to the relevant tab.
 */
function StatTile({
  icon,
  label,
  value,
  tone,
  loading,
  onClick,
}: {
  icon: React.ReactNode;
  label: string;
  value: number | string;
  tone: 'success' | 'warning' | 'destructive' | 'info' | 'muted';
  loading: boolean;
  onClick?: () => void;
}) {
  const toneClass = {
    success:     'text-emerald-700 dark:text-emerald-300',
    warning:     'text-amber-700 dark:text-amber-300',
    destructive: 'text-rose-700 dark:text-rose-300',
    info:        'text-blue-700 dark:text-blue-300',
    muted:       'text-muted-foreground',
  }[tone];
  const Tag = onClick !== undefined ? 'button' : 'div';
  return (
    <Tag
      type={Tag === 'button' ? 'button' : undefined}
      onClick={onClick}
      className={cn(
        'rounded-xl border bg-card p-4 text-left shadow-sm transition-colors',
        onClick !== undefined && 'hover:bg-accent/40 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
      )}
    >
      <div className="mb-1 flex items-center gap-1.5 text-xs text-muted-foreground">
        {icon}
        <span>{label}</span>
      </div>
      <div className={cn('text-2xl font-semibold tabular-nums', toneClass)}>
        {loading ? <Loader2 className="size-5 animate-spin" /> : value}
      </div>
    </Tag>
  );
}
