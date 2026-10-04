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
  // Recall-window fields (October 2026). Present on every queue row;
  // null until the entry is skipped. `skip_deadline_at` is the
  // authoritative deadline the countdown renders against — the backend
  // stamps it at skip time and the expiry sweep keys off it, so a page
  // reload can never reset the timer.
  skipped_at: z.string().nullable().optional(),
  skip_deadline_at: z.string().nullable().optional(),
  returned_at: z.string().nullable().optional(),
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

/**
 * Skipped Patients module row (October 2026) — mirrors
 * `QueueService::skippedRow()`.
 *
 * `status` is the DERIVED display state the backend computes from the
 * raw queue row (`skipped` → still counting down, `returned` → staff
 * brought them back, `no_show` → window lapsed or manually marked), so
 * the SPA never re-implements that precedence.
 */
export const SKIPPED_PATIENT_STATUSES = ['skipped', 'returned', 'no_show'] as const;
export type SkippedPatientStatus = (typeof SKIPPED_PATIENT_STATUSES)[number];

export const skippedPatientSchema = z.object({
  id: z.number().int().positive(),
  queue_number: z.string(),
  encounter_id: z.number().int().positive(),
  position: z.number().int().min(1),
  // Display status (see above) — the raw queue status rides along as
  // `queue_status` for diagnostics and future states the SPA does not
  // know about yet.
  status: z.string(),
  queue_status: z.enum(['waiting', 'called', 'in_session', 'done', 'skipped']),
  display_name: z.string(),
  patient_school_id: z.string(),
  patient_name: z.string().nullable().optional(),
  chief_complaint: z.string(),
  appointment_id: z.number().int().positive().nullable(),
  appointment_at: z.string().nullable(),
  appointment_status: z.string().nullable(),
  skipped_at: z.string().nullable(),
  skip_deadline_at: z.string().nullable(),
  returned_at: z.string().nullable(),
  encounter_status: z.enum(['open', 'closed', 'referred']),
  outcome: z.enum(QUEUE_OUTCOMES).nullable(),
});
export type SkippedPatient = z.infer<typeof skippedPatientSchema>;

/**
 * `GET /clinic/queue/skipped` payload: the rows plus a server clock
 * stamp the module uses to correct for client-clock skew when
 * rendering countdowns.
 */
export const skippedPatientsResponseSchema = z.object({
  data: z.array(skippedPatientSchema),
  server_now: z.string(),
});
export type SkippedPatientsResponse = z.infer<typeof skippedPatientsResponseSchema>;

export const guidanceQueueEntrySchema = z.object({
  id: z.number().int().positive(),
  position: z.number().int().min(1),
  queue_number: z.string().optional(),
  status: z.enum(['waiting', 'called', 'in_session', 'done', 'skipped']),
  display_name: z.string(),
  patient_school_id: z.string(),
  purpose: z.string(),
  counselling_session_id: z.number().int().positive().nullable().optional(),
  // Notes on the linked session — drives the Complete gate (a session
  // cannot complete without notes, 2026-09-25).
  note_count: z.number().int().min(0).optional(),
  assigned_counsellor_user_id: z.number().int().positive().nullable().optional(),
  counselling_appointment_id: z.number().int().positive().nullable().optional(),
  referral_id: z.number().int().positive().nullable().optional(),
  called_at: z.string().nullable(),
  started_at: z.string().nullable(),
  finished_at: z.string().nullable(),
});
export type GuidanceQueueEntry = z.infer<typeof guidanceQueueEntrySchema>;

/**
 * Queue transition actions.
 *
 * `start` / `complete` drive a visit through the chair; `skip` opens the
 * 60-minute recall window; `return` puts the patient back in line as
 * `waiting`; `recall` calls them straight back up as `called`
 * (October 2026 panel revision).
 */
export type QueueAction = 'start' | 'skip' | 'complete' | 'return' | 'recall';
