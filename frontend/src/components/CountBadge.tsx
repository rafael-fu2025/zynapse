/**
 * CountBadge — the tab-level counterpart of the sidebar's nav-item
 * counter. Same compact styling (10px mono, h-4) so a module's number
 * reads identically in the sidebar and on its tab, and the same
 * zero-suppression rule: nothing to count, nothing to render.
 *
 * The ml-1.5/lg:ml-0 pair slots it after the label in TabSections —
 * inline spacing on the mobile pill bar, none on the desktop rail
 * where TabSections already pushes badges to the right edge.
 */
import { Badge, type BadgeProps } from '@/components/ui/badge';

export function CountBadge({
  count,
  variant = 'info',
}: {
  count: number;
  variant?: BadgeProps['variant'];
}): JSX.Element | null {
  if (count <= 0) return null;
  return (
    <Badge variant={variant} className="ml-1.5 h-4 shrink-0 px-1.5 py-0 font-mono text-[10px] lg:ml-0">
      {count}
    </Badge>
  );
}
