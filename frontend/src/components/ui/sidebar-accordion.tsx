/**
 * SidebarAccordion — a disclosure row for the main sidebar (2026-09-27).
 *
 * Behavior contract:
 *   - the row is a disclosure BUTTON, not a link: tapping it highlights
 *     the module (caller marks it selected) and toggles the child panel —
 *     it never navigates. The main content area changes only when the
 *     user taps one of the child links;
 *   - the button carries `aria-expanded` and `aria-controls` pointing at
 *     the panel; the chevron is decorative (`aria-hidden`);
 *   - collapsed content stays OUT of the tab order (`invisible`), while
 *     remaining mounted so the close animation can play;
 *   - the open/close animation is the grid-rows trick (`0fr -> 1fr`),
 *     which needs no measured heights, and disables itself under
 *     `prefers-reduced-motion`;
 *   - in the collapsed icon rail the chevron is hidden and a tap expands
 *     the rail (plus opens the panel) — a bare toggle would do nothing
 *     visible there;
 *   - single-expand: open state is CONTROLLED by the caller (AppSidebar
 *     owns one `openAccordion` id, so opening a module closes the
 *     others).
 */
import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useSidebar, SidebarMenuButton, SidebarMenuItem, SidebarMenuSub } from './sidebar';

export function SidebarAccordion({
  id,
  label,
  icon: Icon,
  open,
  onOpenChange,
  active,
  badge = null,
  children,
}: {
  /** Stable id — the panel derives `${id}-panel` for `aria-controls`. */
  id: string;
  label: string;
  icon: LucideIcon;
  open: boolean;
  /** Called with the next open state. The caller owns single-expand. */
  onOpenChange: (open: boolean) => void;
  /** Any child of this accordion is the surface on screen. */
  active: boolean;
  /** Right-edge indicator (the red notification dot), or null. */
  badge?: ReactNode;
  /** Panel content — the module's child links. */
  children: ReactNode;
}): JSX.Element {
  const { state, isMobile, setOpen } = useSidebar();
  const panelId = `${id}-panel`;

  return (
    <SidebarMenuItem>
      <SidebarMenuButton
        tooltip={label}
        isActive={active}
        onClick={() => {
          // Collapsed icon rail: the child list is hidden entirely, so the
          // tap must EXPAND the rail and force the panel open — a naive
          // toggle could close an already-open panel and still show nothing.
          if (state === 'collapsed' && !isMobile) {
            setOpen(true);
            onOpenChange(true);
            return;
          }
          onOpenChange(!open);
        }}
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
