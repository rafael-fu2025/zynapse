/**
 * Equipment hooks — catalog list/detail + unit status changes.
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  addEquipmentUnitsSchema,
  changeEquipmentUnitStatusSchema,
  createEquipmentSchema,
  equipmentDetailSchema,
  equipmentItemSchema,
  updateEquipmentSchema,
  type AddEquipmentUnitsInput,
  type ChangeEquipmentUnitStatusInput,
  type CreateEquipmentInput,
  type EquipmentDetail,
  type EquipmentItem,
  type UpdateEquipmentInput,
} from '@/schemas/equipment';

interface EquipmentPage {
  data: EquipmentItem[];
  next: string | null;
}

export function useEquipmentItems(
  cursor: string | null,
  limit = 25,
  q: string | null = null,
  includeArchived = false,
) {
  return useQuery<EquipmentPage, ApiEnvelopeError>({
    queryKey: ['equipment', 'items', { cursor, limit, q, includeArchived }],
    // Status counts are current state — poll so the chips stay honest
    // without a manual refresh (mirrors the supplies list).
    refetchInterval: 60_000,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      if (q !== null && q.trim() !== '') params.set('q', q.trim());
      if (includeArchived) params.set('include_archived', '1');
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/clinic/equipment?${params.toString()}`,
      );
      const data = z.array(equipmentItemSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
    placeholderData: keepPreviousData,
  });
}

export function useEquipmentDetail(equipmentId: number | null) {
  return useQuery<EquipmentDetail, ApiEnvelopeError>({
    queryKey: ['equipment', 'detail', equipmentId],
    enabled: equipmentId !== null && equipmentId > 0,
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/clinic/equipment/${equipmentId}`);
      return equipmentDetailSchema.parse(res.data);
    },
  });
}

export function useCreateEquipment() {
  const qc = useQueryClient();
  return useMutation<EquipmentDetail, ApiEnvelopeError, CreateEquipmentInput>({
    mutationFn: async (input) => {
      const valid = createEquipmentSchema.parse(input);
      const res = await apiClient.post<EquipmentDetail>('/clinic/equipment', valid);
      return equipmentDetailSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['equipment'] });
      toast.success(`Equipment ${item.name} created.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to create equipment.');
    },
  });
}

export function useUpdateEquipment() {
  const qc = useQueryClient();
  return useMutation<EquipmentDetail, ApiEnvelopeError, { equipmentId: number; input: UpdateEquipmentInput }>({
    mutationFn: async ({ equipmentId, input }) => {
      const valid = updateEquipmentSchema.parse(input);
      const res = await apiClient.post<EquipmentDetail>(`/clinic/equipment/${equipmentId}`, valid);
      return equipmentDetailSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['equipment'] });
      toast.success(`Equipment ${item.name} updated.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Update failed.');
    },
  });
}

export function useArchiveEquipment() {
  const qc = useQueryClient();
  return useMutation<EquipmentDetail, ApiEnvelopeError, number>({
    mutationFn: async (equipmentId) => {
      const res = await apiClient.post<EquipmentDetail>(`/clinic/equipment/${equipmentId}/archive`, {});
      return equipmentDetailSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['equipment'] });
      toast.success(`${item.name} archived.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Archive failed.');
    },
  });
}

export function useUnarchiveEquipment() {
  const qc = useQueryClient();
  return useMutation<EquipmentDetail, ApiEnvelopeError, number>({
    mutationFn: async (equipmentId) => {
      const res = await apiClient.post<EquipmentDetail>(`/clinic/equipment/${equipmentId}/unarchive`, {});
      return equipmentDetailSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['equipment'] });
      toast.success(`${item.name} restored.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Restore failed.');
    },
  });
}

export function useAddEquipmentUnits() {
  const qc = useQueryClient();
  return useMutation<EquipmentDetail, ApiEnvelopeError, { equipmentId: number; input: AddEquipmentUnitsInput }>({
    mutationFn: async ({ equipmentId, input }) => {
      const valid = addEquipmentUnitsSchema.parse(input);
      const res = await apiClient.post<EquipmentDetail>(`/clinic/equipment/${equipmentId}/units`, valid);
      return equipmentDetailSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['equipment'] });
      toast.success(`${item.units.length} unit(s) on ${item.name}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to add units.');
    },
  });
}

/**
 * The one-click status change — the load-bearing action. Every change
 * lands in the append-only status log server-side.
 */
export function useChangeEquipmentUnitStatus() {
  const qc = useQueryClient();
  return useMutation<EquipmentDetail, ApiEnvelopeError, { unitId: number; input: ChangeEquipmentUnitStatusInput }>({
    mutationFn: async ({ unitId, input }) => {
      const valid = changeEquipmentUnitStatusSchema.parse(input);
      const res = await apiClient.post<EquipmentDetail>(`/clinic/equipment/units/${unitId}/status`, valid);
      return equipmentDetailSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['equipment'] });
      toast.success(`${item.name}: status updated.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Status change failed.');
    },
  });
}

export function useUpdateEquipmentUnit() {
  const qc = useQueryClient();
  return useMutation<EquipmentDetail, ApiEnvelopeError, { unitId: number; conditionNote: string; acquiredDate: string }>({
    mutationFn: async ({ unitId, conditionNote, acquiredDate }) => {
      const payload: Record<string, unknown> = {};
      if (conditionNote !== '') payload['condition_note'] = conditionNote;
      if (acquiredDate !== '') payload['acquired_date'] = acquiredDate;
      const res = await apiClient.post<EquipmentDetail>(`/clinic/equipment/units/${unitId}`, payload);
      return equipmentDetailSchema.parse(res.data);
    },
    onSuccess: (item) => {
      void qc.invalidateQueries({ queryKey: ['equipment'] });
      toast.success(`${item.name}: unit updated.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Unit update failed.');
    },
  });
}
