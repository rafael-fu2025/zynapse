/**
 * ProtectedRoute — gates React Router v6 children by auth + RBAC.
 *
 * Usage:
 *   <Route element={<ProtectedRoute anyOf={['clinic.encounters.create']} />}>
 *     <Route path="/clinic/encounters/new" element={<NewEncounterPage />} />
 *   </Route>
 *
 * Redirects to /login when unauthenticated, /403 when forbidden.
 */
import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { hasPermission, useAuthStore } from '@/store/auth';

interface ProtectedRouteProps {
  children: ReactNode;
  /** Any-of: user must have at least one of these codes. */
  anyOf?: ReadonlyArray<string>;
  /** All-of: user must have every code. */
  allOf?: ReadonlyArray<string>;
}

export function ProtectedRoute({ children, anyOf, allOf }: ProtectedRouteProps) {
  const location = useLocation();
  const state = useAuthStore();

  if (!state.accessToken || state.userId === null) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (allOf !== undefined && allOf.length > 0) {
    const ok = allOf.every((p) => hasPermission(state, p));
    if (!ok) return <Navigate to="/403" replace />;
  }

  if (anyOf !== undefined && anyOf.length > 0) {
    const ok = anyOf.some((p) => hasPermission(state, p));
    if (!ok) return <Navigate to="/403" replace />;
  }

  return <>{children}</>;
}