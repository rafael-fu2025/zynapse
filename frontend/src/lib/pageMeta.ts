/**
 * pageMeta — single source of truth for the topbar title shown on mobile
 * and the breadcrumb labels in PageHeader. Pages still render their own
 * H1 on desktop; the topbar mirrors the same strings here so the mobile
 * header carries the page identity without a context-propagating API.
 *
 * Page definitions are NOT stored here — they live as `description`
 * props on each page's <PageHeader> and surface in its info tooltip.
 *
 * Add a new entry whenever a route's H1 changes; the route key is
 * the React Router `path` pattern (without `:params`).
 */
export interface PageMeta {
  /** Short title shown in the topbar; should match the page H1. */
  title: string;
}

export const PAGE_META: Readonly<Record<string, PageMeta>> = {
  '/': { title: 'Dashboard' },
  '/me': { title: 'My portal' },
  '/clinic': { title: 'Clinic' },
  '/appointments': { title: 'Appointments' },
  '/patients': { title: 'Records' },
  '/inventory': { title: 'Inventory' },
  '/counselling': { title: 'Counselling' },
  '/counselling/surveys': { title: 'Surveys' },
  '/counselling/announcements': { title: 'Announcements' },
  '/counselling/analytics': { title: 'Analytics' },
  '/counselling/services': { title: 'Services' },
  '/facilities': { title: 'Facilities' },
  '/facilities/drums': { title: 'Drums' },
  '/facilities/waste-categories': { title: 'Waste categories' },
  '/facilities/devices': { title: 'Devices' },
  '/admin': { title: 'Administration' },
  '/admin/users': { title: 'Users' },
  '/notifications': { title: 'Notifications' },
  '/referrals': { title: 'Referrals' },
  '/reports': { title: 'Reports and Analytics' },
  '/audit': { title: 'Audit evidence' },
  '/change-password': { title: 'Change password' },
};

/** Best-effort lookup by pathname. Falls back to a sensible empty meta. */
export function resolvePageMeta(pathname: string): PageMeta {
  // Exact match first.
  const direct = PAGE_META[pathname];
  if (direct !== undefined) return direct;

  // Strip trailing slash and try again.
  const trimmed = pathname.replace(/\/+$/, '');
  if (trimmed !== '' && PAGE_META[trimmed] !== undefined) {
    return PAGE_META[trimmed];
  }

  return { title: '' };
}
