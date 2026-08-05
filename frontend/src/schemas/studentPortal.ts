/**
 * Zod schemas — Student Portal (mirrors backend
 * `App\Modules\Clinic\Controllers\StudentSelfController`).
 *
 * Identity-consolidated: the portal reads the caller's profile straight
 * from `users` (kind=student) — `id` IS the `users.id`, no link fields.
 */
import { z } from 'zod';

export const studentPortalProfileSchema = z.object({
  id: z.number().int().positive(),
  kind: z.enum(['student', 'employee', 'contractor', 'alumni']).nullable(),
  student_number: z.string().nullable(),
  first_name: z.string(),
  last_name: z.string(),
  middle_name: z.string().nullable(),
  course: z.string().nullable(),
  year_level: z.number().int().nullable(),
  section: z.string().nullable(),
  date_of_birth: z.string().nullable(),
  gender: z.enum(['male', 'female', 'other']).nullable(),
  blood_type: z.string().nullable(),
  has_qr: z.boolean(),
  has_rfid: z.boolean(),
  consecutive_no_shows: z.number().int().nonnegative(),
  archived: z.boolean(),
  created_at: z.string(),
  // Convenience: `stu:20266239` / `qr:20266239` / `rfid:20266239`.
  // The kiosk reads the prefix to know which scan payload to
  // expect; the SPA renders this string directly into a QR.
  kiosk_identifier: z.string(),
});
export type StudentPortalProfile = z.infer<typeof studentPortalProfileSchema>;

export const studentPortalClinicVisitSchema = z.object({
  id: z.number().int().positive(),
  chief_complaint: z.string(),
  triage_priority: z.enum(['low', 'medium', 'high', 'urgent']).nullable(),
  status: z.enum(['open', 'closed', 'referred']),
  attending_username: z.string().nullable(),
  started_at: z.string(),
  closed_at: z.string().nullable(),
  created_at: z.string(),
});
export type StudentPortalClinicVisit = z.infer<typeof studentPortalClinicVisitSchema>;

/** A clinic appointment the student booked for themselves (or had booked). */
export const studentAppointmentSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  provider_user_id: z.number().int().positive(),
  provider_name: z.string().nullable(),
  scheduled_at: z.string(),
  status: z.string(),
  reason: z.string().nullable(),
  created_at: z.string(),
});
export type StudentAppointment = z.infer<typeof studentAppointmentSchema>;

/** Minimal clinic-provider option for the booking picker (name + id). */
export const studentProviderSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
});
export type StudentProvider = z.infer<typeof studentProviderSchema>;

/** Self-booking input — `scheduled_at` is UTC `YYYY-MM-DD HH:mm:ss`. */
export const bookStudentAppointmentSchema = z.object({
  provider_user_id: z.number({ message: 'Pick a provider.' }).int().positive('Pick a provider.'),
  scheduled_at: z.string().regex(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/, 'Pick a date and time.'),
  reason: z.string().max(500).optional(),
});
export type BookStudentAppointmentInput = z.infer<typeof bookStudentAppointmentSchema>;
