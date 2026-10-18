/**
 * Appointment hooks — list + schedule + show + update + transitions.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient, getNextCursor } from '@/api/client';
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

/**
 * Server-side book scopes (2026-09-25 staff meeting — the list is the
 * SCHEDULED order, never the booking order):
 *   - `upcoming` — at/after now, ascending (earliest upcoming first)
 *   - `past`     — before now, descending (most recent first)
 *   - `all`      — the whole book, ascending
 */
export type AppointmentScope = 'upcoming' | 'past' | 'all';

/** Approval-state filter: portal bookings start with no provider. */
export type AppointmentProviderFilter = 'any' | 'assigned' | 'unassigned';

export function useAppointments(
  cursor: string | null,
  limit = 25,
  status: Appointment['status'] | null = null,
  scope: AppointmentScope = 'all',
  provider: AppointmentProviderFilter = 'any',
  enabled = true,
) {
  return useQuery<AppointmentPage, ApiEnvelopeError>({
    // The status filter is part of the cache key so toggling it
    // triggers a fresh fetch (and a future "All" query does not
    // accidentally render the previously-filtered list). Scope and
    // provider participate too: each tab owns its own slice of the
    // book.
    queryKey: ['appointments', { cursor, limit, status, scope, provider }],
    enabled,
    // Appointments change server-side all day (staff transitions, the
    // kiosk station), so poll to keep statuses current without a
    // manual refresh.
    refetchInterval: 30_000,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      if (status !== null) params.set('status', status);
      params.set('scope', scope);
      params.set('provider', provider);
      const res = await apiClient.get<unknown[]>(`/clinic/appointments?${params.toString()}`);
      const data = z.array(appointmentSchema).parse(res.data);
      // The envelope interceptor unwraps `data` to the bare rows and
      // moves pagination meta to `synapseMeta` — read the cursor via
      // the shared helper (2026-09 audit: reading `res.data.next` here
      // always yielded null and pagination never advanced).
      return { data, next: getNextCursor(res) };
    },
  });
}

/**
 * The "Needs Action" tally — unapproved portal bookings (`scheduled`
 * with no provider), server-filtered. Rendered as the tab badge so an
 * approval waiting on the desk is visible from every tab; the badge
 * caps at 25 like the page size it counts.
 */
export function useNeedsActionAppointments(status: Appointment['status'] | null = null) {
  return useQuery<Appointment[], ApiEnvelopeError>({
    queryKey: ['appointments', { needsAction: true, status }],
    refetchInterval: 30_000,
    queryFn: async () => {
      const params = new URLSearchParams();
      params.set('limit', '25');
      params.set('scope', 'upcoming');
      params.set('provider', 'unassigned');
      if (status !== null) params.set('status', status);
      const res = await apiClient.get<unknown[]>(`/clinic/appointments?${params.toString()}`);
      return z.array(appointmentSchema).parse(res.data);
    },
  });
}

/**
 * Live free-text search over appointments — mirrors the Patients
 * page's `useStudentSearch`: a SEPARATE query from the paged list,
 * enabled only once the debounced term is >= 2 chars, returning a
 * keyset page within the selected scope/provider. Case-insensitivity comes from the DB
 * collation; the backend also matches formatted month/date/time
 * (e.g. `aug`, `August`, `08/05`, `2:00 pm`).
 */
export function useAppointmentSearch(
  q: string,
  status: Appointment['status'] | 'all' = 'all',
  cursor: string | null = null,
  scope: AppointmentScope = 'all',
  provider: AppointmentProviderFilter = 'any',
  limit = 25,
) {
  const search = q.trim();
  return useQuery<AppointmentPage, ApiEnvelopeError>({
    queryKey: ['appointments', 'search', { q: search, status, cursor, scope, provider, limit }],
    enabled: search.length >= 2,
    refetchInterval: 30_000,
    queryFn: async () => {
      const params = new URLSearchParams({ limit: String(limit), q: search, scope, provider });
      if (cursor !== null) params.set('cursor', cursor);
      if (status !== 'all') params.set('status', status);
      const res = await apiClient.get<unknown[]>(`/clinic/appointments?${params.toString()}`);
      return { data: z.array(appointmentSchema).parse(res.data), next: getNextCursor(res) };
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
