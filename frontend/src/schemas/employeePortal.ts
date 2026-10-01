/**
 * Zod schemas — Employee Portal (mirrors backend
 * `App\Modules\Clinic\Controllers\EmployeeSelfController`).
 *
 * Identity-consolidated: the portal reads the caller's profile straight
 * from `users` (kind=employee) — `id` IS the `users.id`, no link fields.
 */
import { z } from 'zod';

/**
 * One MIS appointment record of the logged-in employee (backend
 * `EmployeePersonService`). `is_primary` marks the newest record whose
 * position the profile card displays. Optional so legacy payloads and
 * e2e route mocks keep parsing.
 */
export const employeePortalRecordSchema = z.object({
  id: z.number().int(),
  employee_number: z.string(),
  department: z.string().nullable(),
  position: z.string().nullable(),
  position_year: z.number().int().nullable(),
  archived: z.boolean(),
  is_primary: z.boolean(),
  visit_count: z.number().int().min(0),
});
export type EmployeePortalRecord = z.infer<typeof employeePortalRecordSchema>;

export const employeePortalProfileSchema = z.object({
  id: z.number().int().positive(),
  kind: z.enum(['student', 'employee', 'contractor', 'alumni']).nullable(),
  employee_number: z.string().nullable(),
  first_name: z.string(),
  last_name: z.string(),
  middle_name: z.string().nullable(),
  department: z.string().nullable(),
  position: z.string().nullable(),
  position_year: z.number().int().nullable().optional(),
  records: z.array(employeePortalRecordSchema).optional(),
  date_hired: z.string().nullable(),
  employment_status: z.string().nullable(),
  hr_synced_at: z.string().nullable(),
  emergency_contact_name: z.string().nullable(),
  emergency_contact_phone: z.string().nullable(),
  date_of_birth: z.string().nullable(),
  gender: z.string().nullable(),
  has_qr: z.boolean(),
  has_rfid: z.boolean(),
  is_teaching: z.boolean().nullable(),
  archived: z.boolean(),
  created_at: z.string(),
});
export type EmployeePortalProfile = z.infer<typeof employeePortalProfileSchema>;

export const employeePortalClinicVisitSchema = z.object({
  id: z.number().int().positive(),
  chief_complaint: z.string(),
  triage_priority: z.enum(['low', 'medium', 'high', 'urgent']).nullable(),
  status: z.enum(['open', 'closed', 'referred']),
  attending_username: z.string().nullable(),
  started_at: z.string(),
  closed_at: z.string().nullable(),
  created_at: z.string(),
});
export type EmployeePortalClinicVisit = z.infer<typeof employeePortalClinicVisitSchema>;
