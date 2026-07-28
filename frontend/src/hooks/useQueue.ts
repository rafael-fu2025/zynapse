/**
 * Queue hooks — walk-in queue (Phase 14).
 *
 * `usePublicQueueState` uses a RAW fetch (no auth, no interceptors):
 * the endpoint is public by design and the display board must work
 * on a logged-out lobby TV.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  publicQueueStateSchema,
  queueEntrySchema,
  type PublicQueueState,
  type QueueAction,
  type QueueEntry,
} from '@/schemas/queue';

const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api/v1';

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

export function useEnqueue() {
  const qc = useQueryClient();
  return useMutation<QueueEntry, ApiEnvelopeError, number>({
    mutationFn: async (encounterId) => {
      const res = await apiClient.post<unknown>('/clinic/queue', { encounter_id: encounterId });
      return queueEntrySchema.parse(res.data);
    },
    onSuccess: (e) => {
      void qc.invalidateQueries({ queryKey: ['queue'] });
      toast.success(`Queued at position ${e.position}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to queue encounter.');
    },
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
      toast.success(`Now serving #${e.position} — ${e.display_name}.`);
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
      toast.success(`#${e.position} → ${e.status}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Queue action failed.');
    },
  });
}

/** Public board — no auth, poll-refreshed for the lobby TV. */
export function usePublicQueueState() {
  return useQuery<PublicQueueState, Error>({
    queryKey: ['queue', 'public-state'],
    refetchInterval: 5_000,
    queryFn: async () => {
      const res = await fetch(`${API_BASE_URL}/clinic/queue/state`);
      if (!res.ok) {
        throw new Error(`Queue state unavailable (${res.status}).`);
      }
      const body = (await res.json()) as { data?: unknown };
      return publicQueueStateSchema.parse(body.data);
    },
  });
}
