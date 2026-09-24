/**
 * Scheduling hooks — counsellor availability + appointments (Phase 15).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient, getNextCursor } from '@/api/client';
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
  type AppointmentScope,
  type AppointmentStatus,
  type AppointmentType,
  type Availability,
  type BookAppointmentInput,
  type SlotAnalytics,
} from '@/schemas/schedule';
import { fmtHumanDate } from '@/utils/date';

/**
 * Availability windows. `enabled` lets the Counselling page hold the request
 * back for viewers who cannot read the schedule — the page uses the result
 * only to decide whether Scheduling needs a "not configured" dot.
 */
export function useAvailability({ enabled = true }: { enabled?: boolean } = {}) {
  return useQuery<Availability[], ApiEnvelopeError>({
    queryKey: ['schedule', 'availability'],
    enabled,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/counselling/availability');
      return z.array(availabilitySchema).parse(res.data);
    },
  });
}

export function useCounsellors() {
  return useQuery({ queryKey: ['schedule','counsellors'], queryFn: async () => z.array(z.object({ id:z.number(),name:z.string() })).parse((await apiClient.get('/counselling/counsellors')).data) });
}

/**
 * Add availability windows. The payload carries a **set** of weekdays, so one
 * submit can create the whole working week; the toast reports how many landed
 * rather than claiming a single window.
 */
export function useAddSlot() {
  const qc = useQueryClient();
  return useMutation<{ id: number; ids: number[] }, ApiEnvelopeError, AddSlotInput>({
    mutationFn: async (input) => {
      const valid = addSlotSchema.parse(input);
      const res = await apiClient.post<{ id: number; ids?: number[] }>('/counselling/availability', valid);
      const parsed = z
        .object({ id: z.number().int().positive(), ids: z.array(z.number().int().positive()).optional() })
        .parse(res.data);
      return { id: parsed.id, ids: parsed.ids ?? [parsed.id] };
    },
    onSuccess: (result) => {
      void qc.invalidateQueries({ queryKey: ['schedule', 'availability'] });
      const n = result.ids.length;
      toast.success(n === 1 ? 'Availability window added.' : `${n} availability windows added.`);
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

export interface AppointmentQuery {
  status?: AppointmentStatus | null;
  date?: string | null;
  cursor?: string | null;
  /** Calendar bucket — resolved server-side against the Manila business day. */
  scope?: AppointmentScope | null;
  type?: AppointmentType | null;
  limit?: number;
  /** Gate the request (e.g. a badge query for a viewer without schedule read). */
  enabled?: boolean;
}

/**
 * Appointments list. `scope` is the Queue board's bucket and `type` narrows to
 * one counselling type (the Follow-ups tab reads `follow_up`); both are
 * resolved server-side so day boundaries stay on the Manila helper.
 */
export function useAppointments(query: AppointmentQuery = {}) {
  const {
    status = null,
    date = null,
    cursor = null,
    scope = null,
    type = null,
    limit = 25,
    enabled = true,
  } = query;

  return useQuery<{ data: Appointment[]; next: string | null }, ApiEnvelopeError>({
    queryKey: ['schedule', 'appointments', { status, date, cursor, scope, type, limit }],
    enabled,
    queryFn: async () => {
      const params = new URLSearchParams();
      params.set('limit', String(limit));
      if (status !== null) params.set('status', status);
      if (date !== null && date !== '') params.set('date', date);
      if (cursor !== null && cursor !== '') params.set('cursor', cursor);
      if (scope !== null) params.set('scope', scope);
      if (type !== null) params.set('type', type);
      const res = await apiClient.get<unknown[]>(`/counselling/appointments?${params.toString()}`);
      return {
        data: z.array(appointmentSchema).parse(res.data),
        next: getNextCursor(res),
      };
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
      toast.success(`Appointment #${a.id} booked for ${fmtHumanDate(a.appointment_date)}.`);
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
