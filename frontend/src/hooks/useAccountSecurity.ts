/**
 * useAccountSecurity — queries + mutations for the self-service security
 * surface: TOTP MFA lifecycle and the refresh-session inventory. All
 * endpoints are self-scoped; the backend enforces ownership.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import { humanizeCode } from '@/api/errorCodes';
import {
  mfaEnrollmentSchema,
  mfaStatusSchema,
  sessionRowSchema,
  type MfaEnrollment,
  type MfaStatus,
  type SessionRow,
} from '@/schemas/accountSecurity';

export function useMfaStatus() {
  return useQuery<MfaStatus, ApiEnvelopeError>({
    queryKey: ['mfa', 'status'],
    queryFn: async () => mfaStatusSchema.parse((await apiClient.get('/auth/mfa/status')).data),
    staleTime: 30_000,
    retry: false,
  });
}

export function useMfaSetup() {
  const qc = useQueryClient();

  return useMutation<MfaEnrollment, ApiEnvelopeError, void>({
    mutationFn: async () => mfaEnrollmentSchema.parse((await apiClient.post('/auth/mfa/setup')).data),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['mfa', 'status'] });
    },
  });
}

export function useMfaConfirm() {
  const qc = useQueryClient();

  return useMutation<void, ApiEnvelopeError, { code: string }>({
    mutationFn: async ({ code }) => {
      await apiClient.post('/auth/mfa/confirm', { code });
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['mfa', 'status'] });
      toast.success('Two-step sign-in enabled.');
    },
    onError: (err) => {
      toast.error(humanizeCode(err.errors[0]?.code ?? '') || 'That code was not accepted.');
    },
  });
}

export function useMfaDisable() {
  const qc = useQueryClient();

  return useMutation<void, ApiEnvelopeError, { code: string; current_password: string }>({
    mutationFn: async (input) => {
      await apiClient.post('/auth/mfa/disable', input);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['mfa', 'status'] });
      toast.success('Two-step sign-in disabled.');
    },
    onError: (err) => {
      toast.error(humanizeCode(err.errors[0]?.code ?? '') || 'Could not disable — check the code and password.');
    },
  });
}

export function useSessions() {
  return useQuery<SessionRow[], ApiEnvelopeError>({
    queryKey: ['auth', 'sessions'],
    queryFn: async () => sessionRowSchema.array().parse((await apiClient.get('/auth/sessions')).data),
    staleTime: 15_000,
    retry: false,
  });
}

export function useRevokeSession() {
  const qc = useQueryClient();

  return useMutation<void, ApiEnvelopeError, { family_id: string }>({
    mutationFn: async ({ family_id }) => {
      await apiClient.post('/auth/sessions/revoke', { family_id });
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['auth', 'sessions'] });
      toast.success('Session signed out.');
    },
    onError: (err) => {
      toast.error(humanizeCode(err.errors[0]?.code ?? '') || 'Could not revoke that session.');
    },
  });
}
