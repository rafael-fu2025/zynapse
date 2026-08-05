/**
 * Notification hooks — self-scoped in-app notifications.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { hasPermission, useAuthStore } from '@/store/auth';

export const notificationSchema = z.object({
  id: z.number().int().positive(),
  template_code: z.string(),
  context: z.record(z.string(), z.unknown()).nullable(),
  read_at: z.string().nullable(),
  created_at: z.string(),
});
export type AppNotification = z.infer<typeof notificationSchema>;

export function useNotifications(limit = 10) {
  // The endpoint is gated by `notifications.read`. When the caller
  // lacks that permission the server returns 403 — the bell would
  // otherwise poll every 60 s and fill the console with errors for
  // accounts (e.g. a kiosk service account) that have no business
  // seeing in-app notifications.
  const authState = useAuthStore();
  const enabled = hasPermission(authState, 'notifications.read');

  return useQuery<AppNotification[], ApiEnvelopeError>({
    queryKey: ['notifications', { limit }],
    queryFn: async () => {
      const res = await apiClient.get<{ data: unknown[] }>(`/notifications?limit=${limit}`);
      return z.array(notificationSchema).parse(res.data);
    },
    enabled,
    refetchInterval: 60_000,
  });
}

interface NotificationPage {
  data: AppNotification[];
  next: string | null;
}

/** Full-history variant with keyset pagination for the Notifications page. */
export function useNotificationsPage(cursor: string | null, limit = 25) {
  const authState = useAuthStore();
  const enabled = hasPermission(authState, 'notifications.read');

  return useQuery<NotificationPage, ApiEnvelopeError>({
    queryKey: ['notifications', 'page', { cursor, limit }],
    enabled,
    refetchInterval: 30_000,
    queryFn: async () => {
      const params = new URLSearchParams({ limit: String(limit) });
      if (cursor !== null) params.set('cursor', cursor);
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/notifications?${params.toString()}`,
      );
      return {
        data: z.array(notificationSchema).parse(res.data),
        next: res.data?.next ?? null,
      };
    },
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
