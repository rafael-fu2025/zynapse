/**
 * Zod schemas — kiosk check-in (Phase 17).
 * Mirrors backend `CheckinController` validation + service payloads.
 */
import { z } from 'zod';

export const SCAN_METHODS = ['manual', 'qr', 'rfid'] as const;
export type ScanMethod = (typeof SCAN_METHODS)[number];

export const CHECKIN_DESTINATIONS = ['clinic', 'counselling'] as const;
export type CheckinDestination = (typeof CHECKIN_DESTINATIONS)[number];

export const CHECKIN_OUTCOMES = [
  'counselling_confirmed',
  'counselling_already',
  'counselling_queued',
  'clinic_appointment_confirmed',
  'clinic_appointment_already',
  'clinic_queued',
  'duplicate',
] as const;
export type CheckinOutcome = (typeof CHECKIN_OUTCOMES)[number];

export const scanInputSchema = z.object({
  destination: z.enum(CHECKIN_DESTINATIONS),
  identifier: z.string().max(255).optional().or(z.literal('')),
  guest_name: z.string().max(120).optional().or(z.literal('')),
  method: z.enum(SCAN_METHODS),
  station_id: z.string().max(64).optional().or(z.literal('')),
  purpose: z.string().max(120).optional().or(z.literal('')),
  custom_purpose: z.boolean().optional().default(false),
  scanned_at: z.string().optional(),
}).refine((v) => (v.identifier?.trim() ?? '') !== '' || (v.guest_name?.trim() ?? '') !== '', {
  message: 'Enter an ID or a name.',
  path: ['identifier'],
});
export type ScanInput = z.infer<typeof scanInputSchema>;

export const scanResultSchema = z.object({
  id: z.number().int().positive(),
  destination: z.enum(CHECKIN_DESTINATIONS),
  outcome: z.enum(CHECKIN_OUTCOMES),
  message: z.string(),
  student: z.object({
    student_number: z.string(),
    name: z.string(),
    course: z.string().nullable(),
    year_level: z.number().int().nullable(),
    kind: z.enum(['student', 'employee', 'guest']).default('student'),
  }),
  allergy_alert: z.string().nullable(),
  counselling_appointment_id: z.number().int().nullable(),
  queue: z
    .object({
      encounter_id: z.number().int().positive().nullable().optional(),
      queue_number: z.string().optional(),
      counselling_session_id: z.number().int().positive().nullable().optional(),
      position: z.number().int(),
      estimated_wait_minutes: z.number().int().min(0).optional(),
    })
    .nullable(),
});
export type ScanResult = z.infer<typeof scanResultSchema>;

export const checkinRowSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string().nullable(),
  guest_name: z.string().nullable(),
  method: z.enum(SCAN_METHODS),
  station_id: z.string().nullable(),
  destination: z.enum(CHECKIN_DESTINATIONS),
  outcome: z.enum(CHECKIN_OUTCOMES),
  purpose: z.string().nullable(),
  counselling_appointment_id: z.number().int().nullable(),
  encounter_id: z.number().int().nullable(),
  scanned_at: z.string(),
});
export type CheckinRow = z.infer<typeof checkinRowSchema>;

/** Offline scan buffered client-side (legacy offline_checkin_buffer, moved to the SPA). */
export interface BufferedScan {
  destination: CheckinDestination;
  identifier?: string;
  guest_name?: string;
  method: ScanMethod;
  station_id: string;
  purpose?: string;
  custom_purpose?: boolean;
  scanned_at: string;
}
