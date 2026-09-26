/**
 * Queue hooks — appointment-fed staff queue (Phase 14).
 *
 * Panel revision (August 2026): manual enqueue is gone. Today's
 * scheduled appointments auto-queue themselves when checked in, and
 * walk-in encounters are queued atomically inside `useCreateEncounter`.
 * The `useEnqueue()` mutation was removed; the `POST /clinic/queue`
 * endpoint no longer exists.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  guidanceQueueEntrySchema,
  queueEntrySchema,
  type QueueAction,
  type QueueEntry,
  type GuidanceQueueEntry,
} from '@/schemas/queue';

export function useQueueToday() {
  return useQuery<QueueEntry[], ApiEnvelopeError>({
    queryKey: ['queue', 'today'],
    refetchInterval: 10_000,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/clinic/queue');
      return z.array(queueEntrySchema).parse(res.data);
    },
  });
}

export function useGuidanceQueueToday(enabled = true) {
  return useQuery<GuidanceQueueEntry[], ApiEnvelopeError>({
    queryKey: ['counselling-queue', 'today'],
    refetchInterval: 10_000,
    enabled,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/counselling/queue');
      return z.array(guidanceQueueEntrySchema).parse(res.data);
    },
  });
}

export function useGuidanceCallNext() {
  const qc = useQueryClient();
  return useMutation<GuidanceQueueEntry, ApiEnvelopeError, void>({
    mutationFn: async () => guidanceQueueEntrySchema.parse(
      (await apiClient.post<unknown>('/counselling/queue/call-next', {})).data,
    ),
    onSuccess: (entry) => {
      void qc.invalidateQueries({ queryKey: ['counselling-queue'] });
      void qc.invalidateQueries({ queryKey: ['counselling'] });
      toast.success(`Now serving ${entry.queue_number ?? `G-${String(entry.position).padStart(3, '0')}`} — ${entry.display_name}.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Call next failed.'),
  });
}

export function useGuidanceQueueTransition() {
  const qc = useQueryClient();
  return useMutation<GuidanceQueueEntry, ApiEnvelopeError, { id: number; action: QueueAction }>({
    mutationFn: async ({ id, action }) => guidanceQueueEntrySchema.parse(
      (await apiClient.post<unknown>(`/counselling/queue/${id}/transition`, { action })).data,
    ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['counselling-queue'] });
      void qc.invalidateQueries({ queryKey: ['counselling'] });
      void qc.invalidateQueries({ queryKey: ['schedule', 'appointments'] });
      void qc.invalidateQueries({ queryKey: ['schedule', 'analytics'] });
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Queue action failed.'),
  });
}

export function useGuidanceRepairSession() {
  const qc = useQueryClient();
  return useMutation<GuidanceQueueEntry, ApiEnvelopeError, number>({
    mutationFn: async (id) => guidanceQueueEntrySchema.parse(
      (await apiClient.post<unknown>(`/counselling/queue/${id}/repair-session`, {})).data,
    ),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['counselling-queue'] });
      void qc.invalidateQueries({ queryKey: ['counselling'] });
    },
    onError: (error) => toast.error(error.errors[0]?.message ?? 'Could not repair the session link.'),
  });
}

export function useCallNext() {
  const qc = useQueryClient();
  return useMutation<QueueEntry, ApiEnvelopeError, void>({
    mutationFn: async () => {
      const res = await apiClient.post<unknown>('/clinic/queue/call-next', {});
      return queueEntrySchema.parse(res.data);
    },
    onSuccess: (e) => {
      void qc.invalidateQueries({ queryKey: ['queue'] });
      // Calling next flips the linked encounter into session — keep the
      // clinic + appointments views in step without a manual refresh.
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      void qc.invalidateQueries({ queryKey: ['appointments'] });
      toast.success(`Now serving ${e.queue_number ?? `C-${String(e.position).padStart(3, '0')}`} — ${e.display_name}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Call next failed.');
    },
  });
}

export function useQueueTransition() {
  const qc = useQueryClient();
  return useMutation<QueueEntry, ApiEnvelopeError, { id: number; action: QueueAction }>({
    mutationFn: async ({ id, action }) => {
      const res = await apiClient.post<unknown>(`/clinic/queue/${id}/transition`, { action });
      return queueEntrySchema.parse(res.data);
    },
    onSuccess: (e) => {
      void qc.invalidateQueries({ queryKey: ['queue'] });
      // Queue transitions (called / finished / cancelled) mutate the
      // linked encounter + appointment — refresh those views too.
      void qc.invalidateQueries({ queryKey: ['clinic'] });
      void qc.invalidateQueries({ queryKey: ['appointments'] });
      toast.success(`${e.queue_number ?? `C-${String(e.position).padStart(3, '0')}`} → ${e.status}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Queue action failed.');
    },
  });
}
