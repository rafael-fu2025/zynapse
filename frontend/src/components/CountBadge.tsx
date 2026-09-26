/**
 * CountBadge — the app-wide counter badge: sidebar nav rows, TabSections
 * labels, and dialog section headings all render their counts through
 * this one component so the numeral reads identically everywhere.
 *
 * `count` also carries the Counselling "now serving" ticket number (a
 * string like "G-012"), which renders as the same chip stretched to a
 * stadium — one geometry for every live indicator in the shell.
 *
 * Shape is a perfect circle at one digit (16px, matching the bell dot)
 * that grows into a stadium for more digits. The fill is a solid
 * muted-foreground chip with a background-colored numeral: mid-gray is
 * the one tone that keeps ≥2.8:1 separation from every surface the
 * badge sits on — maroon rail, dark rail, white active pill, muted
 * panels, white cards — so one color serves all of them (an earlier
 * 15% adaptive tint merged into the rails). No per-call-site colors.
 *
 * Spacing is owned by the call site via `className` (TabSections wraps
 * the badge; the sidebar pushes it to the row edge with ml-auto).
 */
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';

export function CountBadge({
  count,
  className,
}: {
  count: number | string;
  className?: string;
}): JSX.Element | null {
  if (count === '' || (typeof count === 'number' && count <= 0)) return null;
  return (
    <Badge
      variant="outline"
      className={cn(
        'h-4 min-w-4 justify-center rounded-full border-transparent bg-muted-foreground px-1 py-0 tabular-nums text-[0.625rem] text-background',
        className,
      )}
    >
      {count}
    </Badge>
  );
}
