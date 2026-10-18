/**
 * Dashboard hooks — counters + ping.
 */
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { useAuthStore } from '@/store/auth';

export interface DashboardCounters {
  clinic?: { open_encounters: number; closed_encounters: number };
  counselling?: { open_sessions: number; closed_sessions: number };
  facilities?: { units_idle: number; units_processing: number; units_awaiting: number; at_risk: number };
  referrals?: {
    submitted: number;
    acknowledged: number;
    under_review: number;
    closed: number;
  };
  audit?: { events_last_24h: number };
}

export function useDashboardCounters() {
  const { userId, accessToken, permissions } = useAuthStore();
  return useQuery<DashboardCounters, ApiEnvelopeError>({
    queryKey: ['dashboard', 'counters', userId, [...permissions].sort()],
    enabled: userId !== null && accessToken !== null,
    queryFn: async () => {
      const res = await apiClient.get<DashboardCounters>('/dashboard/counters');
      return res.data;
    },
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
}