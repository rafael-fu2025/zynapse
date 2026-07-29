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
      { label: 'Dashboard', href: '/', icon: LayoutDashboard, permission: null },
      // My portal — both staff and students can open `/me`; the
      // router dispatches to the right surface based on the
      // caller's permissions. Phase 13 extends the sidebar to
      // accept anyOf permission predicates.
      { label: 'My portal', href: '/me', icon: IdCard, permission: ['employee.portal.read', 'student.portal.read'] },
    ],
  },
  {
    title: 'Clinic',
    items: [
      { label: 'Encounters', href: '/clinic', icon: HeartPulse, permission: 'clinic.encounters.read' },
      { label: 'Patients', href: '/patients', icon: ContactRound, permission: 'clinic.patients.read' },
      { label: 'Appointments', href: '/appointments', icon: CalendarClock, permission: 'clinic.appointments.read' },
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
    ],
  },
];

export function AppSidebar() {
  const state = useAuthStore();
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
                <span className="truncate font-semibold tracking-wide group-data-[collapsible=icon]:hidden">
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
            (i) => hasAnyPermission(state, i.permission),
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
                        <NavLink to={item.href} end={item.href === '/'} onClick={closeMobile}>
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
