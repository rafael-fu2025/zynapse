/**
 * usePatientLookup — kiosk station autocomplete.
 *
 * Combined student + employee lookup by number or name (backend
 * `GET /clinic/patients/lookup`). Returns a minimal dropdown shape:
 * { id, kind, name, school_id }.
 */
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';

export const kioskLookupSchema = z.array(
  z.object({
    id: z.number().int().positive(),
    kind: z.enum(['student', 'employee']),
    name: z.string(),
    school_id: z.string(),
  }),
);
export type KioskLookupResult = z.infer<typeof kioskLookupSchema>[number];

export function usePatientLookup(query: string) {
  const q = query.trim();
  const enabled = q.length >= 2;
  return useQuery<KioskLookupResult[], ApiEnvelopeError>({
    queryKey: ['kiosk-lookup', q],
    enabled,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(
        `/clinic/patients/lookup?q=${encodeURIComponent(q)}&limit=8`,
      );
      return kioskLookupSchema.parse(res.data);
    },
    staleTime: 30_000,
  });
}
