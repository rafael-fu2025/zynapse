/**
 * Zod schemas — Counselling module.
 */
import { z } from 'zod';
import { referralSchema } from '@/schemas/referrals';

export const sessionSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  counsellor_user_id: z.number().int().positive(),
  started_at: z.string(),
  ended_at: z.string().nullable(),
});
export type Session = z.infer<typeof sessionSchema>;

export const sessionDetailSchema = sessionSchema.extend({
  patient_display_name: z.string(),
  queue_entry_id: z.number().int().positive().nullable(),
  queue_number: z.string().nullable(),
  queue_status: z.enum(['waiting', 'called', 'in_session', 'done', 'skipped']).nullable(),
  purpose: z.string().nullable(),
  appointment_id: z.number().int().positive().nullable(),
  incoming_referral_id: z.number().int().positive().nullable(),
  note_count: z.number().int().min(0),
  outgoing_referral: referralSchema.nullable(),
});
export type SessionDetail = z.infer<typeof sessionDetailSchema>;

export const noteSchema = z.object({
  session_id: z.number().int().positive(),
  plaintext: z.string(),
  key_version: z.number().int(),
  created_at: z.string(),
  // Amendment chain (F15): id + optional pointer to the note this row
  // supersedes. Notes are insert-only, so corrections happen by amendment.
  id: z.number().int().positive().optional(),
  supersedes_note_id: z.number().int().positive().nullable().optional(),
});
export type Note = z.infer<typeof noteSchema>;

export const openSessionSchema = z.object({
  patient_school_id: z.string().min(1).max(32),
});
export type OpenSessionInput = z.infer<typeof openSessionSchema>;

export const writeNotesSchema = z.object({
  plaintext: z.string().min(1).max(16384),
  supersedes_note_id: z.number().int().positive().optional(),
});
export type WriteNotesInput = z.infer<typeof writeNotesSchema>;
