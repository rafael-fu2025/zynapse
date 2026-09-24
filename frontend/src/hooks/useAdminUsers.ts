/**
 * Identity-consolidated: adminUserSchema exposes person_kind + person_name
 * read straight from `users` (no person_id link).
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient, getNextCursor } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';

export const adminUserSchema = z.object({
  id: z.number().int(),
  username: z.string().nullable(),
  email: z.string().nullable(),
  active: z.boolean(),
  status: z.string(),
  groups: z.array(z.string()),
  // Identity-consolidated fields.
  person_kind: z.enum(['student', 'employee', 'contractor', 'alumni']).nullable(),
  person_name: z.string().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
  last_active: z.string().nullable(),
  force_reset: z.boolean(),
  is_directory_record: z.boolean().optional(),
  directory_identifier: z.string().optional(),
});
export type AdminUser = z.infer<typeof adminUserSchema>;

export const createUserSchema = z.object({
  email: z.string().email().max(255),
  username: z.string().max(64).regex(/^[A-Za-z0-9_-]*$/, 'Letters, digits, - and _ only').optional(),
  groups: z.array(z.string()).min(1, 'Select at least one role.'),
});
export type CreateUserInput = z.infer<typeof createUserSchema>;

export const adminRoleSchema = z.object({
  code: z.string(),
  name: z.string(),
  permissions: z.array(z.string()),
  // 2026-09 RBAC rework: privileged set P (grant/revoke needs
  // rbac.privileged.manage) and the sole wildcard holder flag.
  privileged: z.boolean().default(false),
  wildcard: z.boolean().default(false),
});
export type AdminRole = z.infer<typeof adminRoleSchema>;

const rolesResponseSchema = z.object({ roles: z.array(adminRoleSchema) });
const createUserResponseSchema = z.object({
  id: z.number().int().positive(),
  email: z.string().email(),
  username: z.string().nullable(),
  groups: z.array(z.string()),
  temporary_password: z.string().min(12),
  force_reset: z.literal(true),
});
const statusResponseSchema = z.object({ id: z.number().int().positive(), active: z.boolean() });
const groupsResponseSchema = z.object({ id: z.number().int().positive(), groups: z.array(z.string()) });
const resetResponseSchema = z.object({
  id: z.number().int().positive(),
  temporary_password: z.string().min(12),
  force_reset: z.literal(true),
});

export interface AdminUsersFilters {
  search: string;
  status: 'all' | 'active' | 'disabled';
  group: string;
  sort: 'newest' | 'oldest';
  /** Person type: `all`, a `users.kind` value, or `unlinked` for NULL. */
  kind: string;
}

/** Facet value for `kind IS NULL` — not a `users.kind` enum member. */
export const UNLINKED_KIND = 'unlinked';

/** Values the `kind` query parameter accepts, mirroring the backend allowlist. */
export const ADMIN_USER_KINDS = [
  'student', 'employee', 'contractor', 'alumni', UNLINKED_KIND,
] as const;

/** Display labels for person-type facet values. */
export const PERSON_KIND_LABELS: Record<string, string> = {
  student: 'Student',
  employee: 'Employee',
  contractor: 'Contractor',
  alumni: 'Alumni',
  [UNLINKED_KIND]: 'Unlinked',
};

export function personKindLabel(kind: string): string {
  return PERSON_KIND_LABELS[kind] ?? kind;
}

export const adminUserFacetsSchema = z.object({
  kinds: z.array(z.string()),
});
export type AdminUserFacets = z.infer<typeof adminUserFacetsSchema>;

/**
 * Query string for the admin user list.
 *
 * Pure so it can be tested directly: every filter is omitted at its
 * "no filter" default rather than sent, and the first page must omit
 * `cursor` rather than send an empty one. `kind` passes through untouched —
 * `unlinked` is a genuine filter value for `kind IS NULL`, not a sentinel
 * meaning "no filter".
 */
export function adminUserListParams(
  cursor: string | null,
  limit: number,
  filters: AdminUsersFilters,
): URLSearchParams {
  const params = new URLSearchParams();

  if (cursor !== null && cursor !== '') params.set('cursor', cursor);
  params.set('limit', String(limit));
  if (filters.search !== '') params.set('q', filters.search);
  if (filters.status !== 'all') params.set('status', filters.status);
  if (filters.group !== 'all') params.set('group', filters.group);
  if (filters.sort !== 'newest') params.set('sort', filters.sort);
  if (filters.kind !== 'all') params.set('kind', filters.kind);

  return params;
}

