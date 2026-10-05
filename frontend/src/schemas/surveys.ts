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

/** Dynamic link types (survey_links.type) — one table, three shapes. */
export const surveyLinkTypeSchema = z.enum(['survey', 'test', 'evaluation']);
export type SurveyLinkType = z.infer<typeof surveyLinkTypeSchema>;

/** Canonical instruments for test-type links. */
export const surveyLinkInstrumentSchema = z.enum(['new_transferees', 'mi', 'ls', 'bfpt', 'custom']);
export type SurveyLinkInstrument = z.infer<typeof surveyLinkInstrumentSchema>;

export const surveyLinkSchema = z.object({
  id: z.number().int().positive(),
  survey_id: z.number().int().positive(),
  type: surveyLinkTypeSchema,
  title: z.string(),
  description: z.string().nullable(),
  external_url: z.string(),
  /** survey-type only: null = every student. */
  audience: surveyAudienceSchema.nullable(),
  /** test-type only. */
  instrument_key: surveyLinkInstrumentSchema.nullable(),
  /** Deprecated gating flag — informational only, never enforced. */
  is_required: z.boolean().default(false),
  is_enabled: z.boolean(),
  /** The link asks the student for a result screenshot (optional). */
  requires_screenshot: z.boolean().default(false),
  sort_order: z.number().int().nonnegative(),
});
export type SurveyLink = z.infer<typeof surveyLinkSchema>;

/** A staged or bound screenshot proof (metadata only — bytes stream separately). */
export const surveyLinkScreenshotSchema = z.object({
  id: z.number().int().positive(),
  link_id: z.number().int().positive(),
  original_name: z.string(),
  mime_type: z.string(),
  size_bytes: z.number().int().nonnegative(),
  created_at: z.string(),
});
export type SurveyLinkScreenshot = z.infer<typeof surveyLinkScreenshotSchema>;

export const surveySchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  description: z.string().nullable(),
  category: z.enum(['survey', 'interview']),
  audience: surveyAudienceSchema,
  /** Targeted registry year levels (1-6); null = every year level. */
  year_levels: z.array(z.number().int().min(1).max(6)).nullable(),
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
  links: z.array(surveyLinkSchema).default([]),
});
export type Survey = z.infer<typeof surveySchema>;

export const surveyResponseRowSchema = z.object({
  id: z.number().int().positive(),
  survey_id: z.number().int().positive(),
  student_user_id: z.number().int().positive(),
  student_name: z.string().nullable(),
  email: z.string().nullable(),
  student_year_level: z.number().int().min(1).max(6).nullable(),
  submitted_at: z.string(),
});
export type SurveyResponseRow = z.infer<typeof surveyResponseRowSchema>;

/** Per-link status inside a staff response detail. */
export const surveyResponseLinkSchema = z.object({
  link_id: z.number().int().positive(),
  type: surveyLinkTypeSchema,
  title: z.string(),
  instrument_key: surveyLinkInstrumentSchema.nullable(),
  is_required: z.boolean(),
  opened: z.boolean(),
  opened_at: z.string().nullable(),
  screenshot: surveyLinkScreenshotSchema.nullable(),
});
export type SurveyResponseLink = z.infer<typeof surveyResponseLinkSchema>;

export const surveyResponseDetailSchema = z.object({
  id: z.number().int().positive(),
  survey_id: z.number().int().positive(),
  student_user_id: z.number().int().positive(),
  student_name: z.string().nullable(),
  student_year_level: z.number().int().min(1).max(6).nullable(),
  submitted_at: z.string(),
  answers: z.array(z.object({
    question_id: z.number().int().positive(),
    question_type: questionTypeSchema,
    value: z.unknown(),
  })),
  links: z.array(surveyResponseLinkSchema).default([]),
});
export type SurveyResponseDetail = z.infer<typeof surveyResponseDetailSchema>;

/** Student-facing survey (available list item / requirement). */
export const mySurveySchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  description: z.string().nullable(),
  category: z.enum(['survey', 'interview']),
  is_required: z.boolean(),
  /** Submitted surveys stay listed for optional proofs while open. */
  submitted: z.boolean().default(false),
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
  /** True once submitted — questions render read-only; proofs stay editable. */
  submitted: z.boolean().default(false),
  questions: z.array(surveyQuestionSchema),
  /** Enabled dynamic links visible to THIS student (audience-filtered server-side). */
  links: z.array(surveyLinkSchema).default([]),
  /** Ids of links this student has opened (click attestation). */
  opened_link_ids: z.array(z.number().int().positive()).default([]),
  /** This student's screenshot proofs (staged or bound) — restores dropzones. */
  screenshots: z.array(surveyLinkScreenshotSchema).default([]),
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

/** Builder link row as authored in the "Optional links" editor (pre-save). */
export interface BuilderLink {
  /** Client-only key for list rendering; the server assigns real ids on save. */
  key: string;
  /** Present for rows loaded from a saved draft/published snapshot. */
  id?: number;
  type: SurveyLinkType;
  title: string;
  description: string;
  external_url: string;
  /** survey-type only: null = every audience. */
  audience: SurveyAudience | null;
  /** test-type only. */
  instrument_key: SurveyLinkInstrument | null;
  /** The link asks for a result screenshot (optional, default off). */
  requires_screenshot: boolean;
  is_enabled: boolean;
}

export const surveyMetaInputSchema = z.object({
  title: z.string().min(1, 'Title is required.').max(200),
  description: z.string().max(1000).optional(),
  audience: surveyAudienceSchema,
  /** Targeted registry year levels; empty = every year level. */
  year_levels: z.array(z.number().int().min(1).max(6)).max(6).optional(),
  category: z.enum(['survey', 'interview']),
  is_required: z.boolean(),
  publish_at: z.string().optional(),
  close_at: z.string().optional(),
});
export type SurveyMetaInput = z.infer<typeof surveyMetaInputSchema>;
