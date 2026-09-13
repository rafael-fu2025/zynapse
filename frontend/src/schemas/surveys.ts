/**
 * Survey engine schemas (2026-09 parity plan Phase B). Mirrors
 * Modules\Counselling\Services\SurveyService payloads.
 */
import { z } from 'zod';

export const surveyAudienceSchema = z.enum([
  'all',
  'new_students',
  'continuing_students',
  'graduating_students',
]);
export type SurveyAudience = z.infer<typeof surveyAudienceSchema>;

export const surveyStatusSchema = z.enum(['draft', 'scheduled', 'live', 'closed']);
export const questionTypeSchema = z.enum(['single', 'multi', 'likert', 'rating', 'free_text', 'external_url']);
export type QuestionType = z.infer<typeof questionTypeSchema>;

export const surveyQuestionSchema = z.object({
  id: z.number().int().positive(),
  sort_order: z.number().int().nonnegative(),
  question_type: questionTypeSchema,
  question_text: z.string(),
  is_required: z.boolean(),
  options: z.array(z.object({ id: z.number().int().positive(), text: z.string() })),
  option_ids: z.array(z.number().int().positive()),
});
export type SurveyQuestion = z.infer<typeof surveyQuestionSchema>;

export const surveySchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  description: z.string().nullable(),
  category: z.enum(['survey', 'interview']),
  audience: surveyAudienceSchema,
  is_required: z.boolean(),
  publish_at: z.string().nullable(),
  close_at: z.string().nullable(),
  created_at: z.string(),
  status: surveyStatusSchema,
  version_id: z.number().int().positive().nullable(),
  version_no: z.number().int().positive().nullable(),
  question_count: z.number().int().nonnegative(),
  response_count: z.number().int().nonnegative(),
  questions: z.array(surveyQuestionSchema),
});
export type Survey = z.infer<typeof surveySchema>;

export const surveyResponseRowSchema = z.object({
  id: z.number().int().positive(),
  survey_id: z.number().int().positive(),
  student_user_id: z.number().int().positive(),
  student_name: z.string().nullable(),
  email: z.string().nullable(),
  submitted_at: z.string(),
});
export type SurveyResponseRow = z.infer<typeof surveyResponseRowSchema>;

export const surveyResponseDetailSchema = z.object({
  id: z.number().int().positive(),
  survey_id: z.number().int().positive(),
  student_user_id: z.number().int().positive(),
  student_name: z.string().nullable(),
  submitted_at: z.string(),
  answers: z.array(z.object({
    question_id: z.number().int().positive(),
    question_type: questionTypeSchema,
    value: z.unknown(),
  })),
});
export type SurveyResponseDetail = z.infer<typeof surveyResponseDetailSchema>;

/** Student-facing survey (available list item / requirement). */
export const mySurveySchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  description: z.string().nullable(),
  category: z.enum(['survey', 'interview']),
  is_required: z.boolean(),
  publish_at: z.string().nullable(),
  close_at: z.string().nullable(),
});
export type MySurvey = z.infer<typeof mySurveySchema>;

export const mySurveyFormSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  description: z.string().nullable(),
  close_at: z.string().nullable(),
  version_id: z.number().int().positive(),
  questions: z.array(surveyQuestionSchema),
});
export type MySurveyForm = z.infer<typeof mySurveyFormSchema>;

/** Builder question as authored in the form editor (pre-save). */
export interface BuilderQuestion {
  question_text: string;
  question_type: QuestionType;
  is_required: boolean;
  /** Newline-separated option texts for single/multi. */
  options_text: string;
}

export const surveyMetaInputSchema = z.object({
  title: z.string().min(1, 'Title is required.').max(200),
  description: z.string().max(1000).optional(),
  audience: surveyAudienceSchema,
  category: z.enum(['survey', 'interview']),
  is_required: z.boolean(),
  publish_at: z.string().optional(),
  close_at: z.string().optional(),
});
export type SurveyMetaInput = z.infer<typeof surveyMetaInputSchema>;
