/**
 * Appointment hooks — list + schedule + show + update + transitions.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  appointmentSchema,
  scheduleAppointmentSchema,
  type Appointment,
  type AppointmentTransition,
  type ScheduleAppointmentInput,
  type UpdateAppointmentInput,
} from '@/schemas/appointments';

interface AppointmentPage {
  data: Appointment[];
  next: string | null;
}

export function useAppointments(cursor: string | null, limit = 25, status: Appointment['status'] | null = null) {
  return useQuery<AppointmentPage, ApiEnvelopeError>({
    // The status filter is part of the cache key so toggling it
    // triggers a fresh fetch (and so a future "All" query does not
    // accidentally render the previously-filtered list).
    queryKey: ['appointments', { cursor, limit, status }],
    // Appointments are auto-checked-in server-side (the queue sweep +
    // the kiosk station), so poll to keep statuses current without a
    // manual refresh.
    refetchInterval: 30_000,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      if (status !== null) params.set('status', status);
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/clinic/appointments?${params.toString()}`,
      );
      const data = z.array(appointmentSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
  });
}

export function useScheduleAppointment() {
  const qc = useQueryClient();
  return useMutation<Appointment, ApiEnvelopeError, ScheduleAppointmentInput>({
    mutationFn: async (input) => {
      const valid = scheduleAppointmentSchema.parse(input);
      const res = await apiClient.post<Appointment>('/clinic/appointments', valid);
      return appointmentSchema.parse(res.data);
    },
    onSuccess: (a) => {
      void qc.invalidateQueries({ queryKey: ['appointments'] });
      toast.success(`Appointment #${a.id} scheduled.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to schedule appointment.');
    },
  });
}

export function useTransitionAppointment() {
  const qc = useQueryClient();
  return useMutation<Appointment, ApiEnvelopeError, { id: number; status: AppointmentTransition }>({
    mutationFn: async ({ id, status }) => {
      const res = await apiClient.post<Appointment>(`/clinic/appointments/${id}/transition`, { status });
      return appointmentSchema.parse(res.data);
    },
    onSuccess: (a) => {
      void qc.invalidateQueries({ queryKey: ['appointments'] });
      toast.success(`Appointment #${a.id} → ${a.status}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Transition failed.');
    },
  });
}

/**
 * Single appointment detail. The list page already has the row data,
 * but the detail dialog uses this hook so it can lazy-load on demand
 * and so a refresh of the list page does not refetch the open detail.
 */
export function useAppointment(id: number | null) {
  return useQuery<Appointment, ApiEnvelopeError>({
    queryKey: ['appointments', 'detail', id],
    enabled: id !== null && id > 0,
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/clinic/appointments/${id}`);
      return appointmentSchema.parse(res.data);
    },
  });
}

/**
 * Partial update — reschedule, change reason, or change provider.
 * Only allowed while the appointment is `Scheduled` (server-side
 * lock); once checked-in, the user must cancel + reschedule.
 */
export function useUpdateAppointment() {
  const qc = useQueryClient();
  return useMutation<Appointment, ApiEnvelopeError, { id: number; input: UpdateAppointmentInput }>({
    mutationFn: async ({ id, input }) => {
      // Strip undefined keys so the backend doesn't see explicit nulls
      // for fields the caller didn't touch.
      const payload: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(input)) {
        if (v !== undefined) payload[k] = v;
      }
      const res = await apiClient.post<unknown>(`/clinic/appointments/${id}`, payload);
      return appointmentSchema.parse(res.data);
    },
    onSuccess: (a) => {
      void qc.invalidateQueries({ queryKey: ['appointments'] });
      void qc.invalidateQueries({ queryKey: ['appointments', 'detail', a.id] });
      toast.success(`Appointment #${a.id} updated.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to update appointment.');
    },
  });
}
