/** Audit reader queries and chain-verification action. */
import { useMutation, useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient, getNextCursor } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  auditChainVerificationSchema,
  auditEventDetailSchema,
  auditEventSchema,
  auditFacetsSchema,
  type AuditChainVerification,
  type AuditEvent,
  type AuditEventDetail,
  type AuditFacets,
} from '@/schemas/audit';

export interface AuditFilters {
  action?: string;
  entity_type?: string;
  entity_id?: string;
  actor_user_id?: string;
  request_id?: string;
  from?: string;
  to?: string;
  q?: string;
}

const AUDIT_FILTER_KEYS: ReadonlyArray<keyof AuditFilters> = [
  'action', 'entity_type', 'entity_id', 'actor_user_id',
  'request_id', 'from', 'to', 'q',
];

interface AuditPage {
  data: AuditEvent[];
  next: string | null;
}

export function appendAuditFilters(params: URLSearchParams, filters: AuditFilters): void {
  for (const key of AUDIT_FILTER_KEYS) {
    const value = filters[key];
    if (value !== undefined && value.trim() !== '') params.set(key, value.trim());
  }
}

export function useAuditEvents(cursor: string | null, limit: number, filters: AuditFilters) {
  return useQuery<AuditPage, ApiEnvelopeError>({
    queryKey: ['audit', 'events', { cursor, limit, filters }],
    queryFn: async () => {
      const params = new URLSearchParams({ limit: String(limit) });
      if (cursor !== null) params.set('cursor', cursor);
      appendAuditFilters(params, filters);
      const res = await apiClient.get<unknown[]>(`/audit/events?${params.toString()}`);
      return {
        data: z.array(auditEventSchema).parse(res.data),
        next: getNextCursor(res),
      };
    },
    staleTime: 30_000,
    placeholderData: (previous) => previous,
  });
}

export function useAuditFacets() {
  return useQuery<AuditFacets, ApiEnvelopeError>({
    queryKey: ['audit', 'facets'],
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/audit/facets');
      return auditFacetsSchema.parse(res.data);
    },
    staleTime: 5 * 60_000,
  });
}

export function useAuditEvent(id: number | null) {
  return useQuery<AuditEventDetail, ApiEnvelopeError>({
    queryKey: ['audit', 'event', id],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/audit/events/${String(id)}`);
      return auditEventDetailSchema.parse(res.data);
    },
    enabled: id !== null,
  });
}

export function useVerifyAuditChain() {
  return useMutation<AuditChainVerification, ApiEnvelopeError, void>({
    mutationFn: async () => {
      const res = await apiClient.get<unknown>('/audit/verify', { timeout: 120_000 });
      return auditChainVerificationSchema.parse(res.data);
    },
  });
}
