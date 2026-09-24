/**
 * Zod schemas — Counselling scheduling (Phase 15).
 * Mirrors backend `ScheduleController` validation rules.
 */
import { z } from 'zod';

const TIME_RE = /^\d{2}:\d{2}(:\d{2})?$/;

export const availabilitySchema = z.object({
  id: z.number().int().positive(),
  counsellor_user_id: z.number().int().positive(),
  day_of_week: z.number().int().min(0).max(6),
  start_time: z.string(),
  end_time: z.string(),
});
export type Availability = z.infer<typeof availabilitySchema>;

export const APPOINTMENT_TYPES = ['initial', 'follow_up', 'crisis', 'referral_based'] as const;
export type AppointmentType = (typeof APPOINTMENT_TYPES)[number];

export const APPOINTMENT_STATUSES = ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'] as const;
export type AppointmentStatus = (typeof APPOINTMENT_STATUSES)[number];

/**
 * Calendar buckets the Queue board reads. Resolved server-side against the
 * Manila business day (never a raw UTC date) and disjoint by construction:
 * `upcoming` and `today` hold live appointments, `archived` holds resolved
 * ones plus anything already dated in the past.
 */
export const APPOINTMENT_SCOPES = ['upcoming', 'today', 'archived', 'all'] as const;
export type AppointmentScope = (typeof APPOINTMENT_SCOPES)[number];

/** Which side of the desk booked the appointment. */
export const APPOINTMENT_SOURCES = ['patient', 'counsellor', 'staff'] as const;
export type AppointmentSource = (typeof APPOINTMENT_SOURCES)[number];

export const SOURCE_LABEL: Record<AppointmentSource, string> = {
  patient: 'Patient',
  counsellor: 'Counsellor',
  staff: 'Staff',
};

export const appointmentSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  counsellor_user_id: z.number().int().positive().nullable(),
  appointment_date: z.string(),
  start_time: z.string(),
  end_time: z.string(),
  type: z.enum(APPOINTMENT_TYPES),
  status: z.enum(APPOINTMENT_STATUSES),
  reason: z.string().nullable(),
  cancellation_reason: z.string().nullable(),
  // Booking origin. Defaulted so the write paths, which re-read a bare row,
  // still parse if a future response omits it.
  source: z.enum(APPOINTMENT_SOURCES).default('staff'),
  // Populated by the list endpoint's `users` join; null on write responses.
  patient_display_name: z.string().nullable().optional(),
  counsellor_display_name: z.string().nullable().optional(),
  created_at: z.string(),
});
export type Appointment = z.infer<typeof appointmentSchema>;

/**
 * Availability window creation.
 *
 * `days_of_week` is a **set** — the desk ticks every weekday it works and
 * submits once, and the backend inserts the whole set in one transaction
 * (2026-09-23). A partial week can never be written, so there is no state
 * where some days landed and the operator cannot tell which.
 */
export const addSlotSchema = z.object({
  days_of_week: z
    .array(z.coerce.number().int().min(0, 'Pick a weekday.').max(6, 'Pick a weekday.'))
    .min(1, 'Pick at least one weekday.'),
  start_time: z.string().regex(TIME_RE, 'Use HH:MM.'),
  end_time: z.string().regex(TIME_RE, 'Use HH:MM.'),
  counsellor_user_id: z.coerce.number().int().positive().optional(),
});
export type AddSlotInput = z.infer<typeof addSlotSchema>;

/**
 * Availability window edit (2026-09-24).
 *
 * Every field is optional on the wire — the backend keeps the current value
 * for anything absent — so a caller may send only what it changed. The desk's
 * edit dialog sends the whole set it displays. A window is one weekday, not a
 * set: moving a window to another day changes that row rather than spreading
 * it, which is what `days_of_week` does on create.
 */
export const updateSlotSchema = z.object({
  day_of_week: z.coerce.number().int().min(0, 'Pick a weekday.').max(6, 'Pick a weekday.').optional(),
  start_time: z.string().regex(TIME_RE, 'Use HH:MM.').optional(),
  end_time: z.string().regex(TIME_RE, 'Use HH:MM.').optional(),
  counsellor_user_id: z.coerce.number().int().positive().optional(),
});
export type UpdateSlotInput = z.infer<typeof updateSlotSchema>;

export const bookAppointmentSchema = z.object({
  patient_school_id: z.string().min(1, 'Required.').max(32),
  counsellor_user_id: z.coerce
    .number({ invalid_type_error: 'Pick a counsellor.' })
    .int()
    .positive('Pick a counsellor.'),
  appointment_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD.'),
  start_time: z.string().regex(TIME_RE, 'Use HH:MM.'),
  end_time: z.string().regex(TIME_RE, 'Use HH:MM.'),
  type: z.enum(APPOINTMENT_TYPES),
  reason: z.string().max(255).optional().or(z.literal('')),
});
export type BookAppointmentInput = z.infer<typeof bookAppointmentSchema>;

export const APPOINTMENT_ACTIONS = ['confirm', 'complete', 'cancel', 'no_show'] as const;
export type AppointmentAction = (typeof APPOINTMENT_ACTIONS)[number];

// Scheduling analytics (Phase P5a — deterministic no-show optimizer).
export const slotAnalyticsSchema = z.object({
  id: z.number().int().positive(),
  counsellor_user_id: z.number().int().positive(),
  day_of_week: z.number().int().min(0).max(6),
  time_slot: z.string(),
  total_appointments: z.number().int().nonnegative(),
  total_no_shows: z.number().int().nonnegative(),
  no_show_rate: z.number(),
  avg_utilization: z.number(),
  recommended_overbooking: z.number().int().nonnegative(),
  last_calculated_at: z.string().nullable(),
});
export type SlotAnalytics = z.infer<typeof slotAnalyticsSchema>;

export const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as const;

/**
 * Three-letter weekday labels for the checkbox row that replaced the single
 * "Day of week" dropdown. Index-aligned with {@link DAY_NAMES} (0 = Sunday),
 * so `DAY_SHORT[i]` is the short form of `DAY_NAMES[i]`.
 */
export const DAY_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const;
