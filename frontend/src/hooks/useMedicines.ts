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
  type AddBatchInput,
  type CreateMedicineInput,
  type DispenseInput,
  type Medicine,
  type MedicineForecast,
} from '@/schemas/medicines';

interface MedicinePage {
  data: Medicine[];
  next: string | null;
}

export function useMedicines(cursor: string | null, limit = 25) {
  return useQuery<MedicinePage, ApiEnvelopeError>({
    queryKey: ['medicines', 'list', { cursor, limit }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
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
      toast.success(`Dispensed (FEFO) — ${m.quantity_on_hand} ${m.unit} remaining.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Dispense failed.');
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
