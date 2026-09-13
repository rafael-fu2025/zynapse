/**
 * useSurveys — staff builder/publish + responses hooks (Phase B) and
 * the student self-service hooks (/me/guidance/surveys + requirements).
 */
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { toast } from 'sonner';
import { apiClient } from '@/api/client';
import type { ApiEnvelopeError } from '@/api/envelope';
import type {
  BuilderQuestion,
  MySurvey,
  MySurveyForm,
  Survey,
  SurveyMetaInput,
  SurveyResponseDetail,
  SurveyResponseRow,
} from '@/schemas/surveys';
import {
  mySurveyFormSchema,
  mySurveySchema,
  surveyQuestionSchema,
  surveyResponseDetailSchema,
  surveyResponseRowSchema,
  surveySchema,
} from '@/schemas/surveys';

const surveyListSchema = z.array(
  surveySchema.omit({ questions: true }).extend({ questions: z.array(surveyQuestionSchema).default([]) }),
);

function metaPayload(input: SurveyMetaInput): Record<string, unknown> {
  return {
    title: input.title,
    description: input.description?.trim() || null,
    audience: input.audience,
    category: input.category,
    is_required: input.is_required,
    publish_at: input.publish_at?.trim() || null,
    close_at: input.close_at?.trim() || null,
  };
}

function questionsPayload(questions: BuilderQuestion[]): Array<Record<string, unknown>> {
  return questions
    .filter((q) => q.question_text.trim() !== '')
    .map((q) => ({
      question_text: q.question_text.trim(),
      question_type: q.question_type,
      is_required: q.is_required,
      options: ['single', 'multi'].includes(q.question_type)
        ? q.options_text.split('\n').map((line) => line.trim()).filter((line) => line !== '')
        : [],
    }));
}

// ---- staff: builder + publish ---------------------------------------

export function useSurveys() {
  return useQuery<Survey[], ApiEnvelopeError>({
    queryKey: ['guidance', 'surveys'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/counselling/surveys');
      return surveyListSchema.parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useSurvey(id: number | null) {
  return useQuery<Survey, ApiEnvelopeError>({
    queryKey: ['guidance', 'surveys', id],
    enabled: id !== null,
    queryFn: async () => {
      const res = await apiClient.get<Survey>(`/counselling/surveys/${id}`);
      return surveySchema.parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useCreateSurvey() {
  const qc = useQueryClient();
  return useMutation<Survey, ApiEnvelopeError, { meta: SurveyMetaInput; questions: BuilderQuestion[] }>({
    mutationFn: async ({ meta, questions }) => {
      const created = await apiClient.post<Survey>('/counselling/surveys', metaPayload(meta));
      const id = (created.data as { id: number }).id;
      const withQuestions = await apiClient.post<Survey>(`/counselling/surveys/${id}/questions`, {
        questions: questionsPayload(questions),
      });
      return withQuestions.data;
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'surveys'] });
      toast.success(`Survey "${s.title}" saved as draft.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to create the survey.'),
  });
}

export function useUpdateSurvey() {
  const qc = useQueryClient();
  return useMutation<Survey, ApiEnvelopeError, { id: number; meta: SurveyMetaInput; questions: BuilderQuestion[] }>({
    mutationFn: async ({ id, meta, questions }) => {
      await apiClient.post<Survey>(`/counselling/surveys/${id}/update`, metaPayload(meta));
      void qc.invalidateQueries({ queryKey: ['guidance', 'surveys'] });
      const withQuestions = await apiClient.post<Survey>(`/counselling/surveys/${id}/questions`, {
        questions: questionsPayload(questions),
      });
      return withQuestions.data;
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'surveys'] });
      toast.success(`Survey "${s.title}" saved.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to save the survey.'),
  });
}

export function usePublishSurvey() {
  const qc = useQueryClient();
  return useMutation<Survey, ApiEnvelopeError, { id: number; title: string }>({
    mutationFn: async ({ id }) => {
      const res = await apiClient.post<Survey>(`/counselling/surveys/${id}/publish`);
      return res.data;
    },
    onSuccess: (s) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'surveys'] });
      toast.success(`Survey "${s.title}" published — students can answer now.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to publish the survey.'),
  });
}

export function useArchiveSurvey() {
  const qc = useQueryClient();
  return useMutation<{ id: number }, ApiEnvelopeError, { id: number; title: string }>({
    mutationFn: async ({ id }) => {
      const res = await apiClient.post<{ id: number; archived: boolean }>(`/counselling/surveys/${id}/archive`);
      return { id: res.data.id };
    },
    onSuccess: (_r, vars) => {
      void qc.invalidateQueries({ queryKey: ['guidance', 'surveys'] });
      toast.success(`Survey "${vars.title}" archived.`);
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to archive the survey.'),
  });
}

// ---- staff: responses ------------------------------------------------

export function useSurveyResponses(surveyId: number | null) {
  return useQuery<SurveyResponseRow[], ApiEnvelopeError>({
    queryKey: ['guidance', 'surveys', surveyId, 'responses'],
    enabled: surveyId !== null,
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>(`/counselling/surveys/${surveyId}/responses`);
      return z.array(surveyResponseRowSchema).parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useSurveyResponseDetail(surveyId: number | null, responseId: number | null) {
  return useQuery<SurveyResponseDetail, ApiEnvelopeError>({
    queryKey: ['guidance', 'surveys', surveyId, 'responses', responseId],
    enabled: surveyId !== null && responseId !== null,
    queryFn: async () => {
      const res = await apiClient.get<SurveyResponseDetail>(`/counselling/surveys/${surveyId}/responses/${responseId}`);
      return surveyResponseDetailSchema.parse(res.data);
    },
  });
}

// ---- student: /me/guidance/surveys -----------------------------------

export function useMySurveys() {
  return useQuery<MySurvey[], ApiEnvelopeError>({
    queryKey: ['me', 'guidance', 'surveys'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/me/guidance/surveys');
      return z.array(mySurveySchema).parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useMySurveyForm(id: number | null) {
  return useQuery<MySurveyForm, ApiEnvelopeError>({
    queryKey: ['me', 'guidance', 'surveys', id, 'form'],
    enabled: id !== null,
    queryFn: async () => {
      const res = await apiClient.get<MySurveyForm>(`/me/guidance/surveys/${id}`);
      return mySurveyFormSchema.parse(res.data);
    },
  });
}

export function useMyRequirements() {
  return useQuery<MySurvey[], ApiEnvelopeError>({
    queryKey: ['me', 'guidance', 'requirements'],
    queryFn: async () => {
      const res = await apiClient.get<unknown[]>('/me/guidance/requirements');
      return z.array(mySurveySchema).parse(res.data);
    },
    placeholderData: keepPreviousData,
  });
}

export function useSubmitSurvey() {
  const qc = useQueryClient();
  return useMutation<{ id: number }, ApiEnvelopeError, { surveyId: number; answers: Array<{ question_id: number; value: unknown }> }>({
    mutationFn: async ({ surveyId, answers }) => {
      const res = await apiClient.post<{ id: number }>(`/me/guidance/surveys/${surveyId}/submit`, { answers });
      return res.data;
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['me', 'guidance'] });
      toast.success('Submitted — thank you!');
    },
    onError: (err) => toast.error(err.errors[0]?.message ?? 'Failed to submit.'),
  });
}
