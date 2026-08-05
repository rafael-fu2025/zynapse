/**
 * useMyQueue — self-scoped "Your queue" status for the student/employee
 * portal. Endpoint is permission-scoped to the caller's portal variant:
 *   employee → `/me/queue-status`
 *   student  → `/me/student-queue-status`
 *
 * Returns `null` when the caller has no active queue entry today (so the
 * portal card can hide itself). Polls every 10s to stay live while
 * waiting — a non-IT user should be able to open their phone and see
 * their place in line without a page refresh.
 */
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { hasPermission, useAuthStore } from '@/store/auth';

export const myQueueStatusSchema = z.object({
  queue_entry_id: z.number().int().positive(),
  encounter_id: z.number().int().positive(),
  position: z.number().int().positive(),
  queue_number: z.string(),
  status: z.enum(['waiting', 'called', 'in_session']),
  called_at: z.string().nullable(),
  started_at: z.string().nullable(),
  people_ahead: z.number().int(),
  estimated_wait_minutes: z.number().int().nullable(),
});
export type MyQueueStatus = z.infer<typeof myQueueStatusSchema>;

export function useMyQueueStatus(kind: 'employee' | 'student') {
  const authState = useAuthStore();
  const permission = kind === 'employee' ? 'employee.portal.read' : 'student.portal.read';
  const enabled = hasPermission(authState, permission);
  const endpoint = kind === 'employee' ? '/me/queue-status' : '/me/student-queue-status';

  return useQuery<MyQueueStatus | null, ApiEnvelopeError>({
    queryKey: ['me', 'queue-status', kind],
    enabled,
    queryFn: async () => {
      const res = await apiClient.get<unknown>(endpoint);
      if (res.data === null || res.data === undefined) return null;
      return myQueueStatusSchema.parse(res.data);
    },
    refetchInterval: 10_000,
    staleTime: 5_000,
    retry: false,
  });
}
