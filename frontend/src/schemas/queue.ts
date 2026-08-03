/**
 * Zod schemas — Clinic queue (mirrors backend QueueController).
 */
import { z } from 'zod';

export const queueEntrySchema = z.object({
  id: z.number().int().positive(),
  encounter_id: z.number().int().positive(),
  position: z.number().int().min(1),
  status: z.enum(['waiting', 'called', 'in_session', 'done', 'skipped']),
  display_name: z.string(),
  patient_school_id: z.string(),
  chief_complaint: z.string(),
  called_at: z.string().nullable(),
  started_at: z.string().nullable(),
  finished_at: z.string().nullable(),
});
export type QueueEntry = z.infer<typeof queueEntrySchema>;

export const publicQueueStateSchema = z.object({
  now_serving: z
    .object({
      position: z.number().int(),
      display_name: z.string(),
      patient_school_id: z.string(),
    })
    .nullable(),
  waiting: z.array(
    z.object({
      position: z.number().int(),
      display_name: z.string(),
      patient_school_id: z.string(),
      est_wait_minutes: z.number().int().min(0).optional(),
    }),
  ),
  updated_at: z.string(),
});
export type PublicQueueState = z.infer<typeof publicQueueStateSchema>;

export type QueueAction = 'start' | 'skip' | 'complete';
