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
      { label: 'Encounters', href: '/clinic', icon: HeartPulse, permission: 'clinic.encounters.read' },
      { label: 'Appointments', href: '/appointments', icon: CalendarClock, permission: 'clinic.appointments.read' },
      { label: 'Patients', href: '/patients', icon: ContactRound, permission: 'clinic.patients.read' },
      { label: 'Inventory', href: '/inventory', icon: Boxes, permission: 'clinic.inventory.read' },
      { label: 'Check-in Kiosk', href: '/kiosk', icon: ScanLine, permission: 'clinic.checkin.record' },
    ],
  },
  {
    title: 'Care',
    items: [
      { label: 'Counselling', href: '/counselling', icon: MessagesSquare, permission: 'counselling.records.read' },
      { label: 'Referrals', href: '/referrals', icon: Share2, permission: 'referrals.read' },
    ],
  },
  {
    title: 'Facilities',
    items: [
      { label: 'Facilities', href: '/facilities', icon: Factory, permission: 'facilities.units.read' },
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
      { label: 'Kiosk Settings', href: '/admin/kiosk-settings', icon: Settings, permission: 'rbac.manage' },
    ],
  },
];

export function AppSidebar() {
  const state = useAuthStore();
  const isAdmin = hasPermission(state, '*');
  const { pathname } = useLocation();
  const { setOpenMobile } = useSidebar();

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
            <SidebarMenuButton size="lg" asChild tooltip="Dashboard">
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

      <SidebarContent>
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
                          <span>{item.label}</span>
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
