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
 *   - Badges (live queue numbers, counts) and notification dots render
 *     inline on mobile and push to the right edge (`ml-auto`) in the
 *     sidebar. Both share one trailing flex group so a section carrying
 *     either — or both — keeps a single right edge.
 *
 * **Grouping** (2026-09-23). A section may carry a `group` label, and
 * consecutive sections sharing one render under a single heading — the
 * Counselling page uses this to split eight flat sections into three
 * named clusters. Pages that pass no `group` render exactly as before,
 * which is why this stayed a single optional field rather than a new
 * component: eight pages share this nav.
 *
 * Two implementation notes, both load-bearing:
 *   - Headings are emitted as **flat siblings** of the triggers, never as
 *     wrapper elements. The horizontal bar is an `inline-flex` row and the
 *     sidebar is a `flex-col`; a wrapper would become a single flex item
 *     and collapse each cluster into a stack. Fragments keep the flex
 *     children where the layout expects them.
 *   - Grouping is by **consecutive run**, so interleaved groups would
 *     render two headings with the same text rather than silently
 *     reordering sections. Order is never changed here — callers own it.
 */
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Fragment, useId } from 'react';
import { TabsList, TabsTrigger } from '@/components/ui/tabs';

export interface TabSection {
  value: string;
  label: ReactNode;
  icon: LucideIcon;
  /**
   * Optional cluster label. Consecutive sections sharing a value render
   * under one heading; `undefined` leaves the section ungrouped (and a
   * page where nothing is grouped renders flat, as it always did).
   */
  group?: string;
  /** Optional neutral counter chip (queue number, count) shown after the label. */
  badge?: ReactNode;
  /**
   * Optional red notification marker — a {@see NotificationDot}. Unlike
   * `badge`, which reports how much content a section holds, this reports
   * pending work. Sections with no backlog simply omit it.
   */
  notify?: ReactNode;
}

export interface TabCluster {
  /** `null` for a run of ungrouped sections. */
  group: string | null;
  /** Id of this cluster's heading, referenced by its triggers. `null` when ungrouped. */
  headingId: string | null;
  items: readonly TabSection[];
}

/**
 * Split tabs into consecutive same-group runs.
 *
 * Interleaving is preserved rather than merged, so a repeated group label
 * appears twice instead of the nav silently resequencing itself — order is the
 * caller's business.
 *
 * Exported for tests: it is the only part of this component with real logic
 * (the rest is two nearly identical JSX blocks), and the repo's Vitest setup is
 * node-only on purpose, so pure helpers are what get covered.
 */
export function clusterTabs(
  tabs: readonly Pick<TabSection, 'value' | 'group'>[],
  baseId: string,
): TabCluster[] {
  const clusters: TabCluster[] = [];
  tabs.forEach((tab, index) => {
    const group = tab.group ?? null;
    const current = clusters[clusters.length - 1];
    if (current !== undefined && current.group === group) {
      (current.items as TabSection[]).push(tab as TabSection);
      return;
    }
    clusters.push({
      group,
      headingId: group === null ? null : `${baseId}-group-${index}`,
      items: [tab as TabSection],
    });
  });
  return clusters;
}

/** Trailing indicators, kept in one flex group so `ml-auto` stays a single edge. */
function TabIndicators({ badge, notify }: { badge?: ReactNode; notify?: ReactNode }) {
  if (badge === undefined && notify === undefined) return null;
  return (
    <>
      {badge}
      {notify}
    </>
  );
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
  // Stable per-instance prefix so heading ids never collide when two
  // grouped navs share a page.
  const baseId = useId();
  const clusters = clusterTabs(tabs, baseId);

  /** A heading is decorative *inside* the tablist — the label reaches
   *  assistive tech through `aria-describedby` on each trigger instead,
   *  which keeps the tablist's children semantics clean. */
  const heading = (cluster: TabCluster, className: string) =>
    cluster.group === null ? null : (
      <div role="presentation" id={cluster.headingId ?? undefined} className={className}>
        {cluster.group}
      </div>
    );

  /** Group name carried on the trigger, not baked into its accessible name. */
  const describedBy = (cluster: TabCluster) =>
    cluster.headingId === null ? undefined : { 'aria-describedby': cluster.headingId };

  return (
    <>
      {/* Mobile / tablet: the original horizontal pill bar. */}
      <TabsList className="lg:hidden" aria-label={ariaLabel}>
        {clusters.map((cluster, clusterIndex) => (
          <Fragment key={cluster.headingId ?? `flat-${clusterIndex}`}>
            {heading(
              cluster,
              'shrink-0 pl-1.5 pr-0.5 text-[0.625rem] font-semibold uppercase tracking-wide text-muted-foreground',
            )}
            {cluster.items.map((t) => (
              <TabsTrigger key={t.value} value={t.value} {...describedBy(cluster)}>
                {t.label}
                {(t.badge !== undefined || t.notify !== undefined) && (
                  <span className="ml-1.5 inline-flex shrink-0 items-center gap-1.5">
                    <TabIndicators badge={t.badge} notify={t.notify} />
                  </span>
                )}
              </TabsTrigger>
            ))}
          </Fragment>
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
            {clusters.map((cluster, clusterIndex) => (
              <Fragment key={cluster.headingId ?? `flat-${clusterIndex}`}>
                {heading(
                  cluster,
                  'mt-3 px-2.5 pb-0.5 text-[0.625rem] font-semibold uppercase tracking-wide text-muted-foreground first:mt-0',
                )}
                {cluster.items.map((t) => {
                  const Icon = t.icon;
                  return (
                    <TabsTrigger
                      key={t.value}
                      value={t.value}
                      {...describedBy(cluster)}
                      className="justify-start gap-2.5 py-2.5 hover:ring-1 hover:ring-primary data-[state=active]:bg-primary data-[state=active]:text-primary-foreground md:py-2.5"
                    >
                      <Icon className="size-4 shrink-0" aria-hidden />
                      <span className="min-w-0 truncate">{t.label}</span>
                      {(t.badge !== undefined || t.notify !== undefined) && (
                        <span className="ml-auto flex shrink-0 items-center gap-1.5">
                          <TabIndicators badge={t.badge} notify={t.notify} />
                        </span>
                      )}
                    </TabsTrigger>
                  );
                })}
              </Fragment>
            ))}
          </TabsList>
        </div>

        <div className="min-w-0">{children}</div>
      </div>
    </>
  );
}
