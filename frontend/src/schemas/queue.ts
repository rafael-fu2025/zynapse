/**
 * Zod schemas — Clinic queue (mirrors backend QueueController).
 */
import { z } from 'zod';

/**
 * Non-staff close outcomes surfaced on the queue row (panel revision,
 * August 2026). Mirrors `EncounterOutcome`. Set when a queue entry
 * lands on `done` via a non-staff path; null for normal closes.
 */
export const QUEUE_OUTCOMES = ['no_show', 'auto_closed'] as const;
export type QueueOutcome = (typeof QUEUE_OUTCOMES)[number];

export const queueEntrySchema = z.object({
  id: z.number().int().positive(),
  destination: z.literal('clinic').optional(),
  queue_number: z.string().optional(),
  encounter_id: z.number().int().positive(),
  position: z.number().int().min(1),
  status: z.enum(['waiting', 'called', 'in_session', 'done', 'skipped']),
  display_name: z.string(),
  patient_school_id: z.string(),
  // Full registry name (`First Last`) — powers the id tooltip in the
  // Queue tab's Patient column; null for guests/orphans.
  patient_name: z.string().nullable().optional(),
  chief_complaint: z.string(),
  // Kiosk station that opened the visit — mirrors Encounter.station_id.
  station_id: z.string().nullable().optional(),
  called_at: z.string().nullable(),
  started_at: z.string().nullable(),
  finished_at: z.string().nullable(),
  // Status of the linked encounter (`open`/`closed`/`referred`).
  // Mirrors `Encounter.status` — staff queue UI uses it to gate
  // destructive per-row actions (Close encounter, Mark no-show)
  // without a second round-trip.
  encounter_status: z.enum(['open', 'closed', 'referred']),
  // Mirrors `Encounter.outcome` for the joined encounter; null on
  // walk-ins that close normally.
  outcome: z.enum(QUEUE_OUTCOMES).nullable().optional(),
  // Mirrors `Encounter.outcome` on the queue row itself (set when
  // `clinic_queue_entries.outcome` is populated by the auto-close /
  // no-show cascades).
  encounter_outcome: z.enum(QUEUE_OUTCOMES).nullable().optional(),
});
export type QueueEntry = z.infer<typeof queueEntrySchema>;

export const guidanceQueueEntrySchema = z.object({
  id: z.number().int().positive(),
  position: z.number().int().min(1),
  queue_number: z.string().optional(),
  status: z.enum(['waiting', 'called', 'in_session', 'done', 'skipped']),
  display_name: z.string(),
  patient_school_id: z.string(),
  purpose: z.string(),
  counselling_session_id: z.number().int().positive().nullable().optional(),
  assigned_counsellor_user_id: z.number().int().positive().nullable().optional(),
  counselling_appointment_id: z.number().int().positive().nullable().optional(),
  referral_id: z.number().int().positive().nullable().optional(),
  called_at: z.string().nullable(),
  started_at: z.string().nullable(),
  finished_at: z.string().nullable(),
});
export type GuidanceQueueEntry = z.infer<typeof guidanceQueueEntrySchema>;

// Public lobby rows: identity fields are present only where the
// destination's public branch still discloses them. Guidance rows
// carry the queue-number abstraction ONLY (audit 2026-09-05, F16);
// clinic rows keep name + school ID.
const publicQueueRowSchema = z.object({
  position: z.number().int(),
  queue_number: z.string(),
  display_name: z.string().optional(),
  patient_school_id: z.string().optional(),
  est_wait_minutes: z.number().int().min(0).optional(),
});

const publicQueueColumnSchema = z.object({
  active: z.array(publicQueueRowSchema).optional(),
  now_serving: publicQueueRowSchema.nullable(),
  waiting: z.array(publicQueueRowSchema),
});

export const publicQueueStateSchema = z.object({
  guidance: publicQueueColumnSchema,
  clinic: publicQueueColumnSchema,
  updated_at: z.string(),
});
export type PublicQueueState = z.infer<typeof publicQueueStateSchema>;

export type QueueAction = 'start' | 'skip' | 'complete';
