/**
 * PageHeader — the standard main-content header for every page rendered
 * inside the app shell.
 *
 * Row 1: title + description on the left, action buttons on the right.
 * Row 2 (optional): tabs on the left, tab-scoped actions on the right.
 * Row 3 (optional): filters / search inside the standard toolbar card.
 *
 * The mobile topbar duplicates the page title (Layout.tsx resolves it
 * from pageMeta), so the in-page h1 and description are hidden below
 * 768px by the `[data-page-header]` rules in styles/index.css. The data
 * attribute is deliberately nested-depth-agnostic — portal pages render
 * inside a wrapper div, so a `main > header` selector would miss them.
 *
 * Radix note: a <TabsList> must render inside its <Tabs> root, so pages
 * with tabs wrap <PageHeader> (passing the TabsList as `tabs`) inside
 * their <Tabs> element together with the body <TabsContent>s; React
 * context flows through this component, so the wiring is invisible.
 */
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

const TOOLBAR_CARD_CLASSES = 'rounded-xl border bg-card p-3';
const TOOLBAR_FLEX_CLASSES = 'flex flex-wrap items-end justify-between gap-3';

interface PageHeaderProps {
  /** The h1 — string or node (leading icon, mono code, badge…). */
  title: ReactNode;
  /** Muted line under the h1; hidden on mobile (the topbar shows it). */
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
          <h1 className="text-xl font-semibold text-foreground">{title}</h1>
          {description != null && (
            <p data-page-header-description className="text-sm text-muted-foreground">
              {description}
            </p>
          )}
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
