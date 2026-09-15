/**
 * TabSections — the app's section navigation pattern (2026-09-15).
 *
 * On wide screens the sections render as a secondary sidebar beside the
 * content — the same muted-box + pill design as the horizontal tabs,
 * rotated: icons, left-aligned labels, maroon active pill (theme
 * tokens), 1px primary hover ring, sticky under the shell topbar.
 * Below `lg` the original horizontal pill bar stays (it already scrolls
 * sideways on narrow viewports via the TabsList base styles).
 *
 * Must render INSIDE the page's `<Tabs>` root — Radix context flows
 * through, so the page keeps full ownership of `value` /
 * `onValueChange` / URL logic. Children are the `<TabsContent>`s and
 * land in the content column.
 *
 * Implementation notes baked in from the InventoryPage prototype's
 * iterations:
 *   - The sidebar TabsList needs an explicit `flex` — the base component
 *     is `inline-flex`, which shrinks the muted box to its text width
 *     instead of filling the 14rem column.
 *   - `h-auto` + `md:h-auto` override the base `h-10` / `md:h-9` so the
 *     box hugs its items vertically (not the table height).
 *   - 14rem is a deliberate step down from the 16rem primary sidebar.
 *   - Badges (live queue numbers, counts) render inline on mobile and
 *     push to the right edge (`ml-auto`) in the sidebar.
 */
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { TabsList, TabsTrigger } from '@/components/ui/tabs';

export interface TabSection {
  value: string;
  label: ReactNode;
  icon: LucideIcon;
  /** Optional live indicator (queue number, count) shown after the label. */
  badge?: ReactNode;
}

export function TabSections({
  tabs,
  ariaLabel,
  children,
}: {
  tabs: readonly TabSection[];
  ariaLabel: string;
  children: ReactNode;
}): JSX.Element {
  return (
    <>
      {/* Mobile / tablet: the original horizontal pill bar. */}
      <TabsList className="lg:hidden" aria-label={ariaLabel}>
        {tabs.map((t) => (
          <TabsTrigger key={t.value} value={t.value}>
            {t.label}
            {t.badge}
          </TabsTrigger>
        ))}
      </TabsList>

      <div className="lg:grid lg:grid-cols-[14rem_minmax(0,1fr)] lg:gap-6">
        {/* Desktop secondary sidebar. */}
        <div className="hidden lg:sticky lg:top-20 lg:block lg:self-start">
          <TabsList
            aria-label={ariaLabel}
            aria-orientation="vertical"
            className="flex h-auto flex-col items-stretch justify-start gap-0.5 md:h-auto md:justify-start"
          >
            {tabs.map((t) => {
              const Icon = t.icon;
              return (
                <TabsTrigger
                  key={t.value}
                  value={t.value}
                  className="justify-start gap-2.5 py-2.5 hover:ring-1 hover:ring-primary data-[state=active]:bg-primary data-[state=active]:text-primary-foreground md:py-2.5"
                >
                  <Icon className="size-4 shrink-0" aria-hidden />
                  <span className="min-w-0 truncate">{t.label}</span>
                  {t.badge !== undefined && (
                    <span className="ml-auto shrink-0">{t.badge}</span>
                  )}
                </TabsTrigger>
              );
            })}
          </TabsList>
        </div>

        <div className="min-w-0">{children}</div>
      </div>
    </>
  );
}
