/**
 * Referrals hooks — list, create, lifecycle transitions, QR issuance, verify.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import {
  createReferralSchema,
  issueQrSchema,
  referralSchema,
  verifyResultSchema,
  verifyTokenSchema,
  type CreateReferralInput,
  type Referral,
  type VerifyResult,
} from '@/schemas/referrals';
import { kioskLookupSchema, type KioskLookupResult } from '@/hooks/usePatientLookup';

interface ReferralPage {
  data: Referral[];
  next: string | null;
}

/**
 * Patient autocomplete for the referral form — narrow, referrals-scoped
 * lookup (`GET /referrals/patient-lookup`), gated by `referrals.create`
 * so teaching employees can search patients without broad clinic access.
 */
export function useReferralPatientLookup(query: string) {
  const q = query.trim();
  const enabled = q.length >= 2;
  return useQuery<KioskLookupResult[], ApiEnvelopeError>({
    queryKey: ['referral-patient-lookup', q],
    enabled,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(
        `/referrals/patient-lookup?q=${encodeURIComponent(q)}&limit=8`,
      );
      return kioskLookupSchema.parse(res.data);
    },
    staleTime: 30_000,
  });
}

export function useReferrals(cursor: string | null, status: string | null, limit = 25) {
  return useQuery<ReferralPage, ApiEnvelopeError>({
    queryKey: ['referrals', { cursor, status, limit }],
    // Referrals progress through other providers' actions (acknowledge
    // / review / close); poll so statuses update without a refresh.
    refetchInterval: 30_000,
    queryFn: async () => {
      const params = new URLSearchParams();
      if (cursor !== null) params.set('cursor', cursor);
      if (status !== null) params.set('status', status);
      params.set('limit', String(limit));
      const res = await apiClient.get<{ data: unknown[]; next: string | null }>(
        `/referrals?${params.toString()}`,
      );
      const data = z.array(referralSchema).parse(res.data);
      return { data, next: res.data?.next ?? null };
    },
  });
}

export function useCreateReferral() {
  const qc = useQueryClient();
  return useMutation<Referral, ApiEnvelopeError, CreateReferralInput>({
    mutationFn: async (input) => {
      const valid = createReferralSchema.parse(input);
      const res = await apiClient.post<Referral>('/referrals', valid);
      return referralSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['referrals'] });
      toast.success('Referral created.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to create referral.');
    },
  });
}

const REFERRAL_ACTION_PAST: Record<'acknowledge' | 'review' | 'close', string> = {
  acknowledge: 'acknowledged',
  review: 'reviewed',
  close: 'closed',
};

function useTransition(action: 'acknowledge' | 'review' | 'close') {
  const qc = useQueryClient();
  return useMutation<Referral, ApiEnvelopeError, number | { id: number; providerUserId?: number }>({
    mutationFn: async (arg) => {
      const id = typeof arg === 'number' ? arg : arg.id;
      // Panel revision: acknowledging may assign the handling provider
      // (nurse / counsellor). Defaults server-side to the current user.
      const body =
        action === 'acknowledge' && typeof arg === 'object' && arg.providerUserId !== undefined
          ? { provider_user_id: arg.providerUserId }
          : undefined;
      const res = await apiClient.post<Referral>(`/referrals/${id}/${action}`, body);
      return referralSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['referrals'] });
      toast.success(`Referral ${REFERRAL_ACTION_PAST[action]}.`);
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? `Failed to ${action} referral.`);
    },
  });
}

export const useAcknowledgeReferral = () => useTransition('acknowledge');
export const useReviewReferral = () => useTransition('review');
export const useCloseReferral = () => useTransition('close');

interface IssuedQr {
  referral_id: number;
  token: string;
  expires_at: string;
  artifact_type: string;
}

export function useIssueQr() {
  const qc = useQueryClient();
  return useMutation<IssuedQr, ApiEnvelopeError, { id: number; ttlSeconds: number }>({
    mutationFn: async ({ id, ttlSeconds }) => {
      const valid = issueQrSchema.parse({ ttl_seconds: ttlSeconds });
      const res = await apiClient.post<IssuedQr>(`/referrals/${id}/issue-qr`, valid);
      return res.data;
    },
    onSuccess: () => {
      // Refresh the list so the QR state (and the "Revoke QR code"
      // action) reflects immediately after issuing.
      void qc.invalidateQueries({ queryKey: ['referrals'] });
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to issue QR.');
    },
  });
}

/** Revoke the referral's current QR token (POST /referrals/{id}/revoke-qr). */
export function useRevokeReferralQr() {
  const qc = useQueryClient();
  return useMutation<Referral, ApiEnvelopeError, number>({
    mutationFn: async (id) => {
      const res = await apiClient.post<Referral>(`/referrals/${id}/revoke-qr`);
      return referralSchema.parse(res.data);
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['referrals'] });
      toast.success('QR token revoked.');
    },
    onError: (err) => {
      toast.error(err.errors[0]?.message ?? 'Failed to revoke QR.');
    },
  });
}

/**
 * Public verify — no auth needed. Hits the public endpoint.
 */
export function useVerifyQr() {
  return useMutation<VerifyResult, ApiEnvelopeError, string>({
    mutationFn: async (token) => {
      const valid = verifyTokenSchema.parse({ token });
      const res = await apiClient.post<VerifyResult>('/referrals/verify', valid);
      return verifyResultSchema.parse(res.data);
    },
  });
}