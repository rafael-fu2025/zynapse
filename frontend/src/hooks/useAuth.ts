/**
 * useAuth — TanStack Query + Axios orchestration for the auth endpoints.
 *
 * Each hook is colocated with the screen that uses it; this file hosts
 * the shared `login` / `logout` / `me` hooks because they're cross-cut.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { sessionSchema, type ChangePasswordInput, type LoginInput, type Session } from '@/schemas/auth';
import { useAuthStore } from '@/store/auth';

export function useLogin() {
  const setAccessToken = useAuthStore((s) => s.setAccessToken);
  const setSession = useAuthStore((s) => s.setSession);
  const navigate = useNavigate();
  const qc = useQueryClient();

  return useMutation<Session, ApiEnvelopeError, LoginInput>({
    mutationFn: async (input) => {
      const res = await apiClient.post<{ access_token: string; expires_in: number }>(
        '/auth/login',
        input,
      );
      setAccessToken(res.data.access_token);
      const me = await apiClient.get<Session>('/auth/me');
      const parsed = sessionSchema.parse(me.data);
      setSession({ userId: parsed.id, email: parsed.email, permissions: parsed.permissions });
      // Seed the shared session query immediately. Without this, mounting
      // the protected shell after login issues another /auth/me request.
      qc.setQueryData(['me'], parsed);
      return parsed;
    },
    onSuccess: () => {
      navigate('/', { replace: true });
    },
  });
}

export function useLogout() {
  const clear = useAuthStore((s) => s.clear);
  const qc = useQueryClient();
  const navigate = useNavigate();

  return useMutation<void, ApiEnvelopeError, void>({
    mutationFn: async () => {
      await apiClient.post('/auth/logout');
    },
    onSettled: () => {
      clear();
      void qc.clear();
      navigate('/login', { replace: true });
    },
  });
}

export function useMe() {
  return useQuery<Session, ApiEnvelopeError>({
    queryKey: ['me'],
    queryFn: async () => {
      const res = await apiClient.get<Session>('/auth/me');
      return sessionSchema.parse(res.data);
    },
    staleTime: 60_000,
    retry: false,
  });
}

/**
 * Self-service password change. On success the API returns a fresh
 * token pair (all other sessions are revoked), so the store token is
 * swapped and `me` refetched to clear `force_reset`.
 */
export function useChangePassword() {
  const setAccessToken = useAuthStore((s) => s.setAccessToken);
  const qc = useQueryClient();
  const navigate = useNavigate();

  return useMutation<void, ApiEnvelopeError, ChangePasswordInput>({
    mutationFn: async (input) => {
      const res = await apiClient.post<{ access_token: string }>('/auth/change-password', {
        current_password: input.current_password,
        new_password: input.new_password,
      });
      setAccessToken(res.data.access_token);
    },
    onSuccess: async () => {
      // AWAIT the refetch: navigating while the cached `me` still says
      // force_reset=true would bounce straight back to /change-password.
      await qc.invalidateQueries({ queryKey: ['me'] });
      toast.success('Password changed.');
      navigate('/', { replace: true });
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Password change failed.');
    },
  });
}
