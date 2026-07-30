/**
 * Medicine hooks — batch-tracked inventory with FEFO dispensing (Phase 12).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  addBatchSchema,
  createMedicineSchema,
  dispenseSchema,
  medicineForecastSchema,
  medicineSchema,
  medicineTxnSchema,
  updateMedicineSchema,
  type AddBatchInput,
  type CreateMedicineInput,
  type DispenseInput,
  type Medicine,
  type MedicineForecast,
  type MedicineTxn,
  type UpdateMedicineInput,
} from '@/schemas/medicines';

interface MedicinePage {
  data: Medicine[];
  next: string | null;
}

export function useMedicines(cursor: string | null, limit = 25, q: string | null = null, includeArchived = false) {
  return useQuery<MedicinePage, ApiEnvelopeError>({
    queryKey: ['medicines', 'list', { cursor, limit, q, includeArchived }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      if (q !== null && q.trim() !== '') params.set('q', q.trim());
      if (includeArchived) params.set('include_archived', '1');
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/clinic/medicines?${params.toString()}`,
      );
      const data = z.array(medicineSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
  });
}

export function useMedicine(id: number | null) {
  return useQuery<Medicine, ApiEnvelopeError>({
    queryKey: ['medicines', 'detail', id],
    enabled: id !== null,
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/clinic/medicines/${id}`);
      return medicineSchema.parse(res.data);
    },
  });
}

export function useCreateMedicine() {
  const qc = useQueryClient();
  return useMutation<Medicine, ApiEnvelopeError, CreateMedicineInput>({
    mutationFn: async (input) => {
      const valid = createMedicineSchema.parse(input);
      const res = await apiClient.post<unknown>('/clinic/medicines', valid);
      return medicineSchema.parse(res.data);
    },
    onSuccess: (m) => {
      void qc.invalidateQueries({ queryKey: ['medicines'] });
      toast.success(`${m.generic_name} added to catalog.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to add medicine.');
    },
  });
}

export function useAddBatch() {
  const qc = useQueryClient();
  return useMutation<Medicine, ApiEnvelopeError, { medicineId: number; input: AddBatchInput }>({
    mutationFn: async ({ medicineId, input }) => {
      const valid = addBatchSchema.parse(input);
      const res = await apiClient.post<unknown>(`/clinic/medicines/${medicineId}/batches`, valid);
      return medicineSchema.parse(res.data);
    },
    onSuccess: (m) => {
      void qc.invalidateQueries({ queryKey: ['medicines'] });
      // Receiving a batch completes the medicine's `received` reorder.
      void qc.invalidateQueries({ queryKey: ['reorders'] });
      toast.success(`Batch received — ${m.quantity_on_hand} ${m.unit} on hand.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to receive batch.');
    },
  });
}

export function useDispense() {
  const qc = useQueryClient();
  return useMutation<Medicine, ApiEnvelopeError, { medicineId: number; input: DispenseInput }>({
    mutationFn: async ({ medicineId, input }) => {
      const valid = dispenseSchema.parse(input);
      const res = await apiClient.post<unknown>(`/clinic/medicines/${medicineId}/dispense`, valid);
      return medicineSchema.parse(res.data);
    },
    onSuccess: (m) => {
      void qc.invalidateQueries({ queryKey: ['medicines'] });
      void qc.invalidateQueries({ queryKey: ['medicines', 'transactions', m.id] });
      toast.success(`Dispensed (FEFO) — ${m.quantity_on_hand} ${m.unit} remaining.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Dispense failed.');
    },
  });
}

/**
 * Medicine ledger (panel revision): typed in/out transactions with the
 * stored running balance, oldest → newest. Powers the per-medicine
 * ledger drawer on the Inventory page.
 */
export function useMedicineTransactions(medicineId: number | null) {
  return useQuery<MedicineTxn[], ApiEnvelopeError>({
    queryKey: ['medicines', 'transactions', medicineId],
    enabled: medicineId !== null && medicineId > 0,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/clinic/medicines/${medicineId}/transactions`);
      return z.array(medicineTxnSchema).parse(res.data);
    },
  });
}

/**
 * Update a medicine's catalog row. generic_name and unit are not
 * editable here; stock/batches are unaffected. Invalidates both
 * list and detail queries so every open dialog refreshes.
 */
export function useUpdateMedicine() {
  const qc = useQueryClient();
  return useMutation<Medicine, ApiEnvelopeError, { medicineId: number; input: UpdateMedicineInput }>({
    mutationFn: async ({ medicineId, input }) => {
      const valid = updateMedicineSchema.parse(input);
      const res = await apiClient.post<unknown>(`/clinic/medicines/${medicineId}`, valid);
      return medicineSchema.parse(res.data);
    },
    onSuccess: (m) => {
      void qc.invalidateQueries({ queryKey: ['medicines'] });
      toast.success(`${m.generic_name} updated.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Update failed.');
    },
  });
}

/**
 * Soft-archive a medicine. The row drops off the default list;
 * batches & movement history remain in the database.
 */
export function useArchiveMedicine() {
  const qc = useQueryClient();
  return useMutation<Medicine, ApiEnvelopeError, number>({
    mutationFn: async (medicineId) => {
      const res = await apiClient.post<unknown>(`/clinic/medicines/${medicineId}/archive`, {});
      return medicineSchema.parse(res.data);
    },
    onSuccess: (m) => {
      void qc.invalidateQueries({ queryKey: ['medicines'] });
      toast.success(`${m.generic_name} archived.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Archive failed.');
    },
  });
}

/**
 * Restore a soft-archived medicine back onto the default list.
 * Batches & the transaction ledger were never touched, so stock
 * resumes exactly where it left off.
 */
export function useUnarchiveMedicine() {
  const qc = useQueryClient();
  return useMutation<Medicine, ApiEnvelopeError, number>({
    mutationFn: async (medicineId) => {
      const res = await apiClient.post<unknown>(`/clinic/medicines/${medicineId}/unarchive`, {});
      return medicineSchema.parse(res.data);
    },
    onSuccess: (m) => {
      void qc.invalidateQueries({ queryKey: ['medicines'] });
      toast.success(`${m.generic_name} restored.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Restore failed.');
    },
  });
}

export function useComputeForecast() {
  const qc = useQueryClient();
  return useMutation<MedicineForecast, ApiEnvelopeError, number>({
    mutationFn: async (medicineId) => {
      const res = await apiClient.post<unknown>(`/clinic/medicines/${medicineId}/forecast`, {});
      return medicineForecastSchema.parse(res.data);
    },
    onSuccess: (f) => {
      void qc.invalidateQueries({ queryKey: ['medicines', 'forecast', f.medicine_id] });
      toast.success(`Forecast: ~${f.predicted_daily_usage}/day, stockout ${f.predicted_stockout_date ?? 'n/a'}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Forecast failed.');
    },
  });
}

export function useMedicineForecast(medicineId: number | null) {
  return useQuery<MedicineForecast | null, ApiEnvelopeError>({
    queryKey: ['medicines', 'forecast', medicineId],
    queryFn: async () => {
      const res = await apiClient.get<{ forecast: unknown }>(`/clinic/medicines/${medicineId}/forecast`);
      return res.data.forecast === null ? null : medicineForecastSchema.parse(res.data.forecast);
    },
    enabled: medicineId !== null && medicineId > 0,
  });
}
