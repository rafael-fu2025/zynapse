/**
 * Zod schemas — mirror the backend's CI4 validation rules and DTOs.
 *
 * Identity-consolidated: /auth/me exposes `person_kind` + `person_name`
 * read straight from `users` (there is no separate person record).
 *
 * Login accepts EITHER a university identifier (student/employee number,
 * MIS-delegated when enabled server-side) OR an email (admin and
 * operational accounts). Exactly one — the backend rejects both.
 */
import { z } from 'zod';

export const loginSchema = z
  .object({
    identifier: z
      .string()
      .min(1, 'Enter your student or employee number.')
      .max(64, 'That number is too long.'),
      // A syntactically valid email in the identifier field means the
      // user is an admin — send it as `email` instead, below.
    password: z.string().min(8, 'Password must be at least 8 characters.').max(256),
  });

export type LoginInput = z.infer<typeof loginSchema>;

/**
 * Split a raw login credential into the wire shape: an email-looking
 * string goes out as `email` (local admin path), anything else as
 * `identifier` (MIS-delegated path).
 */
export function loginWirePayload(input: LoginInput):
  { identifier: string; password: string } | { email: string; password: string } {
  const value = input.identifier.trim();
  if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
    return { email: value.toLowerCase(), password: input.password };
  }
  return { identifier: value, password: input.password };
}

export const sessionSchema = z.object({
  id: z.number().int().positive(),
  // Email is empty for MIS-delegated students/employees who authenticate
  // with their university ID number and hold no local password.
  email: z.string(),
  username: z.string(),
  // University ID number (student_number or employee_number) — the
  // login identifier for MIS-delegated accounts; null for admins.
  identifier: z.string().nullable().optional(),
  is_active: z.boolean(),
  force_reset: z.boolean().default(false),
  person_kind: z.enum(['student', 'employee', 'contractor', 'alumni']).nullable().optional(),
  person_name: z.string().nullable().optional(),
  // Teaching flag for employee accounts — drives the "can I refer?"
  // hint on the Referrals page (server enforces it regardless).
  is_teaching: z.boolean().nullable().optional(),
  permissions: z.array(z.string()),
});

export type Session = z.infer<typeof sessionSchema>;

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, 'Enter your current password.').max(256),
    new_password: z.string().min(12, 'New password must be at least 12 characters.').max(256),
    confirm_password: z.string().min(1, 'Re-enter the new password.'),
  })
  .refine((v) => v.new_password === v.confirm_password, {
    message: 'Passwords do not match',
    path: ['confirm_password'],
  });
export type ChangePasswordInput = z.infer<typeof changePasswordSchema>;
