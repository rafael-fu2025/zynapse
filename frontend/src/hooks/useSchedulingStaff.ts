import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import { ApiEnvelopeError } from '@/api/envelope';
import {
  schedulingBatchResultSchema,
  schedulingEnrollmentSchema,
  schedulingProviderSchema,
  schedulingStaffPageSchema,
  schedulingTemplateSchema,
  type SchedulingTemplateInput,
  type SchedulingUnit,
} from '@/schemas/schedulingStaff';

export function schedulingStaffError(error: unknown): string {
  if (error instanceof ApiEnvelopeError) return error.errors.map((entry) => entry.message).join(' ');
  if (error instanceof z.ZodError) return 'The scheduling response or input is invalid. Refresh and contact an administrator if this continues.';
  return error instanceof Error ? error.message : 'Scheduling request failed. Please retry.';
}

export function useSchedulingStaff(unit: SchedulingUnit, q: string, cursor: string, enabled = true) {
  return useQuery({
    queryKey: ['scheduling-staff', unit, { q, cursor }],
    enabled,
    queryFn: async () => {
      const params = new URLSearchParams({ q, limit: '25' });
      if (cursor) params.set('cursor', cursor);
      return schedulingStaffPageSchema.parse((await apiClient.get(`/scheduling/staff/${unit}?${params}`)).data);
    },
  });
}

export function useSchedulingProviders(unit: SchedulingUnit, enabled = true) {
  return useQuery({
    queryKey: ['scheduling-providers', unit],
    enabled,
    queryFn: async () => z.array(schedulingProviderSchema).parse((await apiClient.get(`/scheduling/staff/${unit}/providers`)).data),
  });
}

/** Shared by enrollment and existing schedule editors so directory status stays current. */
export function useInvalidateSchedulingStaff() {
  const qc = useQueryClient();
  return async () => {
    await Promise.all([
      qc.invalidateQueries({ queryKey: ['scheduling-staff'] }),
      qc.invalidateQueries({ queryKey: ['scheduling-providers'] }),
      qc.invalidateQueries({ queryKey: ['clinic', 'staff-schedules'] }),
      qc.invalidateQueries({ queryKey: ['schedule', 'availability'] }),
      qc.invalidateQueries({ queryKey: ['schedule', 'counsellors'] }),
    ]);
  };
}

export function useEnrollSchedulingStaff(unit: SchedulingUnit) {
  const invalidate = useInvalidateSchedulingStaff();
  return useMutation({
    mutationFn: async (userIds: number[]) => schedulingBatchResultSchema.parse((await apiClient.post(
      `/scheduling/staff/${unit}/enroll`, schedulingEnrollmentSchema.parse({ user_ids: userIds }),
    )).data),
    onSuccess: (result) => toast.success(`Enrollment: ${result.created} created, ${result.skipped} skipped.`),
    onError: (error) => toast.error(schedulingStaffError(error)),
    onSettled: invalidate,
  });
}

export function useSchedulingStaffStatus(unit: SchedulingUnit) {
  const invalidate = useInvalidateSchedulingStaff();
  return useMutation({
    mutationFn: async ({ assignmentId, active }: { assignmentId: number; active: boolean }) => {
      await apiClient.post(`/scheduling/staff/${unit}/assignments/${assignmentId}/status`, { active });
    },
    onSuccess: () => toast.success('Scheduling enrollment updated. Existing schedules and appointments are retained.'),
    onError: (error) => toast.error(schedulingStaffError(error)),
    onSettled: invalidate,
  });
}

export function useSchedulingTemplate(unit: SchedulingUnit) {
  const invalidate = useInvalidateSchedulingStaff();
  return useMutation({
    mutationFn: async (input: SchedulingTemplateInput) => {
      const valid = schedulingTemplateSchema.parse(input);
      if (unit === 'guidance' && valid.schedule_type && valid.schedule_type !== 'regular') {
        throw new Error('Guidance supports regular availability windows only.');
      }
      return schedulingBatchResultSchema.parse((await apiClient.post(`/scheduling/staff/${unit}/template`, valid)).data);
    },
    onSuccess: (result) => toast.success(`Schedule windows: ${result.created} created, ${result.skipped} existing staff/day combinations preserved.`),
    onError: (error) => toast.error(schedulingStaffError(error)),
    onSettled: invalidate,
  });
}
