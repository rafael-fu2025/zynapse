/**
 * DrumCard — one drum's live batch, as a tile in the "Processing Drums"
 * grid.
 *
 * Presentational and pure: it reads a batch and renders. Every wording
 * decision is delegated to `lib/bmgFormat` so the drum grid and the drum
 * detail page cannot drift apart, and every colour decision lives here.
 *
 * Read-only by design — this is a status surface. State transitions all
 * live in the facilities table's row menu.
 */
import { Link } from 'react-router-dom';
import type { ReactNode } from 'react';

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import {
  describeBatchPhase,
  describeEta,
  describeTurning,
  formatDayCount,
  formatKg,
  formatProgress,
  type Readout,
  type Tone,
} from '@/lib/bmgFormat';
import type { ActiveBatch } from '@/schemas/facilities';
import { fmtShort } from '@/utils/date';

/**
 * Tone → text class. The `warning` amber is the same dark-aware pair
 * the `warning` Badge variant uses; there is no `--warning` token in the
 * palette, which is why the readout uses the raw amber scale rather than
 * a `text-warning` class that would silently not render.
 */
const TONE_TEXT: Record<Tone, string> = {
  neutral: 'text-foreground',
  muted: 'text-muted-foreground',
  info: 'text-foreground',
  warning: 'text-amber-700 dark:text-amber-400',
  danger: 'text-destructive',
};

/**
 * Theme-aware drum graphic: maroon on light, white on dark. The white
 * asset's filename is misspelled upstream (`drum-whte`), so it is
 * referenced as shipped rather than "fixed" and broken.
 *
 * Exported because the section header uses the same artwork at a
 * smaller size.
 */
export function DrumArt({ className }: { className?: string }) {
  return (
    <>
      <img
        src="/drum-maroon.png"
        alt=""
        aria-hidden
        draggable={false}
        className={cn(className, 'object-contain dark:hidden')}
      />
      <img
        src="/drum-whte.png"
        alt=""
        aria-hidden
        draggable={false}
        className={cn(className, 'hidden object-contain dark:block')}
      />
    </>
  );
}

/** One `<dt>`/`<dd>` pair. Single definition so no row can diverge. */
function FactRow({
  label,
  children,
  className,
  valueClassName,
}: {
  label: string;
  children: ReactNode;
  className?: string;
  valueClassName?: string;
}) {
  return (
    <div className={cn('flex items-baseline justify-between gap-2', className)}>
      <dt className="shrink-0 text-[0.6875rem] uppercase tracking-wide text-muted-foreground">
        {label}
      </dt>
      <dd className={cn('min-w-0 truncate text-right text-xs font-semibold', valueClassName)}>
        {children}
      </dd>
    </div>
  );
}

function ReadoutValue({ readout, title }: { readout: Readout; title?: string }) {
  return (
    <span className={TONE_TEXT[readout.tone]} title={title}>
      {readout.label}
    </span>
  );
}

export interface DrumCardProps {
  batch: ActiveBatch;
  /** Turning cadence from the API; null until the payload loads. */
  turningDueDays: number | null;
  className?: string;
}

export function DrumCard({ batch, turningDueDays, className }: DrumCardProps) {
  const phase = describeBatchPhase(batch.input_kg);
  const eta = describeEta(batch.days_until_expected);
  const turning = describeTurning(batch.days_since_last_turning, turningDueDays);
  const progress = formatProgress(batch.progress_pct);
  const isLoading = batch.input_kg <= 0;

  // The whole tile is the link, so its accessible name has to lead with
  // the drum's identity. A visually-hidden prefix does that WITHOUT an
  // `aria-label`, which would have replaced — and hidden — the batch,
  // waste and weight text that follows it. The decorative "Open" in the
  // footer is aria-hidden so it can't become the link's name.
  const accessibleName = `Drum ${batch.unit_code}, ${batch.unit_name}, batch ${batch.batch_code}, ${phase.label}`;

  return (
    <Link
      to={`/facilities/drums/${batch.unit_id}`}
      className={cn(
        'group flex flex-col gap-2.5 rounded-lg border bg-card p-3 text-left',
        'transition-[border-color,box-shadow,transform] duration-150',
        'hover:-translate-y-px hover:border-primary/40 hover:shadow-md',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
        className,
      )}
    >
      <span className="sr-only">{accessibleName}</span>

      <div className="flex justify-center py-0.5" aria-hidden>
        <DrumArt className="h-14 w-auto" />
      </div>

      <header className="flex items-start justify-between gap-2 border-b border-border/60 pb-2">
        <div className="min-w-0" aria-hidden>
          <p className="truncate text-sm font-bold tabular-nums tracking-wide text-foreground">
            {batch.unit_code}
          </p>
          <p className="truncate text-xs text-muted-foreground" title={batch.unit_name}>
            {batch.unit_name}
          </p>
        </div>
        <Badge variant={isLoading ? 'info' : 'warning'} className="shrink-0 uppercase" aria-hidden>
          {isLoading ? 'Input' : 'Processing'}
        </Badge>
      </header>

      <dl className="space-y-1.5 text-xs" aria-hidden>
        <FactRow label="Batch">
          <span className="font-mono text-[0.6875rem] [overflow-wrap:anywhere]">
            {batch.batch_code}
          </span>
        </FactRow>

        <FactRow label="Waste">
          <span title={batch.category_name ?? undefined}>{batch.category_name ?? '—'}</span>
        </FactRow>

        <FactRow label="Input">
          <span className="tabular-nums">{formatKg(batch.input_kg)}</span>
        </FactRow>

        <FactRow label="Expected done">
          <span className="tabular-nums">
            {batch.expected_completion_date !== null
              ? fmtShort(batch.expected_completion_date)
              : '—'}
          </span>
          <span className="ml-1.5 font-medium">
            {eta.label}
          </span>
        </FactRow>

        <FactRow label="Last turned">
          <ReadoutValue readout={turning} />
        </FactRow>
      </dl>

      <ProgressBar
        unitCode={batch.unit_code}
        value={progress.value}
        label={progress.label}
      />

      <footer className="mt-0.5 flex items-center justify-between border-t border-dashed border-border/60 pt-2">
        <Badge variant={batch.days_active > 30 ? 'warning' : 'info'} aria-hidden>
          {formatDayCount(batch.days_active)} active
        </Badge>
        <span
          aria-hidden
          className="text-xs font-medium text-primary transition-colors group-hover:underline"
        >
          Open
        </span>
      </footer>
    </Link>
  );
}

function ProgressBar({
  unitCode,
  value,
  label,
}: {
  unitCode: string;
  value: number;
  label: string;
}) {
  return (
    <div
      className="mt-0.5 h-1.5 overflow-hidden rounded-full bg-muted"
      role="progressbar"
      // Named for this drum specifically: a grid of identically-named
      // progressbars is unintelligible to a screen reader.
      aria-label={`Decomposition progress for ${unitCode}`}
      aria-valuenow={value}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuetext={`${label} toward expected completion`}
    >
      <div
        className="h-full rounded-full bg-gradient-to-r from-primary/70 to-primary transition-[width] duration-500"
        style={{ width: `${value}%` }}
      />
    </div>
  );
}
