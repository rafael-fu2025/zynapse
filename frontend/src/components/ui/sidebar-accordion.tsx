/**
 * SidebarAccordion — a disclosure row for the main sidebar: a trigger
 * button plus an animated child panel (2026-09-27).
 *
 * Behavior contract (WAI-ARIA disclosure pattern):
 *   - the trigger is a real button carrying `aria-expanded` and
 *     `aria-controls` pointing at the panel;
 *   - the chevron is decorative (`aria-hidden`) — state is conveyed by
 *     `aria-expanded`, never by the icon alone;
 *   - collapsed content stays OUT of the tab order (`invisible`), while
 *     remaining mounted so the close animation can play;
 *   - the open/close animation is the grid-rows trick
 *     (`0fr -> 1fr`), which needs no measured heights, and disables
 *     itself under `prefers-reduced-motion`.
 *
 * Single-expand: the component is CONTROLLED — open state is owned by
 * the caller (AppSidebar keeps one `openAccordion` id, so opening one
 * module closes the others and the sidebar never becomes a wall of
 * expanded sections). Navigation never force-changes the state; the
 * caller initializes it from the route at mount so a deep link loads
 * with its module's panel open.
 */
import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { SidebarMenuButton, SidebarMenuItem, SidebarMenuSub } from './sidebar';

export function SidebarAccordion({
  id,
  label,
  icon: Icon,
  open,
  onToggle,
  active,
  badge = null,
  children,
}: {
  /** Stable id — the panel derives `${id}-panel` for `aria-controls`. */
  id: string;
  label: string;
  icon: LucideIcon;
  open: boolean;
  onToggle: () => void;
  /** Any child of this accordion is the surface on screen. */
  active: boolean;
  /** Right-edge indicator (the red notification dot), or null. */
  badge?: ReactNode;
  /** Panel content — typically the module's child links. */
  children: ReactNode;
}): JSX.Element {
  const panelId = `${id}-panel`;

  return (
    <SidebarMenuItem>
      <SidebarMenuButton
        tooltip={label}
        isActive={active}
        onClick={onToggle}
        aria-expanded={open}
        aria-controls={panelId}
      >
        <Icon aria-hidden />
        <span className="flex-1 truncate">{label}</span>
        {badge}
        {/* Decorative: the state is announced through aria-expanded. */}
        <svg
          aria-hidden
          viewBox="0 0 24 24"
          className={cn(
            'size-4 shrink-0 transition-transform duration-200 motion-reduce:transition-none',
            open ? 'rotate-90' : '',
            badge === null && 'ml-auto',
          )}
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="m9 18 6-6-6-6" />
        </svg>
      </SidebarMenuButton>

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
