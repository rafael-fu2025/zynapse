/**
 * useGuidanceFollowups — the RA 11036 §24 aftercare caseload (Phase C),
 * the survey aggregate, and the session interview linkage.
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';

const followupSchema = z.object({
  id: z.number().int().positive(),
  student_user_id: z.number().int().positive(),
  student_name: z.string(),
  survey_title: z.string(),
  risk_reason: z.string(),
  who5_score: z.number().int().nullable(),
  status: z.enum(['new', 'in_review', 'addressed', 'closed']),
  assigned_counsellor_user_id: z.number().int().positive().nullable(),
  assigned_counsellor: z.string().nullable(),
  outcome_note: z.string().nullable(),
  due_at: z.string().nullable(),
  created_at: z.string(),
});
export type GuidanceFollowup = z.infer<typeof followupSchema>;

export function useGuidanceFollowups(status: string, mineOnly: boolean) {
  return useQuery<GuidanceFollowup[], ApiEnvelopeError>({
    queryKey: ['guidance', 'followups', { status, mineOnly }],
    queryFn: async () => {
      const params = new URLSearchParams();
      if (status !== 'all') params.set('status', status);
      if (mineOnly) params.set('mine', '1');
      const res = await apiClient.get<unknown[]>(`/counselling/followups?${params.toString()}`);
      return z.array(followupSchema).parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useAssignFollowup() {
  const qc = useQueryClient();
  return useMutation<{ id: number }, ApiEnvelopeError, { id: number; counsellorUserId: number }>({
    mutationFn: async ({ id, counsellorUserId }) => {
      const res = await apiClient.post<{ id: number }>(`/counselling/followups/${id}/assign`, {
        counsellor_user_id: counsellorUserId,
      });
      return res.data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'followups'] });
      toast.success('Follow-up assigned.');
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to assign the follow-up.'),
  });
}

export function useTransitionFollowup() {
  const qc = useQueryClient();
  return useMutation<{ id: number; status: string }, ApiEnvelopeError, { id: number; action: string; outcomeNote?: string }>({
    mutationFn: async ({ id, action, outcomeNote }) => {
      const res = await apiClient.post<{ id: number; status: string }>(`/counselling/followups/${id}/transition`, {
        action,
        outcome_note: outcomeNote ?? null,
      });
      return res.data;
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'followups'] });
      toast.success(`Follow-up moved to ${r.status.replace('_', ' ')}.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to update the follow-up.'),
  });
}

// ---- aggregate --------------------------------------------------------

const aggregateSchema = z.object({
  survey_id: z.number().int().positive(),
  title: z.string(),
  response_count: z.number().int().nonnegative(),
  questions: z.array(z.object({
    question_id: z.number().int().positive(),
    question_text: z.string(),
    question_type: z.enum(['single', 'multi', 'likert', 'rating', 'free_text', 'external_url']),
    answered: z.number().int().nonnegative(),
    average: z.number().nullable().optional(),
    scale_counts: z.record(z.string(), z.number()).optional(),
    option_counts: z.record(z.string(), z.number()).optional(),
  })),
});
export type SurveyAggregate = z.infer<typeof aggregateSchema>;

export function useSurveyAggregate(surveyId: number | null) {
  return useQuery<SurveyAggregate, ApiEnvelopeError>({
    queryKey: ['guidance', 'surveys', surveyId, 'aggregate'],
    enabled: surveyId !== null,
    queryFn: async () => {
      const res = await apiClient.get<SurveyAggregate>(`/counselling/surveys/${surveyId}/aggregate`);
      return aggregateSchema.parse(res.data);
    },
  });
}

// ---- session linkage ---------------------------------------------------

export interface SessionInterview {
  response_id: number;
  survey_id: number;
  survey_title: string;
  submitted_at: string;
  answers: Array<{ question: string; value: string }>;
}

export function useSessionInterviews(sessionId: number | null) {
  return useQuery<SessionInterview[], ApiEnvelopeError>({
    queryKey: ['counselling', 'sessions', sessionId, 'interviews'],
    enabled: sessionId !== null,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/counselling/sessions/${sessionId}/interviews`);
      return z.array(z.object({
        response_id: z.number().int().positive(),
        survey_id: z.number().int().positive(),
        survey_title: z.string(),
        submitted_at: z.string(),
        answers: z.array(z.object({ question: z.string(), value: z.string() })),
      })).parse(res.data);
    },
  });
}
