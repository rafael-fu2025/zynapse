/**
 * AppSidebar — primary navigation on the shadcn/ui Sidebar composite.
 *
 * Links are permission-gated with the same codes the router guards
 * use, so users only ever see modules they can open. Collapses to an
 * icon rail on desktop (Ctrl/Cmd+B or the header trigger) with
 * tooltips; renders as a Sheet on mobile.
 */
import {
  BarChart3,
  Bell,
  Boxes,
  CalendarClock,
  ContactRound,
  Factory,
  HeartPulse,
  IdCard,
  LayoutDashboard,
  MessagesSquare,
  Recycle,
  ScanLine,
  ScrollText,
  Settings,
  Share2,
  Users,
  type LucideIcon,
} from 'lucide-react';
import { NavLink, useLocation } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
  useSidebar,
} from '@/components/ui/sidebar';
import { prefetchRoute } from '@/lib/routeChunks';
import { useDashboardCounters } from '@/hooks/useDashboard';
import { hasPermission, useAuthStore } from '@/store/auth';

interface NavItem {
  label: string;
  href: string;
  icon: LucideIcon;
  /**
   * Visibility predicate:
   *   - `null`        : always visible when authenticated
   *   - `string`      : single perm required
   *   - `string[]`    : any-of the listed perms
   */
  permission: string | string[] | null;
  /**
   * Hide this item from admin (the `*` wildcard holder). Used for
   * patient-facing surfaces (e.g. "My portal") that are empty for
   * admin since admin has no student/employee record.
   */
  hideForAdmin?: boolean;
  /**
   * Extract count badge from dashboard counters.
   */
  badge?: (c: ReturnType<typeof useDashboardCounters>['data']) => { count: number; variant?: 'default' | 'destructive' | 'outline' | 'secondary' | 'warning' | 'info' } | null;
}

function hasAnyPermission(state: ReturnType<typeof useAuthStore.getState>, perm: string | string[] | null): boolean {
  if (perm === null) return true;
  const list = Array.isArray(perm) ? perm : [perm];
  return list.some((p) => hasPermission(state, p));
}

const NAV_SECTIONS: ReadonlyArray<{ title: string; items: ReadonlyArray<NavItem> }> = [
  {
    title: 'Overview',
    items: [
      // Dashboard is a staff/ops launchpad — pure students (no
      // employee.portal.read) get their portal instead, so the empty
      // Dashboard nav entry is hidden for them.
      { label: 'Dashboard', href: '/', icon: LayoutDashboard, permission: 'employee.portal.read' },
      // My portal — both staff and students can open `/me`; the
      // router dispatches to the right surface based on the
      // caller's permissions. Phase 13 extends the sidebar to
      // accept anyOf permission predicates. Hidden for admin: the
      // wildcard would route them to the (empty) student portal.
      { label: 'My portal', href: '/me', icon: IdCard, permission: ['employee.portal.read', 'student.portal.read'], hideForAdmin: true },
      { label: 'Notifications', href: '/notifications', icon: Bell, permission: 'notifications.read' },
    ],
  },
  {
    title: 'Clinic',
    items: [
      {
        label: 'Encounters',
        href: '/clinic',
        icon: HeartPulse,
        permission: 'clinic.encounters.read',
        badge: (c) => {
          const n = c?.clinic?.open_encounters ?? 0;
          return n > 0 ? { count: n, variant: 'info' } : null;
        },
      },
      { label: 'Appointments', href: '/appointments', icon: CalendarClock, permission: 'clinic.appointments.read' },
      { label: 'Patients', href: '/patients', icon: ContactRound, permission: 'clinic.patients.read' },
      { label: 'Inventory', href: '/inventory', icon: Boxes, permission: 'clinic.inventory.read' },
      { label: 'Check-in Kiosk', href: '/kiosk', icon: ScanLine, permission: 'clinic.checkin.record' },
    ],
  },
  {
    title: 'Guidance Center',
    items: [
      {
        label: 'Counselling',
        href: '/counselling',
        icon: MessagesSquare,
        permission: 'counselling.records.read',
        badge: (c) => {
          const n = c?.counselling?.open_sessions ?? 0;
          return n > 0 ? { count: n, variant: 'info' } : null;
        },
      },
    ],
  },
  {
    title: 'Referrals',
    items: [
      {
        label: 'All Referrals',
        href: '/referrals',
        icon: Share2,
        permission: 'referrals.read',
        badge: (c) => {
          const n = (c?.referrals?.submitted ?? 0) + (c?.referrals?.under_review ?? 0);
          return n > 0 ? { count: n, variant: 'warning' } : null;
        },
      },
    ],
  },
  {
    title: 'Facilities',
    items: [
      {
        label: 'Facilities',
        href: '/facilities',
        icon: Factory,
        permission: 'facilities.units.read',
        badge: (c) => {
          const risk = c?.facilities?.at_risk ?? 0;
          return risk > 0 ? { count: risk, variant: 'destructive' } : null;
        },
      },
      // Waste categories now live on their own screen (no longer a
      // dialog inside the Facilities page) — the sidebar entry links
      // straight to the dedicated route.
      { label: 'Waste Category', href: '/facilities/waste-categories', icon: Recycle, permission: 'facilities.units.read' },
    ],
  },
  {
    title: 'Administration',
    items: [
      { label: 'Reports', href: '/reports', icon: BarChart3, permission: 'reports.read' },
      { label: 'Audit', href: '/audit', icon: ScrollText, permission: 'audit.read' },
      { label: 'Users', href: '/admin/users', icon: Users, permission: 'rbac.manage' },
      { label: 'Kiosk Settings', href: '/admin/kiosk-settings', icon: Settings, permission: 'kiosk.content.manage' },
    ],
  },
];

