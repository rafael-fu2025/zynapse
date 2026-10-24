/**
 * Shapes for the self-service account-security endpoints (2026-10 audit
 * G-1/G-5): TOTP MFA status/enrollment and the refresh-session inventory.
 */
import { z } from 'zod';

export const mfaStatusSchema = z.object({
  enabled: z.boolean(),
  staged: z.boolean(),
  required: z.boolean(),
});
export type MfaStatus = z.infer<typeof mfaStatusSchema>;

export const mfaEnrollmentSchema = z.object({
  secret: z.string().min(16),
  otpauth_uri: z.string().startsWith('otpauth://'),
});
export type MfaEnrollment = z.infer<typeof mfaEnrollmentSchema>;

export const sessionRowSchema = z.object({
  family_id: z.string().uuid(),
  started_at: z.string(),
  last_used_at: z.string().nullable(),
  session_expires_at: z.string().nullable(),
  user_agent_hash: z.string().nullable(),
  ip_subnet: z.string().nullable(),
  rotations: z.number(),
  is_current: z.boolean(),
});
export type SessionRow = z.infer<typeof sessionRowSchema>;
