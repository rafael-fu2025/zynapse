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
  Cpu,
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
  /**
   * Stable identity for sidebar state (open-accordion, selected-module,
   * owner highlighting, React keys). Required whenever two items share
   * an href — e.g. the Records entry under Clinic and its Guidance
   * Center twin, or the two Referrals rows — otherwise every
   * href-keyed mechanism would drive both items at once.
   */
  key?: string;
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

/** Stable identity for sidebar state — explicit key when given, else href. */
function itemKey(item: NavItem): string {
  return item.key ?? item.href;
}

/** Strip a nav href's query for path matching (Guidance Records carries
    `?side=guidance`; path identity is `/patients`). */
function itemPath(item: NavItem): string {
  return item.href.split('?')[0] ?? item.href;
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
        // match the page's ?tab= values and order. `Skipped` joined the
        // list in the October 2026 recall-window revision; `Archived`
        // followed with the archive action in the same month.
        children: [
          { label: 'Queue', tab: 'queue', permission: null },
          { label: 'Skipped', tab: 'skipped', permission: null },
          { label: 'Closed', tab: 'closed', permission: null },
          { label: 'Archived', tab: 'archived', permission: null },
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
        label: 'Records',
        href: '/patients',
        key: 'records-clinic',
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
      // Referrals split by side (2026-09-27) — see the Guidance Center
      // sibling for the rationale. Clinic staff reach the handoffs here.
      {
        label: 'Clinic Referrals',
        href: '/referrals',
        key: 'referrals-clinic',
        icon: Share2,
        permission: 'clinic.encounters.read',
        badge: (c) => {
          const n = (c?.referrals?.submitted ?? 0) + (c?.referrals?.under_review ?? 0);
          return n > 0 ? { count: n, label: `${n} referrals awaiting action` } : null;
        },
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
      // The same registry, surfaced for guidance staff — but NOT the same
      // view: clinic records and guidance records carry different actions
      // (clinic registers/treats/archives; guidance refers to clinic), so
      // this entry opens the registry in its guidance side
      // (`?side=guidance`), which the page renders with the guidance
      // action set. Gated on the guidance code rather than the clinic one;
      // holders of both permissions see both entries, each opening its own
      // side (identities are keyed separately — see itemKey).
      {
        label: 'Records',
        href: '/patients?side=guidance',
        key: 'records-guidance',
        icon: ContactRound,
        permission: 'counselling.records.read',
        children: [
          { label: 'Students', tab: 'students', permission: null },
          { label: 'Employees', tab: 'employees', permission: null },
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
      // Referrals split by side (2026-09-27): the old Referrals section's
      // single "All Referrals" row became one entry per section, gated on
      // that section's domain code — clinic staff reach referrals under
      // Clinic, guidance staff under Guidance Center, and holders of both
      // see both. Both deep-link to the same page, which keeps its own
      // status filter and "showing your referrals" scoping.
      {
        label: 'Guidance Referrals',
        href: '/referrals',
        key: 'referrals-guidance',
        icon: Share2,
        permission: 'counselling.records.read',
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
      // Same pattern for the automated tumblers: a dedicated screen,
      // not a dialog on the Facilities page.
      { label: 'Devices', href: '/facilities/devices', icon: Cpu, permission: 'facilities.units.read' },
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
          // Unit-scoped analytics — same gates the backend applies
          // (ReportService::MODULE_EXTRA_PERMISSIONS).
          { label: 'Counselling', tab: 'counselling', permission: 'counselling.records.read' },
          { label: 'Inventory', tab: 'inventory', permission: null },
          { label: 'Referrals', tab: 'referrals', permission: null },
          { label: 'Facilities', tab: 'facilities', permission: 'facilities.units.read' },
        ],
      },
      { label: 'Audit', href: '/audit', icon: ScrollText, permission: 'audit.read' },
      { label: 'Users', href: '/admin/users', icon: Users, permission: 'rbac.manage' },
      { label: 'Roles', href: '/admin/roles', icon: ShieldCheck, permission: 'rbac.read' },
    ],
  },
];

/**
 * The nav item whose href is the LONGEST matching prefix of the path —
 * the same rule isActive applies. A flat sibling surface
 * (/counselling/surveys) outranks its parent module (/counselling), so
 * the accordion wayfinding below only ever claims the module's OWN pages;
 * landing on Surveys, Announcements, Analytics or Services — by tap or
 * by reload — never opens the Counselling accordion.
 */
function accordionOwner(pathname: string, side: string | null): NavItem | undefined {
  let best: NavItem | undefined;
  let bestLen = -1;
  let bestSideMatch = false;
  for (const item of NAV_SECTIONS.flatMap((s) => s.items)) {
    // Query strings are presentation, not identity — match on the path
    // only (Guidance Records carries `?side=guidance`).
    const path = itemPath(item);
    const inside = pathname === path || pathname.startsWith(`${path}/`);
    if (!inside) continue;
    const itemSide = new URLSearchParams(item.href.split('?')[1] ?? '').get('side');
    const sideMatch = itemSide === side;
    // Longer path always wins; among equal paths (the two Records
    // entries on /patients) the one whose `side` matches the URL wins.
    if (path.length > bestLen || (path.length === bestLen && sideMatch && !bestSideMatch)) {
      best = item;
      bestLen = path.length;
      bestSideMatch = sideMatch;
    }
  }
  return best;
}

export function AppSidebar() {
  const state = useAuthStore();
  const isAdmin = hasPermission(state, '*');
  const { pathname } = useLocation();
  const { setOpenMobile } = useSidebar();
  const counters = useDashboardCounters();

  const closeMobile = () => setOpenMobile(false);
  // Row highlighting is owner-based (see isRowActive below): the nav item
  // with the longest matching path lights up, which is what kept sibling
  // surfaces like /counselling/surveys from also lighting /counselling.

  // The last-tapped accordion label stays highlighted even though the tap
  // deliberately does NOT navigate — the content area keeps showing the
  // previous module until the user picks one of the newly revealed child
  // sections. Any navigation clears it; route-derived rules take over.
  const [selectedModule, setSelectedModule] = useState<string | null>(null);
  useEffect(() => {
    setSelectedModule(null);
  }, [pathname]);
  const [params] = useSearchParams();
  const tabParam = params.get('tab');
  const sideParam = params.get('side');

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
  // The owner of a path is the nav item with the LONGEST matching href —
  // the same rule as isActive. A flat sibling surface (/counselling/surveys)
  // outranks its parent module (/counselling), so landing on Surveys,
  // Announcements, Analytics or Services — by tap or by reload — never
  // claims the Counselling accordion; only the module's own pages do.
  // Identity is the item KEY, not the href: the Clinic and Guidance Records
  // entries share `/patients`, and keying by href would drive both
  // accordions (and both highlights) at once.
  const [openAccordion, setOpenAccordion] = useState<string | null>(() => {
    const owner = accordionOwner(pathname, null);
    return owner?.children !== undefined ? itemKey(owner) : null;
  });
  const lastPathRef = useRef(pathname);
  useEffect(() => {
    const cameFromInside = (href: string) =>
      lastPathRef.current === href || lastPathRef.current.startsWith(`${href}/`);
    const owner = accordionOwner(pathname, sideParam);
    if (owner?.children !== undefined && !cameFromInside(itemPath(owner))) {
      setOpenAccordion(itemKey(owner));
    }
    lastPathRef.current = pathname;
  }, [pathname, sideParam]);
  // Pure students get portal sections as accordion children; employees and
  // admins see the flat row (the employee portal has no tabs to map).
  const isPureStudent =
    hasPermission(state, 'student.portal.read') && !hasPermission(state, 'employee.portal.read');
  const visibleChildren = (item: NavItem): NavChild[] =>
    (item.children ?? [])
      .filter(() => !(item.studentChildren === true && !isPureStudent))
      .filter((ch) => hasAnyPermission(state, ch.permission));
  /** Child-link URL: the item's own query seeds the target (Guidance
   * Records pins `side=guidance`; the item's side wins over whatever side
   * the current page is on), the current page's params overlay it
   * (Reports' start/end survives), and `tab` is set last. */
  const childHref = (item: NavItem, ch: NavChild): string => {
    const [base, itemQuery = ''] = item.href.split('?');
    const target = new URLSearchParams(itemQuery);
    if (pathname === base) {
      for (const [k, v] of params) {
        if (k !== 'side') target.append(k, v);
      }
    }
    target.set('tab', ch.tab);
    return `${base}?${target.toString()}`;
  };
  /** Row highlighting goes to the path's OWNER (see accordionOwner) plus
   * the last-tapped module — never to every item sharing the href. */
  const ownerKey = (() => {
    const owner = accordionOwner(pathname, sideParam);
    return owner !== undefined ? itemKey(owner) : null;
  })();
  const isRowActive = (item: NavItem): boolean =>
    (ownerKey !== null && itemKey(item) === ownerKey) || selectedModule === itemKey(item);

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
                    // Accordion only when children exist AND at least one
                    // is visible (My portal collapses to a flat row for
                    // employees/admins, who have no portal tabs).
                    // SidebarAccordion renders its own SidebarMenuItem —
                    // wrapping it in another would nest <li> in <li>.
                    if (item.children !== undefined && visible.length > 0) {
                      return (
                        <SidebarAccordion
                          key={itemKey(item)}
                          id={`nav-${itemKey(item).replace(/[^a-z0-9-]+/gi, '-')}`}
                          label={item.label}
                          icon={item.icon}
                          // Row tap: select the module (highlight moves here,
                          // content stays put) + open its panel. Children do
                          // the actual navigating.
                          onOpenChange={(next) => {
                            if (next) setSelectedModule(itemKey(item));
                            setOpenAccordion(next ? itemKey(item) : null);
                          }}
                          // The module row carries the bare-URL case on its
                          // own — nothing in the child list is auto-selected.
                          active={isRowActive(item)}
                          open={openAccordion === itemKey(item)}
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
                                // A child lights up ONLY when its own ?tab=
                                // value is explicit — no auto-selection of
                                // the first entry.
                                isActive={pathname === itemPath(item) && tabParam === ch.tab}
                              >
                                <NavLink
                                  to={childHref(item, ch)}
                                  onClick={closeMobile}
                                  onMouseEnter={() => void prefetchRoute(itemPath(item))}
                                  onFocus={() => void prefetchRoute(itemPath(item))}
                                >
                                  <span className="truncate">{ch.label}</span>
                                </NavLink>
                              </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                          ))}
                        </SidebarAccordion>
                      );
                    }
                    return (
                      <SidebarMenuItem key={itemKey(item)}>
                        <SidebarMenuButton asChild isActive={isRowActive(item)} tooltip={item.label}>
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
                          end={itemPath(item) === '/'}
                          onClick={closeMobile}
                          onMouseEnter={() => void prefetchRoute(itemPath(item))}
                          onFocus={() => void prefetchRoute(itemPath(item))}
                          onTouchStart={() => void prefetchRoute(itemPath(item))}
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
                      </SidebarMenuItem>
                    );
                  })}
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
