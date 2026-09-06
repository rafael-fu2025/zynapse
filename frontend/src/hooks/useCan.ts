/**
 * useCan — permission gate for UI affordances.
 *
 * The backend enforces everything; this hook keeps the UI honest by
 * hiding write actions a role can never complete instead of letting
 * staff click into guaranteed 403 toasts (2026-09 audit: Patients,
 * Appointments, Referrals and Inventory all showed write buttons to
 * read-only roles). Mirrors `ProtectedRoute`'s anyOf semantics.
 */
import { hasPermission, useAuthStore } from '@/store/auth';

export function useCan(permission: string | string[]): boolean {
  const state = useAuthStore();
  if (Array.isArray(permission)) {
    return permission.some((code) => hasPermission(state, code));
  }
  return hasPermission(state, permission);
}
