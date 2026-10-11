/**
 * Zod schemas — Referrals module.
 */
import { z } from 'zod';

export const REFERRAL_STATUSES = ['submitted', 'acknowledged', 'under_review', 'closed'] as const;
export type ReferralStatus = (typeof REFERRAL_STATUSES)[number];

export const REFERRAL_PRIORITIES = ['low', 'normal', 'high', 'urgent'] as const;
export type ReferralPriority = (typeof REFERRAL_PRIORITIES)[number];

/** Guidance-appropriate reason codes for the "Refer to Guidance" action. */
export const GUIDANCE_REASON_OPTIONS = [
  { value: 'emotional_concern', label: 'Emotional concern' },
  { value: 'behavioral_concern', label: 'Behavioral concern' },
  { value: 'academic_stress', label: 'Academic stress' },
  { value: 'family_concern', label: 'Family concern' },
  { value: 'bullying', label: 'Bullying' },
  { value: 'self_harm_risk', label: 'Self-harm risk' },
  { value: 'other', label: 'Other' },
] as const;

export const referralSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  source_encounter_id: z.number().int().positive().nullable().optional(),
  source_session_id: z.number().int().positive().nullable().optional(),
  source_module: z.enum(['clinic', 'counselling']),
  target_module: z.enum(['clinic', 'counselling']),
  artifact_type: z.string(),
  status: z.enum(REFERRAL_STATUSES),
  priority: z.enum(REFERRAL_PRIORITIES).optional(),
  reason_code: z.string().nullable(),
  // Handling provider (nurse / counsellor) — assigned when the
  // receiving side acknowledges the referral (panel revision).
  provider_user_id: z.number().int().positive().nullable().optional(),
  provider_name: z.string().nullable().optional(),
  queue_handoff_destination: z.enum(['clinic', 'counselling']).nullable().optional(),
  queue_handoff_entry_id: z.number().int().positive().nullable().optional(),
  queue_handoff_at: z.string().nullable().optional(),
  created_at: z.string(),
  updated_at: z.string(),
});
export type Referral = z.infer<typeof referralSchema>;

export const createReferralSchema = z.object({
  patient_school_id: z.string().min(1, 'Pick a patient.').max(32),
  source_module: z.enum(['clinic', 'counselling'], { message: 'Pick the source module.' }),
  target_module: z.enum(['clinic', 'counselling'], { message: 'Pick the target module.' }),
  artifact_type: z.string().min(1, 'Pick an artifact type.').max(64),
  priority: z.enum(REFERRAL_PRIORITIES).optional(),
  reason_code: z.string().max(64).optional(),
  notes_plaintext: z.string().max(8192).optional(),
}).refine((v) => v.source_module !== v.target_module, {
  message: 'Source and target module must differ.',
  path: ['target_module'],
});
export type CreateReferralInput = z.infer<typeof createReferralSchema>;

/**
 * Clinic → Guidance referral raised from the student-records Actions
 * menu. Only reason, notes and priority travel to Guidance — the
 * student's clinic medical record stays with Clinic staff.
 */
export const referToGuidanceSchema = z.object({
  patient_school_id: z.string().min(1),
  source_module: z.literal('clinic'),
  target_module: z.literal('counselling'),
  artifact_type: z.literal('intake_pass'),
  reason_code: z.string().min(1, 'Pick a reason.').max(64),
  priority: z.enum(REFERRAL_PRIORITIES, { message: 'Pick a priority.' }),
  notes_plaintext: z.string().max(2000, 'Notes must be 2000 characters or fewer.').optional(),
});
export type ReferToGuidanceInput = z.infer<typeof referToGuidanceSchema>;
