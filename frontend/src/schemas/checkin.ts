/**
 * Zod schemas — kiosk check-in (Phase 17).
 * Mirrors backend `CheckinController` validation + service payloads.
 */
import { z } from 'zod';

export const SCAN_METHODS = ['manual', 'qr', 'rfid'] as const;
export type ScanMethod = (typeof SCAN_METHODS)[number];

export const CHECKIN_OUTCOMES = [
  'counselling_confirmed',
  'counselling_already',
  'clinic_appointment_confirmed',
  'clinic_queued',
  'duplicate',
] as const;
export type CheckinOutcome = (typeof CHECKIN_OUTCOMES)[number];

export const scanInputSchema = z.object({
  identifier: z.string().min(1, 'Scan or type an ID.').max(255),
  method: z.enum(SCAN_METHODS),
  station_id: z.string().max(64).optional().or(z.literal('')),
  scanned_at: z.string().optional(),
});
export type ScanInput = z.infer<typeof scanInputSchema>;

export const scanResultSchema = z.object({
  id: z.number().int().positive(),
  outcome: z.enum(CHECKIN_OUTCOMES),
  message: z.string(),
  student: z.object({
    student_number: z.string(),
    name: z.string(),
    course: z.string().nullable(),
    year_level: z.number().int().nullable(),
    kind: z.enum(['student', 'employee']).default('student'),
  }),
  allergy_alert: z.string().nullable(),
  counselling_appointment_id: z.number().int().nullable(),
  queue: z
    .object({
      encounter_id: z.number().int(),
      position: z.number().int(),
      estimated_wait_minutes: z.number().int().min(0).optional(),
    })
    .nullable(),
});
export type ScanResult = z.infer<typeof scanResultSchema>;

export const checkinRowSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  method: z.enum(SCAN_METHODS),
  station_id: z.string().nullable(),
  outcome: z.enum(CHECKIN_OUTCOMES),
  counselling_appointment_id: z.number().int().nullable(),
  encounter_id: z.number().int().nullable(),
  scanned_at: z.string(),
});
export type CheckinRow = z.infer<typeof checkinRowSchema>;

/** Offline scan buffered client-side (legacy offline_checkin_buffer, moved to the SPA). */
export interface BufferedScan {
  identifier: string;
  method: ScanMethod;
  station_id: string;
  scanned_at: string;
}
