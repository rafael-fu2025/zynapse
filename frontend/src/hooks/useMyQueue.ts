import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { hasPermission, useAuthStore } from '@/store/auth';

export const myQueueStatusSchema = z.object({
  destination: z.enum(['clinic', 'counselling']),
  queue_entry_id: z.number().int().positive(), encounter_id: z.number().int().positive().nullable().optional(),
  session_id: z.number().int().positive().nullable().optional(), appointment_id: z.number().int().positive().nullable().optional(),
  position: z.number().int().positive(), queue_number: z.string(), status: z.enum(['waiting', 'called', 'in_session']),
  called_at: z.string().nullable(), started_at: z.string().nullable(), people_ahead: z.number().int(),
  estimated_wait_minutes: z.number().int().nullable(),
});
export type MyQueueStatus = z.infer<typeof myQueueStatusSchema>;

export function useMyQueueStatus(_kind: 'employee' | 'student') {
  const auth = useAuthStore();
  return useQuery<MyQueueStatus[], ApiEnvelopeError>({
    queryKey: ['me', 'queues'], enabled: hasPermission(auth, 'portal.queue.read'),
    queryFn: async () => z.object({ queues: z.array(myQueueStatusSchema) }).parse((await apiClient.get<unknown>('/me/queues')).data).queues,
    refetchInterval: 10_000, staleTime: 5_000, retry: false,
  });
}
