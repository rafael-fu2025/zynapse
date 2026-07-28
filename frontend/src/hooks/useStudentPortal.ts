/**
 * useStudentPortal — TanStack Query hooks for the student portal.
 *
 * Strictly self-scoped: no mutations, no params. 404 means "no
 * student record linked" — a normal empty state, not an error.
 */
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  studentPortalClinicVisitSchema,
  studentPortalProfileSchema,
  type StudentPortalClinicVisit,
  type StudentPortalProfile,
} from '@/schemas/studentPortal';

export function useMyStudentProfile() {
  return useQuery<StudentPortalProfile, ApiEnvelopeError>({
    queryKey: ['me', 'student-profile'],
    queryFn: async () => {
      const res = await apiClient.get<StudentPortalProfile>('/me/student-profile');
      return studentPortalProfileSchema.parse(res.data);
    },
    staleTime: 60_000,
    retry: (count, err) => {
      if (err.httpStatus === 404) return false;
      return count < 2;
    },
  });
}

export function useMyStudentClinicVisits(limit = 50) {
  return useQuery<StudentPortalClinicVisit[], ApiEnvelopeError>({
    queryKey: ['me', 'student-clinic-visits', limit],
    queryFn: async () => {
      const res = await apiClient.get<StudentPortalClinicVisit[]>(
        `/me/student-clinic-visits?limit=${limit}`,
      );
      return res.data.map((v) => studentPortalClinicVisitSchema.parse(v));
    },
    staleTime: 60_000,
    retry: (count, err) => {
      if (err.httpStatus === 404) return false;
      return count < 2;
    },
  });
}
