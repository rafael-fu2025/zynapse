/**
 * React Router v6 — declarative routing with RBAC-aware guards.
 *
 * Pages are code-split with `React.lazy`: the initial bundle carries
 * only the shell, so first paint does not wait for every module page
 * to be transformed/downloaded (critical on the single-threaded PHP
 * dev server, and smaller chunks in production builds).
 */
import { Suspense } from 'react';
import {
  createBrowserRouter,
  isRouteErrorResponse,
  Link,
  Navigate,
  RouterProvider,
  useRouteError,
} from 'react-router-dom';
import Layout from '@/components/Layout';
import { Button } from '@/components/ui/button';
import { ProtectedRoute } from '@/components/ProtectedRoute';
import { useBootstrapSession } from '@/hooks/useBootstrapSession';
import { lazyWithRetry } from '@/lib/lazy';
import { hasPermission, useAuthStore } from '@/store/auth';

const AuditPage = lazyWithRetry(() => import('@/pages/AuditPage'));
const AdminUsersPage = lazyWithRetry(() => import('@/pages/AdminUsersPage'));
const AppointmentsPage = lazyWithRetry(() => import('@/pages/AppointmentsPage'));
const ChangePasswordPage = lazyWithRetry(() => import('@/pages/ChangePasswordPage'));
const EmployeePortalPage = lazyWithRetry(() => import('@/pages/EmployeePortalPage'));
const StudentPortalPage = lazyWithRetry(() => import('@/pages/StudentPortalPage'));
const ClinicPage = lazyWithRetry(() => import('@/pages/ClinicPage'));
const CounsellingPage = lazyWithRetry(() => import('@/pages/CounsellingPage'));
const DashboardPage = lazyWithRetry(() => import('@/pages/DashboardPage'));
const FacilitiesPage = lazyWithRetry(() => import('@/pages/FacilitiesPage'));
const WasteCategoriesPage = lazyWithRetry(() => import('@/pages/WasteCategoriesPage'));
const DrumDetailPage = lazyWithRetry(() => import('@/pages/DrumDetailPage'));
const ForbiddenPage = lazyWithRetry(() => import('@/pages/ForbiddenPage'));
const InventoryPage = lazyWithRetry(() => import('@/pages/InventoryPage'));
const KioskPage = lazyWithRetry(() => import('@/pages/KioskPage'));
const LoginPage = lazyWithRetry(() => import('@/pages/LoginPage'));
const PatientsPage = lazyWithRetry(() => import('@/pages/PatientsPage'));
const QueueDisplayPage = lazyWithRetry(() => import('@/pages/QueueDisplayPage'));
const ReferralsPage = lazyWithRetry(() => import('@/pages/ReferralsPage'));
const ReportsPage = lazyWithRetry(() => import('@/pages/ReportsPage'));

function PageFallback() {
  return (
    <p className="grid min-h-40 place-items-center text-sm text-muted-foreground" role="status">
      Loading…
    </p>
  );
}

/**
 * RouteError — route-level error boundary. A render/loader error inside
 * any page lands here instead of React Router's raw default screen, so
 * the user gets a branded recovery surface with a reload affordance.
 */
function RouteError() {
  const error = useRouteError();
  const status = isRouteErrorResponse(error) ? error.status : null;
  const detail =
    isRouteErrorResponse(error)
      ? `${error.status} ${error.statusText}`
      : error instanceof Error
        ? error.message
        : 'An unexpected error occurred.';

  return (
    <main className="grid min-h-dvh place-items-center p-6">
      <section className="max-w-md text-center" role="alert">
        <h1 className="text-3xl font-semibold text-foreground">
          {status === 404 ? 'Page not found' : 'Something went wrong'}
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">{detail}</p>
        <div className="mt-4 flex justify-center gap-2">
          <Button variant="outline" onClick={() => window.location.reload()}>
            Reload
          </Button>
          <Button asChild>
            <Link to="/">Return to dashboard</Link>
          </Button>
        </div>
      </section>
    </main>
  );
}

/**
 * NotFoundPage — real 404 for unknown URLs. Authenticated users keep
 * the shell (sidebar/topbar) since it is registered inside the
 * protected branch; the previous behavior silently redirected every
 * unknown path to the dashboard, giving no feedback on a typo.
 */
function NotFoundPage() {
  return (
    <main className="grid min-h-[60vh] place-items-center p-6">
      <section className="max-w-md text-center">
        <h1 className="text-3xl font-semibold text-foreground">404 — Page not found</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          The page you’re looking for doesn’t exist or has moved.
        </p>
        <Button asChild className="mt-4">
          <Link to="/">Return to dashboard</Link>
        </Button>
      </section>
    </main>
  );
}

