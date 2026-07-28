/**
 * Scheduling hooks — counsellor availability + appointments (Phase 15).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  addSlotSchema,
  appointmentSchema,
  availabilitySchema,
  bookAppointmentSchema,
  slotAnalyticsSchema,
  type AddSlotInput,
  type Appointment,
  type AppointmentAction,
  type AppointmentStatus,
  type Availability,
  type BookAppointmentInput,
  type SlotAnalytics,
} from '@/schemas/schedule';

export function useAvailability() {
  return useQuery<Availability[], ApiEnvelopeError>({
    queryKey: ['schedule', 'availability'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/counselling/availability');
      return z.array(availabilitySchema).parse(res.data);
    },
  });
}

export function useAddSlot() {
  const qc = useQueryClient();
  return useMutation<{ id: number }, ApiEnvelopeError, AddSlotInput>({
    mutationFn: async (input) => {
      const valid = addSlotSchema.parse(input);
      const res = await apiClient.post<{ id: number }>('/counselling/availability', valid);
      return z.object({ id: z.number().int().positive() }).parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['schedule', 'availability'] });
      toast.success('Availability window added.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to add window.');
    },
  });
}

export function useRemoveSlot() {
  const qc = useQueryClient();
  return useMutation<void, ApiEnvelopeError, number>({
    mutationFn: async (id) => {
      await apiClient.post(`/counselling/availability/${id}/remove`, {});
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['schedule', 'availability'] });
      toast.success('Availability window removed.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to remove window.');
    },
  });
}

export function useAppointments(status: AppointmentStatus | null) {
  return useQuery<Appointment[], ApiEnvelopeError>({
    queryKey: ['schedule', 'appointments', { status }],
    queryFn: async () => {
      const params = new URLSearchParams();
      params.set('limit', '50');
      if (status !== null) params.set('status', status);
      const res = await apiClient.get<unknown[]>(`/counselling/appointments?${params.toString()}`);
      return z.array(appointmentSchema).parse(res.data);
    },
  });
}

export function useBookAppointment() {
  const qc = useQueryClient();
  return useMutation<Appointment, ApiEnvelopeError, BookAppointmentInput>({
    mutationFn: async (input) => {
      const valid = bookAppointmentSchema.parse(input);
      const payload = { ...valid, reason: valid.reason === '' ? undefined : valid.reason };
      const res = await apiClient.post<unknown>('/counselling/appointments', payload);
      return appointmentSchema.parse(res.data);
    },
    onSuccess: (a) => {
      void qc.invalidateQueries({ queryKey: ['schedule', 'appointments'] });
      toast.success(`Appointment #${a.id} booked for ${a.appointment_date}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Booking failed.');
    },
  });
}

export function useAppointmentTransition() {
  const qc = useQueryClient();
  return useMutation<
    Appointment,
    ApiEnvelopeError,
    { id: number; action: AppointmentAction; cancellation_reason?: string }
  >({
    mutationFn: async ({ id, action, cancellation_reason }) => {
      const res = await apiClient.post<unknown>(`/counselling/appointments/${id}/transition`, {
        action,
        ...(cancellation_reason !== undefined && cancellation_reason !== ''
          ? { cancellation_reason }
          : {}),
      });
      return appointmentSchema.parse(res.data);
    },
    onSuccess: (a) => {
      void qc.invalidateQueries({ queryKey: ['schedule', 'appointments'] });
      toast.success(`Appointment #${a.id} → ${a.status}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Transition failed.');
    },
  });
}

// Scheduling analytics (Phase P5a).

export function useSchedulingAnalytics() {
  return useQuery<SlotAnalytics[], ApiEnvelopeError>({
    queryKey: ['schedule', 'analytics'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/counselling/analytics');
      return z.array(slotAnalyticsSchema).parse(res.data);
    },
  });
}

export function useRecomputeAnalytics() {
  const qc = useQueryClient();
  return useMutation<{ recomputed: number }, ApiEnvelopeError, void>({
    mutationFn: async () => {
      const res = await apiClient.post<unknown>('/counselling/analytics/recompute', {});
      return z.object({ recomputed: z.number().int().nonnegative() }).parse(res.data);
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['schedule', 'analytics'] });
      toast.success(`Recomputed ${r.recomputed} slot${r.recomputed === 1 ? '' : 's'}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Recompute failed.');
    },
  });
}