export function useAdminUsers(cursor: string | null, filters: AdminUsersFilters, limit = 25) {
  return useQuery<{ data: AdminUser[]; next: string | null }, ApiEnvelopeError>({
    queryKey: ['admin', 'users', { cursor, limit, ...filters }],
    queryFn: async () => {
      const params = adminUserListParams(cursor, limit, filters);
      const res = await apiClient.get<unknown[]>(`/admin/users?${params.toString()}`);
      return { data: z.array(adminUserSchema).parse(res.data), next: getNextCursor(res) };
    },
    placeholderData: keepPreviousData,
  });
}

/**
 * Person-type facet options — the distinct `users.kind` values present in
 * the tenant, plus `unlinked` for accounts with no person record (platform
 * accounts such as the seeded superadmin).
 *
 * Sourced from the rows so every option is guaranteed to match at least one
 * user: the enum also carries `contractor` and `alumni`, which are empty
 * today and would otherwise offer a filter that can only return nothing.
 */
export function useAdminUserFacets() {
  return useQuery<AdminUserFacets, ApiEnvelopeError>({
    queryKey: ['admin', 'users', 'facets'],
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/admin/users/facets');
      return adminUserFacetsSchema.parse(res.data);
    },
    // Facets change only when a user's person type changes.
    staleTime: 5 * 60_000,
  });
}

export function useAdminRoles() {
  return useQuery<AdminRole[], ApiEnvelopeError>({
    queryKey: ['admin', 'roles'],
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/rbac/roles');
      return rolesResponseSchema.parse(res.data).roles;
    },
    staleTime: 5 * 60_000,
  });
}

export function useCreateUser() {
  const qc = useQueryClient();
  return useMutation<z.infer<typeof createUserResponseSchema>, ApiEnvelopeError, CreateUserInput>({
    mutationFn: async (input) => {
      const res = await apiClient.post<unknown>('/admin/users', createUserSchema.parse(input));
      return createUserResponseSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin'] });
      toast.success('User created.');
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to create user.'),
  });
}

export function useSetUserActive() {
  const qc = useQueryClient();
  return useMutation<z.infer<typeof statusResponseSchema>, ApiEnvelopeError, { id: number; active: boolean }>({
    mutationFn: async ({ id, active }) => {
      const res = await apiClient.post<unknown>(`/admin/users/${id}/status`, { active });
      return statusResponseSchema.parse(res.data);
    },
    onSuccess: (_d, vars) => {
      void qc.invalidateQueries({ queryKey: ['admin'] });
      toast.success(vars.active ? 'User activated.' : 'User deactivated.');
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Status change failed.'),
  });
}

export function useSetUserGroups() {
  const qc = useQueryClient();
  return useMutation<z.infer<typeof groupsResponseSchema>, ApiEnvelopeError, { id: number; groups: string[] }>({
    mutationFn: async ({ id, groups }) => {
      const res = await apiClient.post<unknown>(`/admin/users/${id}/groups`, { groups });
      return groupsResponseSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin', 'users'] });
      toast.success('Roles updated.');
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Role update failed.'),
  });
}

export function useResetUserPassword() {
  const qc = useQueryClient();
  return useMutation<z.infer<typeof resetResponseSchema>, ApiEnvelopeError, number>({
    mutationFn: async (id) => {
      const res = await apiClient.post<unknown>(`/admin/users/${id}/reset-password`);
      return resetResponseSchema.parse(res.data);
    },
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['admin', 'users'] }),
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Reset failed.'),
  });
}

export interface ProvisionDirectoryUserInput {
  identifier: string;
  kind: 'student' | 'employee';
  /**
   * Roles to ADD. The server unions these with the person's ACTUAL
   * current roles — this is not a replacement list, and directory
   * defaults must never be treated as the authoritative role set.
   */
  groups?: string[];
}

/**
 * Resolve/JIT-provision a university-directory person and additively grant
 * roles. Success invalidates BOTH the admin user list (the synthetic
 * directory row is now a real local account) and the patient caches (the
 * person becomes findable/lookup-able in clinic flows).
 */
export function useProvisionDirectoryUser() {
  const qc = useQueryClient();
  return useMutation<AdminUser, ApiEnvelopeError, ProvisionDirectoryUserInput>({
    mutationFn: async ({ identifier, kind, groups }) => {
      const body: Record<string, unknown> = { identifier, kind };
      if (groups !== undefined) body.groups = groups;
      const res = await apiClient.post<unknown>('/admin/users/provision', body);
      return adminUserSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin', 'users'] });
      void qc.invalidateQueries({ queryKey: ['patients'] });
      void qc.invalidateQueries({ queryKey: ['kiosk-lookup'] });
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Provisioning directory user failed.'),
  });
}
