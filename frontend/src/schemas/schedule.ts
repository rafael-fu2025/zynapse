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
  max_slots: z.number().int().positive(),
});
export type Availability = z.infer<typeof availabilitySchema>;

export const APPOINTMENT_TYPES = ['initial', 'follow_up', 'crisis', 'referral_based'] as const;
export type AppointmentType = (typeof APPOINTMENT_TYPES)[number];

export const APPOINTMENT_STATUSES = ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'] as const;
export type AppointmentStatus = (typeof APPOINTMENT_STATUSES)[number];

export const appointmentSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  counsellor_user_id: z.number().int().positive(),
  appointment_date: z.string(),
  start_time: z.string(),
  end_time: z.string(),
  type: z.enum(APPOINTMENT_TYPES),
  status: z.enum(APPOINTMENT_STATUSES),
  reason: z.string().nullable(),
  cancellation_reason: z.string().nullable(),
  created_at: z.string(),
});
export type Appointment = z.infer<typeof appointmentSchema>;

export const addSlotSchema = z.object({
  day_of_week: z.coerce.number().int().min(0, 'Pick a weekday.').max(6, 'Pick a weekday.'),
  start_time: z.string().regex(TIME_RE, 'Use HH:MM.'),
  end_time: z.string().regex(TIME_RE, 'Use HH:MM.'),
  max_slots: z.coerce.number().int().min(1, 'At least 1 slot.'),
});
export type AddSlotInput = z.infer<typeof addSlotSchema>;

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
