/**
 * routeChunks — single source of truth for which routes are lazy
 * and which chunk each one lives in. Pairs with `lazyWithRetry` so
 * the first navigation to any route does not race Vite's
 * dep-optimizer. The sidebar, command palette, and any future
 * <Link> wrapper can use the same `prefetchRoute(path)` helper to
 * warm a chunk on intent (hover, focus, touchstart).
 *
 * Why a registry instead of a magic import.meta.glob?
 *   - Strong types: each entry is a real `() => Promise<unknown>`
 *     loader, so TypeScript can verify the path.
 *   - No glob fragility: adding a page does not require Vite to
 *     crawl the entire pages/ tree (the dep-optimizer already does
 *     that via `optimizeDeps.entries`).
 *   - One place to add route-level metadata (title, perms) that
 *     both the sidebar and the command palette consume.
 */
import { lazyWithRetry } from '@/lib/lazy';

type LazyComponent = ReturnType<typeof lazyWithRetry>;
type ChunkLoader = () => Promise<{ default: unknown }>;

interface RouteEntry {
  /** Path as registered in the router, e.g. `/admin/kiosk-settings`. */
  path: string;
  /** Lazy chunk loader. Throws on the import only if Vite is broken. */
  load: ChunkLoader;
  /** Memoised lazy component, populated at module load. */
  component: LazyComponent;
}

function route(path: string, importer: ChunkLoader): RouteEntry {
  return {
    path,
    load: importer,
    component: lazyWithRetry(importer as () => Promise<{ default: never }>),
  };
}

export const ROUTE_CHUNKS: ReadonlyArray<RouteEntry> = [
  route('/', () => import('@/pages/DashboardPage')),
  route('/change-password', () => import('@/pages/ChangePasswordPage')),
  route('/me', () => import('@/pages/EmployeePortalPage')),
  route('/clinic', () => import('@/pages/ClinicPage')),
  route('/patients', () => import('@/pages/PatientsPage')),
  route('/appointments', () => import('@/pages/AppointmentsPage')),
  route('/inventory', () => import('@/pages/InventoryPage')),
  route('/kiosk', () => import('@/pages/KioskPage')),
  route('/kiosk-station', () => import('@/pages/KioskStationPage')),
  route('/counselling', () => import('@/pages/CounsellingPage')),
  route('/referrals', () => import('@/pages/ReferralsPage')),
  route('/facilities', () => import('@/pages/FacilitiesPage')),
  route('/facilities/waste-categories', () => import('@/pages/WasteCategoriesPage')),
  route('/facilities/drums/:unitId', () => import('@/pages/DrumDetailPage')),
  route('/reports', () => import('@/pages/ReportsPage')),
  route('/audit', () => import('@/pages/AuditPage')),
  route('/admin/users', () => import('@/pages/AdminUsersPage')),
  route('/admin/kiosk-settings', () => import('@/pages/AdminKioskSettingsPage')),
];

const BY_PATH = new Map<string, RouteEntry>(
  ROUTE_CHUNKS.map((entry) => [entry.path, entry]),
);

const inflight = new Map<string, Promise<unknown>>();

/**
 * Warm the chunk for a given route path. Idempotent and safe to
 * call from any user-intent handler (hover, focus, touchstart).
 * Returns the in-flight (or resolved) promise so callers can
 * `await` it if they want to chain work, e.g. an instant nav.
 *
 * Unknown paths are no-ops — the registry only knows about the
 * app's own routes; framework routes like `/login` or `/403` are
 * imported eagerly elsewhere and don't need prefetching.
 */
export function prefetchRoute(path: string): Promise<unknown> | undefined {
  const entry = BY_PATH.get(path);
  if (entry === undefined) return undefined;
  // Touch the lazy field so the loader registers with Suspense
  // even if the chunk is already cached. The import below is the
  // real warm — it kicks the network/IO regardless of the lazy
  // memo.
  void entry.component;
  const existing = inflight.get(path);
  if (existing !== undefined) return existing;
  const promise = entry.load().catch(() => {
    // The lazy importer already retries transient dev errors; any
    // rejection here is a real failure (404, syntax). Swallow so
    // prefetch never throws back into a hover handler.
    inflight.delete(path);
  });
  inflight.set(path, promise);
  return promise;
}
