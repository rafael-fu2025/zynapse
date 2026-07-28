/**
 * Admin user hooks — list/create/status/reset (rbac.manage).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';

export const adminUserSchema = z.object({
  id: z.number().int().positive(),
  username: z.string().nullable(),
  email: z.string().nullable(),
  active: z.boolean(),
  status: z.string(),
  groups: z.array(z.string()),
  created_at: z.string(),
});
export type AdminUser = z.infer<typeof adminUserSchema>;

export const createUserSchema = z.object({
  email: z.string().email().max(255),
  password: z.string().min(12).max(256),
  username: z.string().max(64).regex(/^[A-Za-z0-9_-]*$/, 'Letters, digits, - and _ only').optional(),
  groups: z.array(z.string()).default([]),
});
export type CreateUserInput = z.infer<typeof createUserSchema>;

export function useAdminUsers(cursor: string | null, limit = 25) {
  return useQuery<{ data: AdminUser[]; next: string | null }, ApiEnvelopeError>({
    queryKey: ['admin', 'users', { cursor, limit }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/admin/users?${params.toString()}`,
      );
      return { data: z.array(adminUserSchema).parse(res.data), next: res.data?.next ?? null };
    },
  });
}

export function useCreateUser() {
  const qc = useQueryClient();
  return useMutation<unknown, ApiEnvelopeError, CreateUserInput>({
    mutationFn: async (input) => apiClient.post('/admin/users', createUserSchema.parse(input)),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin'] });
      toast.success('User created.');
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to create user.'),
  });
}

export function useSetUserActive() {
  const qc = useQueryClient();
  return useMutation<unknown, ApiEnvelopeError, { id: number; active: boolean }>({
    mutationFn: async ({ id, active }) => apiClient.post(`/admin/users/${id}/status`, { active }),
    onSuccess: (_d, vars) => {
      void qc.invalidateQueries({ queryKey: ['admin'] });
      toast.success(vars.active ? 'User activated.' : 'User deactivated.');
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Status change failed.'),
  });
}

interface ResetOut {
  id: number;
  temporary_password: string;
  force_reset: boolean;
}

export function useResetUserPassword() {
  return useMutation<ResetOut, ApiEnvelopeError, number>({
    mutationFn: async (id) => {
      const res = await apiClient.post<ResetOut>(`/admin/users/${id}/reset-password`);
      return res.data;
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Reset failed.'),
  });
}
