/**
 * Zod schemas — Employee Portal (mirrors backend
 * `App\Modules\Clinic\Controllers\EmployeeSelfController`).
 *
 * The portal is strictly self-scoped: every endpoint resolves the
 * caller's `users.id` to a `patients_employees` row by the
 * `patients_employees.user_id` UNIQUE link (added in Phase 11).
 */
import { z } from 'zod';

export const employeePortalProfileSchema = z.object({
  // Phase 3.4: unified-identity fields surfaced via EmployeeSelfService.
  kind: z.enum(['student', 'employee', 'contractor', 'alumni']).optional(),
  persons_id: z.number().int().positive().nullable().optional(),
  patient_identifier_id: z.number().int().positive().nullable().optional(),
  identifier: z.string().optional(),
  id: z.number().int().positive(),
  user_id: z.number().int().positive().nullable(),
  employee_number: z.string(),
  first_name: z.string(),
  last_name: z.string(),
  middle_name: z.string().nullable(),
  department: z.string().nullable(),
  position: z.string().nullable(),
  date_hired: z.string().nullable(),
  employment_status: z.string(),
  hr_synced_at: z.string().nullable(),
  emergency_contact_name: z.string().nullable(),
  emergency_contact_phone: z.string().nullable(),
  has_qr: z.boolean(),
  has_rfid: z.boolean(),
  is_teaching: z.boolean(),
  archived: z.boolean(),
  created_at: z.string(),
  // Convenience: `qr:20266839` / `rfid:20266839` / `emp:20266839`.
  // The SPA renders this directly into a QR code; the kiosk
  // understands the `kind:` prefix.
  kiosk_identifier: z.string(),
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
