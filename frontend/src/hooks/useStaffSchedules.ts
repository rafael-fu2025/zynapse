/**
 * Clinic staff schedule hooks (Phase P5b). Admin-managed recurring shift
 * roster — gated server-side by `clinic.schedules.manage`.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  createStaffScheduleSchema,
  staffScheduleSchema,
  type CreateStaffScheduleInput,
  type StaffSchedule,
} from '@/schemas/staffSchedule';

const KEY = ['clinic', 'staff-schedules'] as const;

export function useStaffSchedules(includeArchived = false) {
  return useQuery<StaffSchedule[], ApiEnvelopeError>({
    queryKey: [...KEY, { includeArchived }],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(
        `/clinic/staff-schedules${includeArchived ? '?include_archived=1' : ''}`,
      );
      return z.array(staffScheduleSchema).parse(res.data);
    },
  });
}

export function useCreateStaffSchedule() {
  const qc = useQueryClient();
  return useMutation<StaffSchedule, ApiEnvelopeError, CreateStaffScheduleInput>({
    mutationFn: async (input) => {
      const valid = createStaffScheduleSchema.parse(input);
      const res = await apiClient.post<unknown>('/clinic/staff-schedules', valid);
      return staffScheduleSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: KEY });
      toast.success('Staff schedule added.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to add schedule.');
    },
  });
}

export function useArchiveStaffSchedule() {
  const qc = useQueryClient();
  return useMutation<void, ApiEnvelopeError, number>({
    mutationFn: async (id) => {
      await apiClient.post(`/clinic/staff-schedules/${id}/archive`, {});
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: KEY });
      toast.success('Staff schedule archived.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to archive.');
    },
  });
}

/** Restore an archived shift template back into the roster. */
export function useUnarchiveStaffSchedule() {
  const qc = useQueryClient();
  return useMutation<StaffSchedule, ApiEnvelopeError, number>({
    mutationFn: async (id) => {
      const res = await apiClient.post<unknown>(`/clinic/staff-schedules/${id}/unarchive`, {});
      return staffScheduleSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: KEY });
      toast.success('Staff schedule restored.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to restore.');
    },
  });
}
