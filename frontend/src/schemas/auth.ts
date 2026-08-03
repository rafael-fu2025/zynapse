/**
 * Zod schemas — mirror the backend's CI4 validation rules and DTOs.
 *
 * Phase 2.1: sessionSchema now exposes the unified-identity fields
 * returned by /auth/me (persons_id, person_kind, patient_identifier_id).
 */
import { z } from 'zod';

export const loginSchema = z.object({
  email: z.string().email().max(255),
  password: z.string().min(8).max(256),
});

export type LoginInput = z.infer<typeof loginSchema>;

export const sessionSchema = z.object({
  id: z.number().int().positive(),
  email: z.string().email(),
  username: z.string(),
  is_active: z.boolean(),
  force_reset: z.boolean().default(false),
  persons_id: z.number().int().positive().nullable().optional(),
  person_kind: z.enum(['student', 'employee', 'contractor', 'alumni']).nullable().optional(),
  person_name: z.string().nullable().optional(),
  patient_identifier_id: z.number().int().positive().nullable().optional(),
  permissions: z.array(z.string()),
});

export type Session = z.infer<typeof sessionSchema>;

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(8).max(256),
    new_password: z.string().min(12).max(256),
    confirm_password: z.string(),
  })
  .refine((v) => v.new_password === v.confirm_password, {
    message: 'Passwords do not match',
    path: ['confirm_password'],
  });
export type ChangePasswordInput = z.infer<typeof changePasswordSchema>;
