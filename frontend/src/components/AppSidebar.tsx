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
import { useEffect, useRef, useState } from 'react';
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
   * Render the item's children as student-only accordion sections. The
   * employee portal is a single surface with no tabs, so employees and
   * admins keep the flat row (same split as HomeDispatcher).
   */
  studentChildren?: boolean;
  /**
   * Extract count badge from dashboard counters. Color is owned by
   * CountBadge (adaptive tint) — call sites only supply the number.
   */
  badge?: (c: ReturnType<typeof useDashboardCounters>['data']) => { count: number; label: string } | null;
  /**
   * Child sections rendered as an accordion under this row instead of
   * an in-content tab strip (trial: Counselling, 2026-09-27; all
   * tabbed modules, 2026-09-27). Each child navigates to
   * `href?tab=<tab>`; visibility is gated independently, mirroring the
   * page's own allowedTabs. The FIRST visible child is the page's
   * default tab.
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
      // PURE STUDENTS get the portal's sections as an accordion — the
      // employee portal is a single surface with no tabs, so employees
      // (and admins) keep the flat row (same split as HomeDispatcher).
      {
        label: 'My portal',
        href: '/me',
        icon: IdCard,
        permission: ['employee.portal.read', 'student.portal.read'],
        hideForAdmin: true,
        studentChildren: true,
        children: [
          { label: 'Overview', tab: 'overview', permission: null },
          { label: 'Appointments', tab: 'appointments', permission: null },
          { label: 'History', tab: 'history', permission: null },
          { label: 'Guidance', tab: 'guidance', permission: null },
          { label: 'Notifications', tab: 'notifications', permission: null },
        ],
      },
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
        // The page's tab strip as an accordion (2026-09-27). Children
        // match the page's ?tab= values and order.
        children: [
          { label: 'Queue', tab: 'queue', permission: null },
          { label: 'Closed', tab: 'closed', permission: null },
          { label: 'Staff schedules', tab: 'staff', permission: null },
        ],
      },
      {
        label: 'Appointments',
        href: '/appointments',
        icon: CalendarClock,
        permission: 'clinic.appointments.read',
        children: [
          { label: 'Upcoming', tab: 'upcoming', permission: null },
          { label: 'Needs action', tab: 'needs-action', permission: null },
          { label: 'Past', tab: 'past', permission: null },
          { label: 'All', tab: 'all', permission: null },
        ],
      },
      {
        label: 'Patients',
        href: '/patients',
        icon: ContactRound,
        permission: 'clinic.patients.read',
        children: [
          { label: 'Students', tab: 'students', permission: null },
          { label: 'Employees', tab: 'employees', permission: null },
        ],
      },
      {
        label: 'Inventory',
        href: '/inventory',
        icon: Boxes,
        permission: 'clinic.inventory.read',
        children: [
          { label: 'Medicines', tab: 'medicines', permission: null },
          { label: 'Supplies', tab: 'supplies', permission: null },
          { label: 'Equipment', tab: 'equipment', permission: null },
          { label: 'Purchases', tab: 'reorders', permission: null },
          { label: 'Insights', tab: 'insights', permission: null },
        ],
      },
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
      {
        label: 'Reports',
        href: '/reports',
        icon: BarChart3,
        permission: 'reports.read',
        // The five report modules as accordion children; child links
        // preserve the current start/end range params when already on
        // the module (see childHref).
        children: [
          { label: 'Clinic', tab: 'clinic', permission: null },
          { label: 'Counselling', tab: 'counselling', permission: null },
          { label: 'Inventory', tab: 'inventory', permission: null },
          { label: 'Referrals', tab: 'referrals', permission: null },
          { label: 'Facilities', tab: 'facilities', permission: null },
        ],
      },
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

  // Accordions are SINGLE-EXPAND: one `openAccordion` id lives here, so
  // opening a module's section list closes the others and the sidebar never
  // becomes a wall of expanded sections.
  //
  // The module whose page is on screen at mount starts open, and ENTERING a
  // module's subtree from outside auto-opens its accordion (wayfinding —
  // the sidebar always shows where you are). Moving BETWEEN sibling
  // surfaces inside the same module subtree never re-opens it: a user who
  // collapsed the accordion stays collapsed until they leave the module or
  // open another one (the first cut forced it open on every render, and an
  // earlier auto-open fired on every navigation; both fought the user).
  //
  // Per-item active tab: the explicit `?tab=` when it names one of the
  // item's permission-visible children, otherwise the FIRST visible child —
  // which is each page's default tab by convention (Counselling's
  // permission-conditional default falls out of the same rule).
  const [openAccordion, setOpenAccordion] = useState<string | null>(() => {
    const active = NAV_SECTIONS.flatMap((s) => s.items).find(
      (i) =>
        i.children !== undefined &&
        (pathname === i.href || pathname.startsWith(`${i.href}/`)),
    );
    return active?.href ?? null;
  });
  const lastPathRef = useRef(pathname);
  useEffect(() => {
    const inside = (href: string) => pathname === href || pathname.startsWith(`${href}/`);
    const cameFromInside = (href: string) =>
      lastPathRef.current === href || lastPathRef.current.startsWith(`${href}/`);
    const active = NAV_SECTIONS.flatMap((s) => s.items).find(
      (i) => i.children !== undefined && inside(i.href),
    );
    if (active !== undefined && !cameFromInside(active.href)) {
      setOpenAccordion(active.href);
    }
    lastPathRef.current = pathname;
  }, [pathname]);
  const [params] = useSearchParams();
  const tabParam = params.get('tab');
  // Pure students get portal sections as accordion children; employees and
  // admins see the flat row (the employee portal has no tabs to map).
  const isPureStudent =
    hasPermission(state, 'student.portal.read') && !hasPermission(state, 'employee.portal.read');
  const visibleChildren = (item: NavItem): NavChild[] =>
    (item.children ?? [])
      .filter(() => !(item.studentChildren === true && !isPureStudent))
      .filter((ch) => hasAnyPermission(state, ch.permission));  /** Child-link URL: on the module page, carry the current params over
   * (Reports' start/end range survives); from elsewhere, start fresh. */
  const childHref = (item: NavItem, ch: NavChild): string => {
    if (pathname !== item.href) return `${item.href}?tab=${ch.tab}`;
    const target = new URLSearchParams(params);
    target.set('tab', ch.tab);
    return `${item.href}?${target.toString()}`;
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
                  {items.map((item) => {
                    const visible = visibleChildren(item);
                    const activeChildTab = visible.some((ch) => ch.tab === tabParam)
                      ? tabParam
                      : visible[0]?.tab;
                    const childActive =
                      visible.length > 0 &&
                      pathname === item.href &&
                      visible.some((ch) => ch.tab === activeChildTab);
                    return (
                    <SidebarMenuItem key={item.href}>
                      {/* Accordion only when children exist AND at least one
                          is visible (My portal collapses to a flat row for
                          employees/admins, who have no portal tabs). */}
                      {item.children !== undefined && visible.length > 0 ? (
                        <SidebarAccordion
                          id={`nav-${item.label.toLowerCase().replace(/\s+/g, '-')}`}
                          label={item.label}
                          icon={item.icon}
                          active={isActive(item.href) || childActive}
                          open={openAccordion === item.href}
                          onOpenChange={(next) =>
                            setOpenAccordion(next ? item.href : null)
                          }
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
                          {visible.map((ch) => (
                            <SidebarMenuSubItem key={ch.tab}>
                              <SidebarMenuSubButton
                                asChild
                                isActive={pathname === item.href && activeChildTab === ch.tab}
                              >
                                <NavLink
                                  to={childHref(item, ch)}
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
                  );})}
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