function ProtectedShell({ children }: { children: React.ReactNode }) {
  const bootstrap = useBootstrapSession();
  if (bootstrap.isLoading) {
    return (
      <p className="grid min-h-dvh place-items-center text-sm text-muted-foreground">
        Restoring session…
      </p>
    );
  }
  return <ProtectedRoute>{children}</ProtectedRoute>;
}

/**
 * Dispatches the `/me` route to either the student or the
 * employee portal based on the caller's effective permissions.
 * The student perm wins when both are present (the more specific
 * surface) — staff members also have `employee.portal.read`,
 * but a staff member with a linked `patients_students` row
 * (e.g. a student worker) is logically a student first.
 */
function MyPortalDispatcher() {
  const state = useAuthStore();
  if (hasPermission(state, 'student.portal.read')) {
    return <StudentPortalPage />;
  }
  if (hasPermission(state, 'employee.portal.read')) {
    return <EmployeePortalPage />;
  }
  // ProtectedRoute above already gates on either perm; this is
  // defensive and should never run in practice.
  return <Navigate to="/" replace />;
}

const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  { path: '/403', element: <ForbiddenPage /> },
  // PUBLIC lobby-TV board — intentionally outside the protected shell.
  { path: '/queue-display', element: <QueueDisplayPage /> },
  {
    errorElement: <RouteError />,
    element: (
      <ProtectedShell>
        <Layout />
      </ProtectedShell>
    ),
    children: [
      { path: '/', element: <DashboardPage /> },
      { path: '/change-password', element: <ChangePasswordPage /> },
      {
        path: '/me',
        // Phase 13: dispatch the `/me` route to either the
        // employee or student portal based on the caller's
        // permissions. Employees fall through to the staff
        // portal; students land on their own surface; both
        // permissions are accepted (an admin or staff member
        // who is ALSO linked to a student row would see the
        // student portal — we treat the student perm as the
        // more specific surface).
        element: (
          <ProtectedRoute anyOf={['employee.portal.read', 'student.portal.read']}>
            <MyPortalDispatcher />
          </ProtectedRoute>
        ),
      },
      {
        path: '/clinic',
        element: (
          <ProtectedRoute anyOf={['clinic.encounters.read']}>
            <ClinicPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/patients',
        element: (
          <ProtectedRoute anyOf={['clinic.patients.read']}>
            <PatientsPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/inventory',
        element: (
          <ProtectedRoute anyOf={['clinic.inventory.read']}>
            <InventoryPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/appointments',
        element: (
          <ProtectedRoute anyOf={['clinic.appointments.read']}>
            <AppointmentsPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/kiosk',
        element: (
          <ProtectedRoute anyOf={['clinic.checkin.record']}>
            <KioskPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/counselling',
        element: (
          <ProtectedRoute anyOf={['counselling.records.read']}>
            <CounsellingPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/facilities',
        element: (
          <ProtectedRoute anyOf={['facilities.units.read']}>
            <FacilitiesPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/facilities/waste-categories',
        element: (
          <ProtectedRoute anyOf={['facilities.units.read']}>
            <WasteCategoriesPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/facilities/drums/:unitId',
        element: (
          <ProtectedRoute anyOf={['facilities.units.read']}>
            <DrumDetailPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/referrals',
        element: (
          <ProtectedRoute anyOf={['referrals.read']}>
            <ReferralsPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/reports',
        element: (
          <ProtectedRoute anyOf={['reports.read']}>
            <ReportsPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/audit',
        element: (
          <ProtectedRoute anyOf={['audit.read']}>
            <AuditPage />
          </ProtectedRoute>
        ),
      },
      {
        path: '/admin/users',
        element: (
          <ProtectedRoute anyOf={['rbac.manage']}>
            <AdminUsersPage />
          </ProtectedRoute>
        ),
      },
      // Unknown paths under the shell render a real 404 (with nav)
      // instead of silently redirecting to the dashboard.
      { path: '*', element: <NotFoundPage /> },
    ],
  },
]);

export function AppRouter() {
  return (
    <Suspense fallback={<PageFallback />}>
      {/* v7_startTransition: navigations to not-yet-loaded lazy chunks
          keep the CURRENT screen mounted instead of unwinding to the
          top-level Suspense fallback (which looked like a full page
          reload — sidebar and all vanished). */}
      <RouterProvider router={router} future={{ v7_startTransition: true }} />
    </Suspense>
  );
}