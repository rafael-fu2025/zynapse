/**
 * Zod schemas — Referrals module.
 */
import { z } from 'zod';

export const referralSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  source_module: z.enum(['clinic', 'counselling']),
  target_module: z.enum(['clinic', 'counselling']),
  artifact_type: z.string(),
  status: z.enum(['Submitted', 'Acknowledged', 'UnderReview', 'Closed']),
  reason_code: z.string().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
  qr_expires_at: z.string().nullable(),
});
export type Referral = z.infer<typeof referralSchema>;

export const createReferralSchema = z.object({
  patient_school_id: z.string().min(1).max(32),
  source_module: z.enum(['clinic', 'counselling']),
  target_module: z.enum(['clinic', 'counselling']),
  artifact_type: z.string().min(1).max(64),
  reason_code: z.string().max(64).optional(),
  notes_plaintext: z.string().max(8192).optional(),
}).refine((v) => v.source_module !== v.target_module, {
  message: 'source_module must differ from target_module.',
  path: ['target_module'],
});
export type CreateReferralInput = z.infer<typeof createReferralSchema>;

export const issueQrSchema = z.object({
  ttl_seconds: z.number().int().min(60).max(86_400).default(3600),
});

export const verifyTokenSchema = z.object({
  token: z.string().min(1),
});

export const verifyResultSchema = z.object({
  status: z.enum(['Valid', 'Expired', 'Revoked']),
  artifact_type: z.string().nullable(),
  issuer: z.string().nullable(),
});
export type VerifyResult = z.infer<typeof verifyResultSchema>;
