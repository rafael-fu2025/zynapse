import { z } from 'zod';

export type SchedulingUnit = 'clinic' | 'guidance';

export const schedulingStaffSchema = z.object({
  user_id: z.number().int().positive(),
  employee_number: z.string().nullable(),
  name: z.string(),
  department: z.string().nullable(),
  position: z.string().nullable(),
  employment_status: z.string().nullable(),
  assignment_id: z.number().int().positive().nullable(),
  enrollment_status: z.enum(['active', 'inactive']).nullable(),
  eligible: z.boolean(),
  can_enroll: z.boolean(),
  status: z.enum(['not_enrolled', 'ready', 'configured', 'inactive_employee', 'inactive_enrollment', 'department_mismatch', 'needs_review']),
  future_appointments: z.number().int().nonnegative(),
});
export type SchedulingStaff = z.infer<typeof schedulingStaffSchema>;

export const schedulingStaffPageSchema = z.object({
  items: z.array(schedulingStaffSchema),
  next_cursor: z.string().nullable(),
  department_keys: z.array(z.string()),
});
export const schedulingProviderSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  position: z.string().nullable().optional(),
  employee_number: z.string().nullable().optional(),
});
export const schedulingBatchResultSchema = z.object({
  created: z.number().int().nonnegative(),
  skipped: z.number().int().nonnegative(),
});
const ids = z.array(z.number().int().positive()).min(1, 'Select at least one employee.').max(100);
export const schedulingEnrollmentSchema = z.object({ user_ids: ids });
export const schedulingTemplateSchema = z.object({
  user_ids: ids,
  days_of_week: z.array(z.number().int().min(0).max(6)).min(1, 'Select at least one weekday.'),
  start_time: z.string().regex(/^(?:[01]\d|2[0-3]):[0-5]\d$/, 'Choose a valid start time.'),
  end_time: z.string().regex(/^(?:[01]\d|2[0-3]):[0-5]\d$/, 'Choose a valid end time.'),
  schedule_type: z.enum(['regular', 'on_call', 'leave']).optional(),
}).refine((input) => input.start_time < input.end_time, {
  message: 'End time must be after start time; overnight windows are not supported.',
  path: ['end_time'],
});
export type SchedulingTemplateInput = z.infer<typeof schedulingTemplateSchema>;
