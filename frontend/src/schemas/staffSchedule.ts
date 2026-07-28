/**
 * Zod schemas — Clinic staff schedules (Phase P5b).
 * Mirrors backend `StaffScheduleController` validation rules.
 */
import { z } from 'zod';

const TIME_RE = /^\d{2}:\d{2}(:\d{2})?$/;

export const SCHEDULE_TYPES = ['regular', 'on_call', 'leave'] as const;
export type ScheduleType = (typeof SCHEDULE_TYPES)[number];

export const staffScheduleSchema = z.object({
  id: z.number().int().positive(),
  user_id: z.number().int().positive(),
  day_of_week: z.number().int().min(0).max(6),
  shift_start: z.string(),
  shift_end: z.string(),
  schedule_type: z.enum(SCHEDULE_TYPES),
  effective_from: z.string().nullable(),
  effective_to: z.string().nullable(),
  is_active: z.boolean(),
});
export type StaffSchedule = z.infer<typeof staffScheduleSchema>;

export const createStaffScheduleSchema = z.object({
  user_id: z.coerce.number().int().positive('User ID is required.'),
  day_of_week: z.coerce.number().int().min(0, 'Pick a weekday.').max(6, 'Pick a weekday.'),
  shift_start: z.string().regex(TIME_RE, 'Use HH:MM.'),
  shift_end: z.string().regex(TIME_RE, 'Use HH:MM.'),
  schedule_type: z.enum(SCHEDULE_TYPES),
});
export type CreateStaffScheduleInput = z.infer<typeof createStaffScheduleSchema>;
