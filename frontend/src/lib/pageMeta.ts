/**
 * pageMeta — single source of truth for the topbar title/description
 * shown on mobile. Pages still render their own H1 on desktop; the
 * topbar mirrors the same strings here so the mobile header carries
 * the page identity without a context-propagating API.
 *
 * Add a new entry whenever a route's H1 changes; the route key is
 * the React Router `path` pattern (without `:params`).
 */
export interface PageMeta {
  /** Short title shown in the topbar; should match the page H1. */
  title: string;
  /** Optional supporting line. If empty, only the title is rendered. */
  description?: string;
}

export const PAGE_META: Readonly<Record<string, PageMeta>> = {
  '/': { title: 'Dashboard', description: 'Signed in as your account — Asia/Manila' },
  '/me': { title: 'My portal' },
  '/clinic': {
    title: 'Clinic',
    description: 'Encounters are isolated from counselling.',
  },
  '/appointments': {
    title: 'Appointments',
    description: 'Times shown in Asia/Manila; stored in UTC.',
  },
  '/patients': { title: 'Patients' },
  '/inventory': { title: 'Inventory' },
  '/kiosk': { title: 'Check-in Kiosk' },
  '/counselling': { title: 'Counselling' },
  '/facilities': { title: 'Facilities — BMG' },
  '/referrals': { title: 'Referrals' },
  '/reports': { title: 'Reports & Analytics' },
  '/audit': { title: 'Audit' },
  '/admin/users': {
    title: 'Users',
    description: 'Deactivation is soft — accounts are never deleted.',
  },
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
