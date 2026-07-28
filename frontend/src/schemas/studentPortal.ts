/**
 * Zod schemas — Student Portal (mirrors backend
 * `App\Modules\Clinic\Controllers\StudentSelfController`).
 *
 * The portal is strictly self-scoped: every endpoint resolves the
 * caller's `users.id` to a `patients_students` row by the
 * `patients_students.user_id` UNIQUE link (added in Phase 13).
 */
import { z } from 'zod';

export const studentPortalProfileSchema = z.object({
  id: z.number().int().positive(),
  user_id: z.number().int().positive().nullable(),
  student_number: z.string(),
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
  status: z.enum(['Open', 'Closed', 'Referred']),
  attending_username: z.string().nullable(),
  started_at: z.string(),
  closed_at: z.string().nullable(),
  created_at: z.string(),
});
export type StudentPortalClinicVisit = z.infer<typeof studentPortalClinicVisitSchema>;
