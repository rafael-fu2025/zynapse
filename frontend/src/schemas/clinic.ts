/**
 * Zod schemas — Clinic module.
 */
import { z } from 'zod';

export const TRIAGE_PRIORITIES = ['low', 'medium', 'high', 'urgent'] as const;
export type TriagePriority = (typeof TRIAGE_PRIORITIES)[number];

export const encounterSchema = z.object({
  id: z.number().int().positive(),
  patient_school_id: z.string(),
  chief_complaint: z.string(),
  triage_priority: z.enum(TRIAGE_PRIORITIES).nullable().optional(),
  triage_override: z.boolean().optional(),
  diagnosis: z.string().nullable().optional(),
  status: z.enum(['Open', 'Closed', 'Referred']),
  attending_user_id: z.number().int().positive(),
  started_at: z.string(),
  closed_at: z.string().nullable(),
});
export type Encounter = z.infer<typeof encounterSchema>;

export const TREATMENT_TYPES = ['medication', 'first_aid', 'procedure', 'referral', 'other'] as const;
export type TreatmentType = (typeof TREATMENT_TYPES)[number];

export const treatmentSchema = z.object({
  id: z.number().int().positive(),
  encounter_id: z.number().int().positive(),
  treatment_type: z.enum(TREATMENT_TYPES),
  description: z.string(),
  medicine_id: z.number().int().nullable(),
  medicine_name: z.string().nullable().optional(),
  unit: z.string().nullable().optional(),
  quantity_used: z.number().int().nullable(),
  administered_at: z.string(),
});
export type Treatment = z.infer<typeof treatmentSchema>;

export const setAssessmentSchema = z.object({
  triage_priority: z.enum(TRIAGE_PRIORITIES).optional(),
  diagnosis: z.string().max(5000).optional().or(z.literal('')),
});
export type SetAssessmentInput = z.infer<typeof setAssessmentSchema>;

export const addTreatmentSchema = z
  .object({
    treatment_type: z.enum(TREATMENT_TYPES),
    description: z.string().min(1, 'Required.').max(2000),
    medicine_id: z.number().int().positive().optional(),
    quantity: z.number().int().positive().optional(),
  })
  .refine((v) => v.treatment_type !== 'medication' || (v.medicine_id !== undefined && v.quantity !== undefined), {
    message: 'Medicine and quantity are required for a medication treatment.',
    path: ['medicine_id'],
  });
export type AddTreatmentInput = z.infer<typeof addTreatmentSchema>;

export const triagePredictionSchema = z.object({
  id: z.number().int().positive(),
  encounter_id: z.number().int().positive(),
  predicted_priority: z.enum(TRIAGE_PRIORITIES),
  confidence_score: z.number(),
  model_version: z.string(),
  features_used: z.record(z.string(), z.unknown()),
  staff_decision: z.enum(['accepted', 'overridden']).nullable(),
});
export type TriagePrediction = z.infer<typeof triagePredictionSchema>;

export const createEncounterSchema = z.object({
  patient_school_id: z.string().min(1).max(32),
  chief_complaint: z.string().min(1).max(255),
});
export type CreateEncounterInput = z.infer<typeof createEncounterSchema>;

export const vitalsSchema = z.object({
  encounter_id: z.number().int().positive(),
  bp_systolic: z.number().int().nullable(),
  bp_diastolic: z.number().int().nullable(),
  pulse_bpm: z.number().int().nullable(),
  temp_c: z.number().nullable(),
  spo2_pct: z.number().int().nullable(),
  weight_kg: z.number().nullable(),
  height_cm: z.number().nullable(),
  recorded_at: z.string(),
});
export type Vitals = z.infer<typeof vitalsSchema>;

export const recordVitalsSchema = z.object({
  bp_systolic:  z.number().int().min(0).max(300).optional(),
  bp_diastolic: z.number().int().min(0).max(200).optional(),
  pulse_bpm:    z.number().int().min(0).max(300).optional(),
  temp_c:       z.number().min(20).max(45).optional(),
  spo2_pct:     z.number().int().min(0).max(100).optional(),
  weight_kg:    z.number().min(0).max(600).optional(),
  height_cm:    z.number().min(0).max(300).optional(),
});
export type RecordVitalsInput = z.infer<typeof recordVitalsSchema>;

/**
 * Result of a bulk CSV import. Mirrors `bulkImportEncounters` return
 * shape on the backend (`Modules\Clinic\Services\ClinicService`).
 */
export const importEncountersResultSchema = z.object({
  imported: z.number().int().min(0),
  first_id: z.number().int().positive(),
  last_id: z.number().int().positive(),
});
export type ImportEncountersResult = z.infer<typeof importEncountersResultSchema>;
