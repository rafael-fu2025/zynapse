/**
 * Notification hooks — self-scoped in-app notifications.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';

export const notificationSchema = z.object({
  id: z.number().int().positive(),
  template_code: z.string(),
  context: z.record(z.string(), z.unknown()).nullable(),
  read_at: z.string().nullable(),
  created_at: z.string(),
});
export type AppNotification = z.infer<typeof notificationSchema>;

export function useNotifications(limit = 10) {
  return useQuery<AppNotification[], ApiEnvelopeError>({
    queryKey: ['notifications', { limit }],
    queryFn: async () => {
      const res = await apiClient.get<{ data: unknown[] }>(`/notifications?limit=${limit}`);
      return z.array(notificationSchema).parse(res.data);
    },
    refetchInterval: 60_000,
  });
}

export function useMarkNotificationRead() {
  const qc = useQueryClient();
  return useMutation<unknown, ApiEnvelopeError, number>({
    mutationFn: async (id) => apiClient.post(`/notifications/${id}/read`),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['notifications'] });
    },
  });
}

export function useMarkAllNotificationsRead() {
  const qc = useQueryClient();
  return useMutation<unknown, ApiEnvelopeError, void>({
    mutationFn: async () => apiClient.post('/notifications/read-all'),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['notifications'] });
    },
  });
}
