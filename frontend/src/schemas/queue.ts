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
