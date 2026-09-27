/**
 * SidebarAccordion — a disclosure row for the main sidebar: the row
 * navigates to the module, a dedicated chevron toggles the child panel,
 * and the panel animates open/closed (2026-09-27).
 *
 * Behavior contract:
 *   - the ROW is a link — tapping a module always navigates to it (its
 *     default section) and OPENS the panel; the row never collapses it;
 *   - the chevron is the disclosure toggle: a real button carrying
 *     `aria-expanded` and `aria-controls` pointing at the panel;
 *   - collapsed content stays OUT of the tab order (`invisible`), while
 *     remaining mounted so the close animation can play;
 *   - the open/close animation is the grid-rows trick (`0fr -> 1fr`),
 *     which needs no measured heights, and disables itself under
 *     `prefers-reduced-motion`;
 *   - in the collapsed icon rail the chevron is hidden and the row tap
 *     expands the rail (plus opens the panel) — a bare toggle would do
 *     nothing visible there;
 *   - single-expand: open state is CONTROLLED by the caller
 *     (AppSidebar owns one `openAccordion` id, so opening a module
 *     closes the others); navigation never force-changes it.
 */
import type { ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useSidebar, SidebarMenuButton, SidebarMenuItem, SidebarMenuSub } from './sidebar';

export function SidebarAccordion({
  id,
  label,
  icon: Icon,
  href,
  open,
  onOpenChange,
  active,
  badge = null,
  onPrefetch,
  children,
}: {
  /** Stable id — the panel derives `${id}-panel` for `aria-controls`. */
  id: string;
  label: string;
  icon: LucideIcon;
  href: string;
  open: boolean;
  /** Called with the next open state. The caller owns single-expand. */
  onOpenChange: (open: boolean) => void;
  /** Any child of this accordion is the surface on screen. */
  active: boolean;
  /** Right-edge indicator (the red notification dot), or null. */
  badge?: ReactNode;
  /** Intent prefetch (hover/focus on any row), wired by the caller. */
  onPrefetch?: () => void;
  /** Panel content — the module's child links. */
  children: ReactNode;
}): JSX.Element {
  const { state, setOpen, setOpenMobile } = useSidebar();
  const panelId = `${id}-panel`;

  return (
    <SidebarMenuItem>
      {/* Row: navigates AND opens the panel — tapping a module must move
          the user there and show where they are (the tap never collapses
          the panel; the chevron owns collapse). */}
      <div className="flex w-full items-center gap-0.5">
        <SidebarMenuButton asChild isActive={active} tooltip={label} className="h-8 flex-1">
          <NavLink
            to={href}
            onClick={() => {
              onOpenChange(true);
              // Collapsed icon rail: the child list is hidden entirely, so
              // the tap must EXPAND the rail too, or nothing shows.
              if (state === 'collapsed') setOpen(true);
              setOpenMobile(false);
              onPrefetch?.();
            }}
            onMouseEnter={() => onPrefetch?.()}
            onFocus={() => onPrefetch?.()}
          >
            <Icon aria-hidden />
            <span className="flex-1 truncate">{label}</span>
            {badge}
          </NavLink>
        </SidebarMenuButton>
        {/* Separate disclosure toggle — the row itself is a link. */}
        <button
          type="button"
          aria-label={`Toggle ${label} sections`}
          aria-expanded={open}
          aria-controls={panelId}
          onClick={() => onOpenChange(!open)}
          className={cn(
            'mr-1 flex size-7 shrink-0 items-center justify-center rounded-md',
            'text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring',
            'group-data-[collapsible=icon]:hidden',
          )}
        >
          <svg
            aria-hidden
            viewBox="0 0 24 24"
            className={cn(
              'size-4 transition-transform duration-200 motion-reduce:transition-none',
              open ? 'rotate-90' : '',
            )}
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="m9 18 6-6-6-6" />
          </svg>
        </button>
      </div>

      {/* Mounted while animating; `invisible` keeps the collapsed links out
          of the tab order without unmounting them mid-transition. */}
      <div
        id={panelId}
        className={cn(
          'grid transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none',
          open ? 'visible grid-rows-[1fr]' : 'invisible grid-rows-[0fr]',
        )}
      >
        <div className="min-h-0 overflow-hidden">
          <SidebarMenuSub>{children}</SidebarMenuSub>
        </div>
      </div>
    </SidebarMenuItem>
  );
}
