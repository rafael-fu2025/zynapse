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
  // Staff display name from the unified registry — powers the id
  // tooltip in the roster; null when the user no longer exists.
  user_name: z.string().nullable().optional(),
  day_of_week: z.number().int().min(0).max(6),
  shift_start: z.string(),
  shift_end: z.string(),
  schedule_type: z.enum(SCHEDULE_TYPES),
  effective_from: z.string().nullable(),
  effective_to: z.string().nullable(),
  is_active: z.boolean(),
});
export type StaffSchedule = z.infer<typeof staffScheduleSchema>;

export const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

const baseScheduleInput = {
  user_id: z.coerce.number().int().positive('User ID is required.'),
  day_of_week: z.coerce.number().int().min(0, 'Pick a weekday.').max(6, 'Pick a weekday.'),
  shift_start: z.string().regex(TIME_RE, 'Use HH:MM.'),
  shift_end: z.string().regex(TIME_RE, 'Use HH:MM.'),
  schedule_type: z.enum(SCHEDULE_TYPES),
  effective_from: z
    .string()
    .regex(DATE_RE, 'Use YYYY-MM-DD.')
    .nullable()
    .optional()
    .transform((v) => (v === '' || v === null ? null : v)),
  effective_to: z
    .string()
    .regex(DATE_RE, 'Use YYYY-MM-DD.')
    .nullable()
    .optional()
    .transform((v) => (v === '' || v === null ? null : v)),
};

/** Both effective dates must be set for the order check to apply. */
const effectiveOrderOk = (from: string | null | undefined, to: string | null | undefined): boolean =>
  from === null || from === undefined || to === null || to === undefined || to >= from;

export const createStaffScheduleSchema = z.object(baseScheduleInput).refine(
  (d) => effectiveOrderOk(d.effective_from, d.effective_to),
  { message: 'Effective to must not precede effective from.', path: ['effective_to'] },
);
export type CreateStaffScheduleInput = z.infer<typeof createStaffScheduleSchema>;

/** Update is a partial — every field optional (backend fills the rest). */
export const updateStaffScheduleSchema = z
  .object({
    day_of_week: z.coerce.number().int().min(0).max(6).optional(),
    shift_start: z.string().regex(TIME_RE, 'Use HH:MM.').optional(),
    shift_end: z.string().regex(TIME_RE, 'Use HH:MM.').optional(),
    schedule_type: z.enum(SCHEDULE_TYPES).optional(),
    effective_from: z.string().regex(DATE_RE, 'Use YYYY-MM-DD.').nullable().optional(),
    effective_to: z.string().regex(DATE_RE, 'Use YYYY-MM-DD.').nullable().optional(),
  })
  .refine(
    (d) => effectiveOrderOk(d.effective_from, d.effective_to),
    { message: 'Effective to must not precede effective from.', path: ['effective_to'] },
  );
export type UpdateStaffScheduleInput = z.infer<typeof updateStaffScheduleSchema>;
