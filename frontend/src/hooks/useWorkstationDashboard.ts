import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { workstationFor } from '@/lib/workstations';
import { workstationDashboardSchema, type WorkstationUnit } from '@/schemas/workstationDashboard';
import { hasPermission, useAuthStore } from '@/store/auth';

export function useWorkstationDashboard(unit: WorkstationUnit) {
  const state = useAuthStore();
  const enabled = state.accessToken !== null && state.userId !== null
    && hasPermission(state, workstationFor(unit).permission);
  const query = useQuery({
    // Permissions also partition cached PHI if access is reduced in-session.
    queryKey: ['dashboard', 'workstation', unit, state.userId, [...state.permissions].sort()],
    enabled,
    queryFn: async ({ signal }) => {
      const response = await apiClient.get<unknown>(`/dashboard/${unit}`, { signal });
      const data = workstationDashboardSchema.parse(response.data);
      if (data.unit !== unit) throw new Error('Unexpected workstation response.');
      return data;
    },
    staleTime: 30_000,
    refetchInterval: 60_000,
  });
  return { ...query, enabled };
}
