/**
 * Zod schemas — Clinic Appointments (mirrors backend AppointmentController rules).
 */
import { z } from 'zod';

export const appointmentSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  // Decorated by AppointmentService::decorate — null when the
  // patient row is missing or archived. The page falls back to the
  // raw school id in that case.
  patient_name: z.string().nullable().optional(),
  patient_kind: z.enum(['student', 'employee']).nullable().optional(),
  provider_user_id: z.number().int().positive(),
  provider_name: z.string().nullable().optional(),
  scheduled_at: z.string(),
  status: z.enum(['Scheduled', 'CheckedIn', 'Completed', 'Cancelled', 'NoShow']),
  reason: z.string().nullable(),
  created_at: z.string(),
});
export type Appointment = z.infer<typeof appointmentSchema>;

export const scheduleAppointmentSchema = z.object({
  patient_school_id: z.string().min(1).max(32),
  provider_user_id: z.number().int().positive(),
  // UTC wall-clock string, `YYYY-MM-DD HH:mm:ss` (backend valid_date rule).
  scheduled_at: z.string().regex(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/, 'Use YYYY-MM-DD HH:mm:ss (UTC)'),
  reason: z.string().max(255).optional(),
});
export type ScheduleAppointmentInput = z.infer<typeof scheduleAppointmentSchema>;

/**
 * Partial-update schema. Every field is optional — the SPA sends
 * only what the user actually changed. The backend enforces the
 * same allow-list (`patient_school_id`, `provider_user_id`,
 * `scheduled_at`, `reason`) and refuses edits on non-Scheduled rows.
 */
export const updateAppointmentSchema = z.object({
  patient_school_id: z.string().min(1).max(32).optional(),
  provider_user_id: z.number().int().positive().optional(),
  scheduled_at: z.string().regex(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/, 'Use YYYY-MM-DD HH:mm:ss (UTC)').optional(),
  reason: z.string().max(255).optional().or(z.literal('')),
});
export type UpdateAppointmentInput = z.infer<typeof updateAppointmentSchema>;

export const appointmentTransitions = ['CheckedIn', 'Completed', 'Cancelled', 'NoShow'] as const;
export type AppointmentTransition = (typeof appointmentTransitions)[number];
