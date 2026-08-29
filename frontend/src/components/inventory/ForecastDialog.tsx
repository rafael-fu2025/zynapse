import { Loader2, RefreshCw, TrendingUp } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useComputeForecast, useMedicineForecast } from '@/hooks/useMedicines';
import type { Medicine } from '@/schemas/medicines';

/**
 * ForecastDialog — read-only forecast view for one medicine with a
 * "Recompute" button (inventory audit fix: the forecast previously
 * only surfaced as a transient toast; the persisted forecast is now
 * viewable here).
 */
export function ForecastDialog({ medicine, onClose }: { medicine: Medicine; onClose: () => void }) {
  const compute = useComputeForecast();
  const forecast = useMedicineForecast(medicine.id);
  const f = forecast.data;

  return (
    <DialogContent className="max-w-md">
      <DialogHeader>
        <DialogTitle className="flex items-center gap-2">
          <TrendingUp className="size-4" /> Forecast — {medicine.generic_name}
        </DialogTitle>
      </DialogHeader>

      {forecast.isLoading && <Loader2 className="mx-auto size-5 animate-spin text-muted-foreground" />}

      {!forecast.isLoading && (f === null || f === undefined) && (
        <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
          No forecast yet. Compute one to see predicted daily use and stockout / reorder dates.
        </p>
      )}

      {f !== null && f !== undefined && (
        <dl className="grid grid-cols-2 gap-3 text-sm">
          <div className="rounded-md bg-muted/50 p-3">
            <dt className="text-xs text-muted-foreground">Predicted daily use</dt>
            <dd className="mt-1 text-lg font-semibold">{f.predicted_daily_usage}/day</dd>
          </div>
          <div className="rounded-md bg-muted/50 p-3">
            <dt className="text-xs text-muted-foreground">Model</dt>
            <dd className="mt-1 capitalize">{f.model_type.replace(/_/g, ' ')}</dd>
          </div>
          <div className="rounded-md bg-muted/50 p-3">
            <dt className="text-xs text-muted-foreground">Stockout date</dt>
            <dd className="mt-1 font-semibold">{f.predicted_stockout_date ?? '—'}</dd>
          </div>
          <div className="rounded-md bg-muted/50 p-3">
            <dt className="text-xs text-muted-foreground">Reorder by</dt>
            <dd className="mt-1 font-semibold">{f.predicted_reorder_date ?? '—'}</dd>
          </div>
        </dl>
      )}

      <DialogFooter>
        <Button variant="outline" onClick={onClose}>Close</Button>
        <Button disabled={compute.isPending} onClick={() => compute.mutate(medicine.id)}>
          {compute.isPending && <Loader2 className="animate-spin" />}
          <RefreshCw /> Recompute
        </Button>
      </DialogFooter>
    </DialogContent>
  );
}
