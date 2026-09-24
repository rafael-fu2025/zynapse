/**
 * NotificationDot — the red "there is something waiting" marker on a tab.
 *
 * Distinct from {@see CountBadge}, which is the neutral counter chip the
 * sidebar and dialog headings use. This one is deliberately red: it means
 * *pending work*, not *how much content exists*. A tab that merely holds
 * rows (Analytics, Services) carries no dot; a tab with an un-actioned
 * backlog does.
 *
 * Two shapes, one meaning:
 *   - `count` omitted → a bare 8px dot. The tab has a pending signal that
 *     is not a tally (e.g. "availability not configured yet").
 *   - `count` given and > 0 → the same dot grown into a pill carrying the
 *     numeral, capped at `9+` exactly like the notification bell.
 *   - `count` given and <= 0 → nothing renders. A countable tab with an
 *     empty backlog shows no dot at all.
 *
 * Colour matches {@see NotificationBell}: `--destructive`, which resolves
 * to a red in both themes. The numeral uses `tabular-nums text-[10px]` so it
 * stays legible inside a 16px chip.
 */
import { cn } from '@/lib/utils';

export function NotificationDot({
  count,
  label,
  className,
}: {
  /** Omit for a bare dot; pass a tally for a counted dot. */
  count?: number;
  /** Accessible description, e.g. "3 appointments today". */
  label: string;
  className?: string;
}): JSX.Element | null {
  if (count !== undefined && count <= 0) return null;

  if (count === undefined) {
    return (
      <span
        className={cn('inline-block size-2 shrink-0 rounded-full bg-destructive', className)}
        role="img"
        aria-label={label}
      />
    );
  }

  return (
    <span
      className={cn(
        'grid h-4 min-w-4 shrink-0 place-items-center rounded-full bg-destructive px-1 tabular-nums text-[10px] font-semibold text-destructive-foreground',
        className,
      )}
      role="img"
      aria-label={label}
    >
      {count > 9 ? '9+' : count}
    </span>
  );
}
