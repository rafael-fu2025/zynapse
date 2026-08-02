/**
 * Reorder hooks — procurement workflow (Phase 13).
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  createReorderSchema,
  reorderSchema,
  type CreateReorderInput,
  type Reorder,
  type ReorderAction,
} from '@/schemas/reorders';

interface ReorderPage {
  data: Reorder[];
  next: string | null;
}

export function useReorders(
  cursor: string | null,
  status: string | null,
  limit = 25,
  q: string | null = null,
) {
  return useQuery<ReorderPage, ApiEnvelopeError>({
    queryKey: ['reorders', { cursor, status, limit, q }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      if (status !== null) params.set('status', status);
      params.set('limit', String(limit));
      if (q !== null && q.trim() !== '') params.set('q', q.trim());
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/clinic/reorders?${params.toString()}`,
      );
      const data = z.array(reorderSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
    // Keep the previous page visible while the next one is in-flight so
    // typing in the search box doesn't flash an empty state between
    // keystrokes.
    placeholderData: keepPreviousData,
  });
}

/**
 * The `received` reorder request awaiting stock entry for one item, or
 * null. Drives the Receive dialogs: the delivered quantity is read
 * from here and the submit is disabled when there is nothing to
 * receive (backend enforces the same gate with a 409).
 */
export function useReceivableReorder(itemType: 'medicine' | 'supply', itemId: number | null) {
  return useQuery<Reorder | null, ApiEnvelopeError>({
    queryKey: ['reorders', 'receivable', itemType, itemId],
    enabled: itemId !== null && itemId > 0,
    queryFn: async () => {
      const params = new URLSearchParams({ status: 'received', limit: '1' });
      params.set(itemType === 'supply' ? 'supply_item_id' : 'medicine_id', String(itemId));
      const res = await apiClient.get<{ data: unknown[] }>(`/clinic/reorders?${params.toString()}`);
      const rows = z.array(reorderSchema).parse(res.data);
      return rows[0] ?? null;
    },
  });
}

export function useCreateReorder() {
  const qc = useQueryClient();
  return useMutation<Reorder, ApiEnvelopeError, CreateReorderInput>({
    mutationFn: async (input) => {
      const valid = createReorderSchema.parse(input);
      const res = await apiClient.post<unknown>('/clinic/reorders', valid);
      return reorderSchema.parse(res.data);
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['reorders'] });
      toast.success(`Reorder #${r.id} created (${r.urgency}).`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to create reorder.');
    },
  });
}

export function useReorderAutoCheck() {
  const qc = useQueryClient();
  return useMutation<Reorder[], ApiEnvelopeError, void>({
    mutationFn: async () => {
      const res = await apiClient.post<unknown[]>('/clinic/reorders/auto-check', {});
      return z.array(reorderSchema).parse(res.data);
    },
    onSuccess: (created) => {
      void qc.invalidateQueries({ queryKey: ['reorders'] });
      toast.success(
        created.length === 0
          ? 'Auto-check: no medicines below threshold.'
          : `Auto-check created ${created.length} request(s).`,
      );
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Auto-check failed.');
    },
  });
}

export function useReorderTransition() {
  const qc = useQueryClient();
  return useMutation<
    Reorder,
    ApiEnvelopeError,
    { id: number; action: ReorderAction; expected_delivery_date?: string }
  >({
    mutationFn: async ({ id, action, expected_delivery_date }) => {
      const body: Record<string, unknown> = { action };
      if (expected_delivery_date !== undefined) body['expected_delivery_date'] = expected_delivery_date;
      const res = await apiClient.post<unknown>(`/clinic/reorders/${id}/transition`, body);
      return reorderSchema.parse(res.data);
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['reorders'] });
      toast.success(`Reorder #${r.id} → ${r.status}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Transition failed.');
    },
  });
}
