/**
 * Check-in kiosk hooks (Phase 17).
 *
 * The legacy offline buffer moved client-side: failed-network scans are
 * queued in localStorage by the page and replayed via the same `useScan`
 * mutation with the original `scanned_at` (the backend's ±5-minute
 * duplicate window still applies on sync).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  checkinRowSchema,
  scanInputSchema,
  scanResultSchema,
  type CheckinRow,
  type ScanInput,
  type ScanResult,
} from '@/schemas/checkin';

export function useScan() {
  const qc = useQueryClient();
  return useMutation<ScanResult, ApiEnvelopeError, ScanInput>({
    mutationFn: async (input) => {
      const valid = scanInputSchema.parse(input);
      const payload: Record<string, unknown> = {
        method: valid.method,
      };
      if (valid.identifier !== undefined && valid.identifier !== '') payload['identifier'] = valid.identifier;
      if (valid.guest_name !== undefined && valid.guest_name !== '') payload['guest_name'] = valid.guest_name;
      if (valid.station_id !== undefined && valid.station_id !== '') payload['station_id'] = valid.station_id;
      if (valid.purpose !== undefined && valid.purpose !== '') payload['purpose'] = valid.purpose;
      if (valid.scanned_at !== undefined && valid.scanned_at !== '') payload['scanned_at'] = valid.scanned_at;
      const res = await apiClient.post<unknown>('/clinic/checkins', payload);
      return scanResultSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['checkins'] });
      void qc.invalidateQueries({ queryKey: ['queue'] });
      // A kiosk check-in can transition a clinic appointment to
      // checked_in and open its linked encounter — refresh those
      // caches too so staff views are never stale.
      void qc.invalidateQueries({ queryKey: ['appointments'] });
      void qc.invalidateQueries({ queryKey: ['clinic'] });
    },
    // Toasting is left to the page: outcomes (including duplicate) are
    // rendered on the kiosk result card, and network failures feed the
    // offline buffer instead of a generic error toast.
  });
}

export function useCheckinsToday() {
  return useQuery<CheckinRow[], ApiEnvelopeError>({
    queryKey: ['checkins', 'today'],
    refetchInterval: 15_000,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/clinic/checkins');
      return z.array(checkinRowSchema).parse(res.data);
    },
  });
}
