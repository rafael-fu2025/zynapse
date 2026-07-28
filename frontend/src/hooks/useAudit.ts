/**
 * Audit hooks — read-only. Wraps TanStack Query with keyset pagination.
 */
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { auditEventSchema, type AuditEvent } from '@/schemas/audit';

interface AuditPage {
  data: AuditEvent[];
  next: string | null;
}

export function useAuditEvents(
  cursor: string | null,
  limit: number,
  filters: { action?: string; entity_type?: string },
) {
  return useQuery<AuditPage, ApiEnvelopeError>({
    queryKey: ['audit', 'events', { cursor, limit, filters }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      params.set('limit', String(limit));
      if (filters.action !== undefined && filters.action !== '') params.set('action', filters.action);
      if (filters.entity_type !== undefined && filters.entity_type !== '') params.set('entity_type', filters.entity_type);

      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/audit/events?${params.toString()}`,
      );
      const data = z.array(auditEventSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
    staleTime: 30_000,
    // TanStack v5: `keepPreviousData` was replaced by `placeholderData`.
    placeholderData: (prev) => prev,
  });
}
