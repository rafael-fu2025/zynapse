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
  moveStockSchema,
  type CreateItemInput,
  type InventoryItem,
  type MoveStockInput,
} from '@/schemas/inventory';

interface ItemPage {
  data: InventoryItem[];
  next: string | null;
}

export function useInventoryItems(cursor: string | null, limit = 25) {
  return useQuery<ItemPage, ApiEnvelopeError>({
    queryKey: ['inventory', 'items', { cursor, limit }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
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
      toast.success(`${item.sku}: ${item.quantity_on_hand} ${item.unit} on hand.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Stock movement failed.');
    },
  });
}
