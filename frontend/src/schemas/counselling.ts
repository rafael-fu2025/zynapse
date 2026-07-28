/**
 * Zod schemas — Counselling module.
 */
import { z } from 'zod';

export const sessionSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  counsellor_user_id: z.number().int().positive(),
  started_at: z.string(),
  ended_at: z.string().nullable(),
});
export type Session = z.infer<typeof sessionSchema>;

export const noteSchema = z.object({
  session_id: z.number().int().positive(),
  plaintext: z.string(),
  key_version: z.number().int(),
  created_at: z.string(),
});
export type Note = z.infer<typeof noteSchema>;

export const openSessionSchema = z.object({
  patient_school_id: z.string().min(1).max(32),
});
export type OpenSessionInput = z.infer<typeof openSessionSchema>;

export const writeNotesSchema = z.object({
  plaintext: z.string().min(1).max(16384),
});
export type WriteNotesInput = z.infer<typeof writeNotesSchema>;