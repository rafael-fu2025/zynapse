/**
 * Inventory hooks — items list + create + stock movements.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  createItemSchema,
  inventoryItemSchema,
  inventoryMovementSchema,
  moveStockSchema,
  updateItemSchema,
  type CreateItemInput,
  type InventoryItem,
  type InventoryMovement,
  type MoveStockInput,
  type UpdateItemInput,
} from '@/schemas/inventory';

interface ItemPage {
  data: InventoryItem[];
  next: string | null;
}

export function useInventoryItems(cursor: string | null, limit = 25, q: string | null = null, includeArchived = false) {
  return useQuery<ItemPage, ApiEnvelopeError>({
    queryKey: ['inventory', 'items', { cursor, limit, q, includeArchived }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      if (q !== null && q.trim() !== '') params.set('q', q.trim());
      if (includeArchived) params.set('include_archived', '1');
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/clinic/inventory?${params.toString()}`,
      );
      const data = z.array(inventoryItemSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
  });
}

export function useCreateItem() {
  const qc = useQueryClient();
  return useMutation<InventoryItem, ApiEnvelopeError, CreateItemInput>({
    mutationFn: async (input) => {
      const valid = createItemSchema.parse(input);
      const res = await apiClient.post<InventoryItem>('/clinic/inventory', valid);
      return inventoryItemSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['inventory'] });
      toast.success(`Item ${item.sku} created.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to create item.');
    },
  });
}

export function useMoveStock() {
  const qc = useQueryClient();
  return useMutation<InventoryItem, ApiEnvelopeError, { itemId: number; input: MoveStockInput }>({
    mutationFn: async ({ itemId, input }) => {
      const valid = moveStockSchema.parse(input);
      const res = await apiClient.post<InventoryItem>(`/clinic/inventory/${itemId}/move`, valid);
      return inventoryItemSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['inventory'] });
      void qc.invalidateQueries({ queryKey: ['inventory', 'movements', item.id] });
      toast.success(`${item.sku}: ${item.quantity_on_hand} ${item.unit} on hand.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Stock movement failed.');
    },
  });
}

/**
 * Supply ledger (panel revision): signed movements with the stored
 * running balance, oldest → newest. Powers the per-item ledger drawer.
 */
export function useInventoryMovements(itemId: number | null) {
  return useQuery<InventoryMovement[], ApiEnvelopeError>({
    queryKey: ['inventory', 'movements', itemId],
    enabled: itemId !== null && itemId > 0,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/clinic/inventory/${itemId}/movements`);
      return z.array(inventoryMovementSchema).parse(res.data);
    },
  });
}

/**
 * Receive the delivered quantity of a supply item's `received` reorder
 * request. The quantity comes from that request server-side; the
 * reorder is marked completed in the same transaction.
 */
export function useReceiveSupply() {
  const qc = useQueryClient();
  return useMutation<InventoryItem, ApiEnvelopeError, { itemId: number; note?: string }>({
    mutationFn: async ({ itemId, note }) => {
      const res = await apiClient.post<InventoryItem>(
        `/clinic/inventory/${itemId}/receive`,
        note !== undefined && note !== '' ? { note } : {},
      );
      return inventoryItemSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['inventory'] });
      void qc.invalidateQueries({ queryKey: ['inventory', 'movements', item.id] });
      void qc.invalidateQueries({ queryKey: ['reorders'] });
      toast.success(`${item.sku} received — ${item.quantity_on_hand} ${item.unit} on hand.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Receive failed.');
    },
  });
}

/**
 * Update a supply item's catalog row (name, unit, reorder_level).
 * SKU is immutable; stock is untouched.
 */
export function useUpdateItem() {
  const qc = useQueryClient();
  return useMutation<InventoryItem, ApiEnvelopeError, { itemId: number; input: UpdateItemInput }>({
    mutationFn: async ({ itemId, input }) => {
      const valid = updateItemSchema.parse(input);
      const res = await apiClient.post<InventoryItem>(`/clinic/inventory/${itemId}`, valid);
      return inventoryItemSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['inventory'] });
      toast.success(`${item.sku} updated.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Update failed.');
    },
  });
}

/**
 * Soft-archive a supply item. The row drops off the default list;
 * every movement row stays intact for the audit trail.
 */
export function useArchiveItem() {
  const qc = useQueryClient();
  return useMutation<InventoryItem, ApiEnvelopeError, number>({
    mutationFn: async (itemId) => {
      const res = await apiClient.post<InventoryItem>(`/clinic/inventory/${itemId}/archive`, {});
      return inventoryItemSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['inventory'] });
      toast.success(`${item.sku} archived.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Archive failed.');
    },
  });
}

/**
 * Restore a soft-archived supply item back onto the default list.
 * Mirrors `useArchiveItem` — the movement ledger was never touched.
 */
export function useUnarchiveItem() {
  const qc = useQueryClient();
  return useMutation<InventoryItem, ApiEnvelopeError, number>({
    mutationFn: async (itemId) => {
      const res = await apiClient.post<InventoryItem>(`/clinic/inventory/${itemId}/unarchive`, {});
      return inventoryItemSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['inventory'] });
      toast.success(`${item.sku} restored.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Restore failed.');
    },
  });
}
