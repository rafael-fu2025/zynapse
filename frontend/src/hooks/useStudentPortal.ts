/**
 * useStudentPortal — TanStack Query hooks for the student portal.
 *
 * Strictly self-scoped. 404 means "no student record linked" — a
 * normal empty state, not an error.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  bookStudentAppointmentSchema,
  studentAppointmentSchema,
  studentPortalClinicVisitSchema,
  studentPortalProfileSchema,
  studentProviderSchema,
  type BookStudentAppointmentInput,
  type StudentAppointment,
  type StudentPortalClinicVisit,
  type StudentPortalProfile,
  type StudentProvider,
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

/** Clinic staff who can see patients (minimal list for the picker). */
export function useStudentProviders() {
  return useQuery<StudentProvider[], ApiEnvelopeError>({
    queryKey: ['me', 'student-providers'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/me/student-providers');
      return res.data.map((p) => studentProviderSchema.parse(p));
    },
    staleTime: 60_000,
  });
}

/** The student's own appointments (latest 20). */
export function useMyStudentAppointments() {
  return useQuery<StudentAppointment[], ApiEnvelopeError>({
    queryKey: ['me', 'student-appointments'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/me/student-appointments');
      return res.data.map((a) => studentAppointmentSchema.parse(a));
    },
    refetchInterval: 30_000,
    staleTime: 15_000,
  });
}

/** Self-booking — POST /me/student-appointments for the calling student. */
export function useBookStudentAppointment() {
  const qc = useQueryClient();
  return useMutation<StudentAppointment, ApiEnvelopeError, BookStudentAppointmentInput>({
    mutationFn: async (input) => {
      const valid = bookStudentAppointmentSchema.parse(input);
      const res = await apiClient.post<StudentAppointment>('/me/student-appointments', valid);
      return studentAppointmentSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['me', 'student-appointments'] });
      toast.success('Appointment booked. Show up on the day — no need to queue.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to book the appointment.');
    },
  });
}
