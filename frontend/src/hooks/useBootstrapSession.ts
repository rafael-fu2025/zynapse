/**
 * useBootstrapSession — runs on app mount.
 *
 * If a refresh-cookie is present, attempts a silent refresh to mint a
 * new access token. If that succeeds, /auth/me is called to populate
 * permissions. If anything fails, the SPA remains on /login.
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/api/client';
import { sessionSchema } from '@/schemas/auth';
import { useAuthStore } from '@/store/auth';

export function useBootstrapSession() {
  const setAccessToken = useAuthStore((s) => s.setAccessToken);
  const setSession = useAuthStore((s) => s.setSession);
  const qc = useQueryClient();

  return useQuery({
    queryKey: ['boot', 'session'],
    retry: false,
    staleTime: Infinity,
    queryFn: async () => {
      // A direct login already minted an access token and populated the
      // shared `me` cache. Reuse it instead of rotating the refresh token
      // and fetching /auth/me again as the protected shell mounts.
      const access = useAuthStore.getState().accessToken;
      const cached = sessionSchema.safeParse(qc.getQueryData(['me']));
      if (access !== null && cached.success) {
        setSession({
          userId: cached.data.id,
          email: cached.data.email,
          permissions: cached.data.permissions,
        });
        return cached.data;
      }

      // 1. Attempt silent refresh.
      try {
        const refresh = await apiClient.post<{ access_token: string }>(
          '/auth/refresh',
          {},
        );
        if (refresh.data?.access_token !== undefined) {
          setAccessToken(refresh.data.access_token);
        }
      } catch {
        return null;
      }
      // 2. Hydrate session.
      try {
        const me = await apiClient.get<unknown>('/auth/me');
        const parsed = sessionSchema.safeParse(me.data);
        if (parsed.success) {
          setSession({
            userId: parsed.data.id,
            email: parsed.data.email,
            permissions: parsed.data.permissions,
          });
          // Seed the shared `['me']` cache so Layout/Dashboard's useMe()
          // does NOT fire a second serialized /auth/me on cold load
          // (costly behind the single-threaded PHP dev server).
          qc.setQueryData(['me'], parsed.data);
          return parsed.data;
        }
      } catch {
        return null;
      }
      return null;
    },
  });
}