export function AppSidebar() {
  const state = useAuthStore();
  const isAdmin = hasPermission(state, '*');
  const { pathname } = useLocation();
  const { setOpenMobile } = useSidebar();
  const counters = useDashboardCounters();

  const closeMobile = () => setOpenMobile(false);
  // Longest-prefix match: `/facilities/waste-categories` must light up
  // ONLY its own entry, not `/facilities` too — so a row is active when
  // its path prefixes the URL AND no other nav entry matches more
  // specifically (longer prefix).
  const allPaths = NAV_SECTIONS.flatMap((s) => s.items.map((i) => i.href.split('?')[0] ?? i.href));
  const isActive = (href: string) => {
    const path = href.split('?')[0] ?? href;
    if (path === '/') return pathname === '/';
    if (pathname !== path && !pathname.startsWith(`${path}/`)) return false;
    return !allPaths.some(
      (p) => p.length > path.length && (pathname === p || pathname.startsWith(`${p}/`)),
    );
  };

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            {/* Brand row — hover/press highlight and the collapsed-rail
                tooltip are deliberately off: a logo banner is not a
                menu row. */}
            <SidebarMenuButton
              size="lg"
              asChild
              className="hover:bg-transparent hover:text-sidebar-foreground active:bg-transparent active:text-sidebar-foreground"
            >
              <NavLink to="/" onClick={closeMobile}>
                <img
                  src="/synapse-white.png"
                  alt=""
                  aria-hidden
                  className="size-8 shrink-0 scale-150 object-contain"
                />
                <span className="ml-3 truncate font-semibold tracking-wide group-data-[collapsible=icon]:hidden">
                  SYNAPSE
                </span>
              </NavLink>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      {/* Primary navigation landmark — the skip link and assistive tech
          target this; the spec suite asserts it by name. */}
      <SidebarContent role="navigation" aria-label="Primary">
        {NAV_SECTIONS.map((section) => {
          const items = section.items.filter(
            (i) => !(i.hideForAdmin && isAdmin) && hasAnyPermission(state, i.permission),
          );
          if (items.length === 0) return null;

          return (
            <SidebarGroup key={section.title}>
              <SidebarGroupLabel>{section.title}</SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {items.map((item) => (
                    <SidebarMenuItem key={item.href}>
                      <SidebarMenuButton asChild isActive={isActive(item.href)} tooltip={item.label}>
                        {/*
                          Intent-based chunk prefetch. Fires on the
                          earliest signal a user might be heading
                          to this route:
                            - mouseenter (desktop hover)
                            - focus      (keyboard / screen reader)
                            - touchstart (mobile tap; cheaper than
                                          waiting for the click)
                          Vite has already pre-bundled the chunk via
                          optimizeDeps.entries, so the import is a
                          warm cache hit and resolves in <1ms — the
                          dynamic-import race is gone before the
                          user can click.
                        */}
                        <NavLink
                          to={item.href}
                          end={item.href === '/'}
                          onClick={closeMobile}
                          onMouseEnter={() => void prefetchRoute(item.href)}
                          onFocus={() => void prefetchRoute(item.href)}
                          onTouchStart={() => void prefetchRoute(item.href)}
                        >
                          <item.icon aria-hidden />
                          <span className="flex-1 truncate">{item.label}</span>
                          {item.badge !== undefined && counters.data !== undefined && (() => {
                            const b = item.badge(counters.data);
                            if (!b || b.count <= 0) return null;
                            return (
                              <Badge
                                variant={b.variant ?? 'default'}
                                className="ml-auto px-1.5 py-0 text-[10px] font-mono h-4 shrink-0 group-data-[collapsible=icon]:hidden"
                              >
                                {b.count}
                              </Badge>
                            );
                          })()}
                        </NavLink>
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  ))}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          );
        })}
      </SidebarContent>

      <SidebarRail />
    </Sidebar>
  );
}
