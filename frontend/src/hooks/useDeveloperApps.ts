/**
 * useDeveloperApps — TanStack Query hooks for the developer portal
 * (2026-09, D7). Superadmin surface: apps + keys + sandbox explorer.
 *
 * Toasts live in the hooks per repo convention; the pages only render.
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient, getNextCursor } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import type {
  DeveloperApp,
  DeveloperKey,
  KeyEnv,
  SandboxExecuteInput,
  SandboxExecuteResponse,
} from '@/schemas/developerApps';
import { sandboxExecuteResponseSchema } from '@/schemas/developerApps';

const appsListSchema = z.array(
  z.object({
    id: z.number().int().positive(),
    name: z.string(),
    description: z.string().nullable(),
    owner_contact: z.string().nullable(),
    status: z.enum(['active', 'suspended']),
    dpa_acknowledged_at: z.string().nullable(),
    key_count: z.number().int().nonnegative(),
    created_at: z.string(),
  }),
);

const keysListSchema = z.array(
  z.object({
    id: z.number().int().positive(),
    app_id: z.number().int().positive(),
    env: z.enum(['test', 'live']),
    prefix: z.string(),
    last4: z.string(),
    scopes: z.array(z.string()),
    rate_limit_per_min: z.number().int().positive(),
    expires_at: z.string().nullable(),
    revoked_at: z.string().nullable(),
    last_used_at: z.string().nullable(),
    created_at: z.string(),
    status: z.enum(['active', 'revoked', 'expired']),
  }),
);

export interface IssueKeyInput {
  appId: number;
  env: KeyEnv;
  scopes: string[];
  rateLimitPerMin?: number | null;
  dpaAcknowledged?: boolean;
}

export function useDeveloperApps() {
  return useQuery<DeveloperApp[], ApiEnvelopeError>({
    queryKey: ['developer', 'apps'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/developer/apps');
      return appsListSchema.parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useDeveloperKeys(appId: number | null) {
  return useQuery<DeveloperKey[], ApiEnvelopeError>({
    queryKey: ['developer', 'apps', appId, 'keys'],
    enabled: appId !== null,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/developer/apps/${appId}/keys`);
      return keysListSchema.parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useCreateDeveloperApp() {
  const qc = useQueryClient();
  return useMutation<{ id: number; name: string }, ApiEnvelopeError, { name: string; description?: string; ownerContact?: string }>({
    mutationFn: async (input) => {
      const res = await apiClient.post<{ id: number; name: string }>('/developer/apps', {
        name: input.name,
        description: input.description ?? null,
        owner_contact: input.ownerContact ?? null,
      });
      return res.data;
    },
    onSuccess: (app) => {
      void qc.invalidateQueries({ queryKey: ['developer', 'apps'] });
      toast.success(`App "${app.name}" registered.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to register the app.'),
  });
}

export function useSetAppStatus() {
  const qc = useQueryClient();
  return useMutation<{ id: number; status: 'active' | 'suspended' }, ApiEnvelopeError, { id: number; status: 'active' | 'suspended' }>({
    mutationFn: async ({ id, status }) => {
      const res = await apiClient.post<{ id: number; status: 'active' | 'suspended' }>(`/developer/apps/${id}/status`, { status });
      return res.data;
    },
    onSuccess: (result) => {
      void qc.invalidateQueries({ queryKey: ['developer', 'apps'] });
      void qc.invalidateQueries({ queryKey: ['developer', 'apps', result.id, 'keys'] });
      toast.success(result.status === 'suspended' ? 'App suspended — its keys stop working immediately.' : 'App reactivated.');
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to update the app status.'),
  });
}

export function useIssueDeveloperKey() {
  const qc = useQueryClient();
  return useMutation<{ key: DeveloperKey; secret: string }, ApiEnvelopeError, IssueKeyInput>({
    mutationFn: async (input) => {
      const res = await apiClient.post<{ key: DeveloperKey; secret: string }>(`/developer/apps/${input.appId}/keys`, {
        env: input.env,
        scopes: input.scopes,
        rate_limit_per_min: input.rateLimitPerMin ?? null,
        dpa_acknowledged: input.dpaAcknowledged ?? false,
      });
      return res.data;
    },
    onSuccess: (result) => {
      void qc.invalidateQueries({ queryKey: ['developer', 'apps'] });
      void qc.invalidateQueries({ queryKey: ['developer', 'apps', result.key.app_id, 'keys'] });
      toast.success(`Key ${result.key.prefix} issued — copy the secret now, it is shown only once.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to issue the key.'),
  });
}

export function useRevokeDeveloperKey() {
  const qc = useQueryClient();
  return useMutation<{ id: number; prefix: string }, ApiEnvelopeError, { id: number; prefix: string; reason?: string }>({
    mutationFn: async ({ id, reason }) => {
      const res = await apiClient.post<{ id: number; prefix: string; revoked: boolean }>(`/developer/keys/${id}/revoke`, {
        reason: reason ?? null,
      });
      return { id: res.data.id, prefix: res.data.prefix };
    },
    onSuccess: (result) => {
      void qc.invalidateQueries({ queryKey: ['developer'] });
      toast.success(`Key ${result.prefix} revoked — requests fail immediately.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to revoke the key.'),
  });
}

export function useSandboxExecute() {
  return useMutation<SandboxExecuteResponse, ApiEnvelopeError, SandboxExecuteInput>({
    mutationFn: async (input) => {
      const res = await apiClient.post<unknown>('/developer/sandbox/execute', input);
      return sandboxExecuteResponseSchema.parse(res.data);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'The sandbox request failed.'),
  });
}

/** Re-export for pages that want the cursor helper next to the hooks. */
export { getNextCursor };
