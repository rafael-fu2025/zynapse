/**
 * useEmployeePortal — TanStack Query hooks for the employee portal.
 *
 * The portal is purely self-scoped — no mutation hooks, no params.
 * The endpoints return 404 when the calling user has no linked
 * `patients_employees` row; the hooks surface the 404 to the page
 * so it can render a "you're not on the registry" empty state.
 */
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  employeePortalClinicVisitSchema,
  employeePortalProfileSchema,
  type EmployeePortalClinicVisit,
  type EmployeePortalProfile,
} from '@/schemas/employeePortal';

export function useMyEmployeeProfile() {
  return useQuery<EmployeePortalProfile, ApiEnvelopeError>({
    queryKey: ['me', 'employee-profile'],
    queryFn: async () => {
      const res = await apiClient.get<EmployeePortalProfile>('/me/employee-profile');
      return employeePortalProfileSchema.parse(res.data);
    },
    staleTime: 60_000,
    // 404 means "no employee record" — a normal empty state, not a
    // server error. We don't want TanStack to retry the lookup.
    retry: (count, err) => {
      if (err.httpStatus === 404) return false;
      return count < 2;
    },
  });
}

/**
 * "My clinic visits" — the logged-in record's encounters, or another of
 * the caller's own MIS records via `recordId` (the position-history
 * accordion; the backend 404s any record that is not the same person).
 * `recordId = null` keeps the pre-grouping behavior (own record only).
 */
export function useMyClinicVisits(limit = 50, recordId: number | null = null) {
  return useQuery<EmployeePortalClinicVisit[], ApiEnvelopeError>({
    queryKey: ['me', 'clinic-visits', limit, recordId],
    queryFn: async () => {
      const params = new URLSearchParams({ limit: String(limit) });
      if (recordId !== null) {
        params.set('record_id', String(recordId));
      }
      const res = await apiClient.get<EmployeePortalClinicVisit[]>(
        `/me/clinic-visits?${params.toString()}`,
      );
      return res.data.map((v) => employeePortalClinicVisitSchema.parse(v));
    },
    staleTime: 60_000,
    retry: (count, err) => {
      if (err.httpStatus === 404) return false;
      return count < 2;
    },
  });
}
