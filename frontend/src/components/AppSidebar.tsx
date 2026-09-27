/**
 * AppSidebar — primary navigation on the shadcn/ui Sidebar composite.
 *
 * Links are permission-gated with the same codes the router guards
 * use, so users only ever see modules they can open. Collapses to an
 * icon rail on desktop (Ctrl/Cmd+B or the header trigger) with
 * tooltips; renders as a Sheet on mobile. Modules may declare child
 * sections, which render as an accordion expanding under the parent
 * row (trial: Counselling, 2026-09-27) instead of an in-content tab
 * strip.
 */
import {
  BarChart3,
  Boxes,
  CalendarClock,
  ClipboardList,
  ContactRound,
  Factory,
  HeartHandshake,
  HeartPulse,
  IdCard,
  LayoutDashboard,
  Megaphone,
  MessagesSquare,
  Recycle,
  ScrollText,
  Share2,
  ShieldCheck,
  Users,
  type LucideIcon,
} from 'lucide-react';
import { NavLink, useLocation, useSearchParams } from 'react-router-dom';
import { SidebarAccordion } from '@/components/ui/sidebar-accordion';
import { NotificationDot } from '@/components/NotificationDot';
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
  SidebarMenuSubButton,
  SidebarMenuSubItem,
  SidebarRail,
  useSidebar,
} from '@/components/ui/sidebar';
import { prefetchRoute } from '@/lib/routeChunks';
import { useDashboardCounters } from '@/hooks/useDashboard';
import { hasPermission, useAuthStore } from '@/store/auth';

interface NavChild {
  label: string;
  /** Tab value the target page reads from its `?tab=` URL param. */
  tab: string;
  permission: string | string[] | null;
}

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
   * Extract count badge from dashboard counters. Color is owned by
   * CountBadge (adaptive tint) — call sites only supply the number.
   */
  badge?: (c: ReturnType<typeof useDashboardCounters>['data']) => { count: number; label: string } | null;
  /**
   * Child sections rendered as an accordion under this row instead of
   * an in-content tab strip (trial: Counselling, 2026-09-27). Each
   * child navigates to `href?tab=<tab>`; visibility is gated
   * independently, mirroring the page's own allowedTabs.
   */
  children?: ReadonlyArray<NavChild>;
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
      // Notifications live in the topbar bell (NotificationBell) — no
      // sidebar entry, so the inbox stays one click from every screen
      // without a second navigation surface.
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
          return n > 0 ? { count: n, label: `${n} open clinic encounters` } : null;
        },
      },
      { label: 'Appointments', href: '/appointments', icon: CalendarClock, permission: 'clinic.appointments.read' },
      { label: 'Patients', href: '/patients', icon: ContactRound, permission: 'clinic.patients.read' },
      { label: 'Inventory', href: '/inventory', icon: Boxes, permission: 'clinic.inventory.read' },
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
          return n > 0 ? { count: n, label: `${n} open guidance sessions` } : null;
        },
        // Trial (2026-09-27): the page's tab strip moved here as an
        // accordion. Children gate and order-match the page's own
        // allowedTabs; Appointments and Scheduling stay ungated on the
        // page for everyone who passes the module gate.
        children: [
          { label: 'Queue', tab: 'queue', permission: 'counselling.queue.read' },
          { label: 'Appointments', tab: 'appointments', permission: null },
          { label: 'Follow-ups', tab: 'followups', permission: 'counselling.responses.read_any' },
          { label: 'Scheduling', tab: 'scheduling', permission: null },
        ],
      },
      // The four content surfaces moved here from the Counselling page's tab
      // strip (2026-09-24). Flat sibling rows, following the Facilities →
      // Waste Category precedent rather than nesting: the longest-prefix
      // active check then lights the child row and not `/counselling`.
      // Each is gated on its own backend-enforced code, not the module-wide
      // `counselling.records.read` the Counselling row carries.
      { label: 'Surveys', href: '/counselling/surveys', icon: ClipboardList, permission: 'counselling.surveys.manage' },
      { label: 'Announcements', href: '/counselling/announcements', icon: Megaphone, permission: 'counselling.announcements.manage' },
      { label: 'Analytics', href: '/counselling/analytics', icon: BarChart3, permission: 'counselling.schedule.read' },
      { label: 'Services', href: '/counselling/services', icon: HeartHandshake, permission: 'counselling.services.manage' },
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
          return n > 0 ? { count: n, label: `${n} referrals awaiting action` } : null;
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
          return risk > 0 ? { count: risk, label: `${risk} drums at risk` } : null;
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
      { label: 'Roles', href: '/admin/roles', icon: ShieldCheck, permission: 'rbac.read' },
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

  // The counselling accordion (the one item with children so far) owns its
  // own open state inside <SidebarAccordion> — a STABLE component defined at
  // module level. The first cut nested this component inside AppSidebar,
  // which re-created the component type every render, remounted the subtree
  // and reset the open state — that is why the accordion forced itself open
  // again after being closed.
  const [params] = useSearchParams();
  const canReadQueue = hasPermission(state, 'counselling.queue.read');
  // The tab the target page would render: the explicit `?tab=` when present,
  // otherwise the page's default (Queue for queue-capable staff, else the
  // appointment book).
  const activeTab = params.get('tab') ?? (canReadQueue ? 'queue' : 'appointments');

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
                      {item.children !== undefined ? (
                        <SidebarAccordion
                          id={`nav-${item.label.toLowerCase().replace(/\s+/g, '-')}`}
                          label={item.label}
                          icon={item.icon}
                          active={isActive(item.href) || (pathname === item.href && item.children.some(
                            (ch) => ch.tab === activeTab && hasAnyPermission(state, ch.permission),
                          ))}
                          // autoOpen on any /counselling/* route (sibling
                          // surfaces included) is deliberate — but the
                          // child highlight is NOT: it applies only on the
                          // tabbed page itself (exact match), or moving to
                          // Surveys would keep the last tab lit.
                          autoOpen={pathname === item.href || pathname.startsWith(`${item.href}/`)}
                          badge={item.badge !== undefined && counters.data !== undefined
                            ? (() => {
                                const b = item.badge(counters.data);
                                if (!b || b.count <= 0) return null;
                                return (
                                  <NotificationDot
                                    label={b.label}
                                    className="group-data-[collapsible=icon]:hidden"
                                  />
                                );
                              })()
                            : null}
                        >
                          {(item.children ?? []).filter((ch) => hasAnyPermission(state, ch.permission)).map((ch) => (
                            <SidebarMenuSubItem key={ch.tab}>
                              <SidebarMenuSubButton
                                asChild
                                isActive={pathname === item.href && activeTab === ch.tab}
                              >
                                <NavLink
                                  to={`${item.href}?tab=${ch.tab}`}
                                  onClick={closeMobile}
                                  onMouseEnter={() => void prefetchRoute(item.href)}
                                  onFocus={() => void prefetchRoute(item.href)}
                                >
                                  <span className="truncate">{ch.label}</span>
                                </NavLink>
                              </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                          ))}
                        </SidebarAccordion>
                      ) : (
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
                              <NotificationDot
                                label={b.label}
                                className="ml-auto group-data-[collapsible=icon]:hidden"
                              />
                            );
                          })()}
                        </NavLink>
                      </SidebarMenuButton>
                      )}
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
