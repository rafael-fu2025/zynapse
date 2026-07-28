/**
 * Zod schemas — mirror the backend's CI4 validation rules and DTOs.
 *
 * If the backend changes a validation rule, this file MUST be updated
 * in the same PR. Drift here is a P1 defect.
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