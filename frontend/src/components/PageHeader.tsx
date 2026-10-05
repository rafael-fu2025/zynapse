/**
 * PageHeader — the standard main-content header for every page rendered
 * inside the app shell.
 *
 * Row 1: title + an info tooltip holding the page description on the
 * left, action buttons on the right. Row 2: breadcrumb trail (hidden on
 * mobile, where the topbar carries the title instead). Row 3 (optional):
 * tabs on the left, tab-scoped actions on the right. Row 4 (optional):
 * filters / search inside the standard toolbar card.
 *
 * The mobile topbar duplicates the page title (Layout.tsx resolves it
 * from pageMeta), so the in-page h1, info icon and breadcrumbs are
 * hidden below 768px — the h1 by the `[data-page-header]` rules in
 * styles/index.css, the icon/trail by their own `md:` classes. The data
 * attribute is deliberately nested-depth-agnostic — portal pages render
 * inside a wrapper div, so a `main > header` selector would miss them.
 *
 * Radix note: a <TabsList> must render inside its <Tabs> root, so pages
 * with tabs wrap <PageHeader> (passing the TabsList as `tabs`) inside
 * their <Tabs> element together with the body <TabsContent>s; React
 * context flows through this component, so the wiring is invisible.
 */
import type { ReactNode } from 'react';

import { Info } from 'lucide-react';

import { HeaderBreadcrumbs } from '@/components/HeaderBreadcrumbs';
import { cn } from '@/lib/utils';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';

const TOOLBAR_CARD_CLASSES = 'rounded-xl border bg-card p-3';
const TOOLBAR_FLEX_CLASSES = 'flex flex-wrap items-end justify-between gap-3';

interface PageHeaderProps {
  /** The h1 — string or node (leading icon, mono code, badge…). */
  title: ReactNode;
  /** Page definition — shown in the info tooltip beside the h1 on hover/focus. */
  description?: ReactNode;
  /** Row-1 right side: primary buttons, toggles, status pills. */
  actions?: ReactNode;
  /** Row-2 left: a <TabsList> (must stay inside the page's <Tabs> root). */
  tabs?: ReactNode;
  /** Row-2 right: tab-scoped buttons (e.g. "Add shift" on the staff tab). */
  tabsActions?: ReactNode;
  /** Row 3: filters / search, wrapped in the standard toolbar card. */
  toolbar?: ReactNode;
}

export function PageHeader({ title, description, actions, tabs, tabsActions, toolbar }: PageHeaderProps) {
  return (
    <>
      <header
        data-page-header
        className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
      >
        <div className="min-w-0 space-y-1">
          <div className="flex items-center gap-1.5">
            <h1 className="text-xl font-semibold text-foreground">{title}</h1>
            {description != null && (
              <TooltipProvider delayDuration={150}>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type="button"
                      aria-label="Page description"
                      className="hidden rounded-full text-muted-foreground/70 transition-colors hover:text-foreground focus-visible:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:inline-flex"
                    >
                      <Info aria-hidden className="size-3.5" />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="bottom" className="max-w-xs text-left leading-relaxed">
                    {description}
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
            )}
          </div>
          <HeaderBreadcrumbs />
        </div>
        {actions != null && (
          <div className="flex flex-wrap items-center gap-2">{actions}</div>
        )}
      </header>
      {(tabs != null || tabsActions != null) && (
        <div className="flex flex-wrap items-center justify-between gap-2">
          {tabs}
          {tabsActions != null && (
            <div className="flex flex-wrap items-center gap-2">{tabsActions}</div>
          )}
        </div>
      )}
      {toolbar != null && <div className={TOOLBAR_CARD_CLASSES}>{toolbar}</div>}
    </>
  );
}

/**
 * PageToolbar — the same bordered card as PageHeader's `toolbar` slot,
 * exported for filter/search rows that live inside tab content (e.g.
 * Patients' per-tab toolbars) so every toolbar in the app wears one
 * skin. Pass `className` to adjust the flex layout if needed.
 */
export function PageToolbar({
  className,
  children,
}: {
  className?: string;
  children: ReactNode;
}) {
  return (
    <div className={cn(TOOLBAR_CARD_CLASSES, TOOLBAR_FLEX_CLASSES, className)}>{children}</div>
  );
}
