/**
 * Dashboard hooks — counters + ping.
 */
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';

export interface DashboardCounters {
  clinic?: { open_encounters: number; closed_encounters: number };
  counselling?: { open_sessions: number; closed_sessions: number };
  facilities?: { units_idle: number; units_processing: number; units_awaiting: number };
  referrals?: {
    submitted: number;
    acknowledged: number;
    under_review: number;
    closed: number;
  };
  audit?: { events_last_24h: number };
}

export function useDashboardCounters() {
  return useQuery<DashboardCounters, ApiEnvelopeError>({
    queryKey: ['dashboard', 'counters'],
    queryFn: async () => {
      const res = await apiClient.get<DashboardCounters>('/dashboard/counters');
      return res.data;
    },
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}