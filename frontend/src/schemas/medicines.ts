/**
 * Zod schemas — Medicines (mirrors backend MedicineController rules).
 */
import { z } from 'zod';

export const medicineBatchSchema = z.object({
  id: z.number().int().positive(),
  medicine_id: z.number().int().positive(),
  batch_number: z.string(),
  quantity_received: z.number().int().min(0),
  quantity_remaining: z.number().int().min(0),
  expiration_date: z.string(),
  received_date: z.string(),
  supplier: z.string().nullable(),
  status: z.enum(['active', 'depleted', 'expired', 'recalled']),
  created_at: z.string(),
});
export type MedicineBatch = z.infer<typeof medicineBatchSchema>;

export const medicineSchema = z.object({
  id: z.number().int().positive(),
  generic_name: z.string(),
  brand_name: z.string().nullable(),
  category: z.string().nullable(),
  dosage_form: z.string().nullable(),
  dosage_strength: z.string().nullable(),
  unit: z.string(),
  reorder_threshold: z.number().int().min(0),
  quantity_on_hand: z.number().int().min(0),
  low_stock: z.boolean(),
  earliest_expiry: z.string().nullable(),
  archived: z.boolean(),
  created_at: z.string(),
  batches: z.array(medicineBatchSchema).optional(),
});
export type Medicine = z.infer<typeof medicineSchema>;

export const createMedicineSchema = z.object({
  generic_name: z.string().min(1, 'Required').max(200),
  brand_name: z.string().max(200).optional(),
  category: z.string().max(100).optional(),
  dosage_form: z.string().max(100).optional(),
  dosage_strength: z.string().max(100).optional(),
  unit: z.string().min(1).max(50).default('pc'),
  reorder_threshold: z.number().int().min(0).default(10),
});
export type CreateMedicineInput = z.infer<typeof createMedicineSchema>;

export const addBatchSchema = z.object({
  batch_number: z.string().min(1, 'Required').max(100),
  quantity_received: z.number().int().min(1, 'Must be at least 1'),
  expiration_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'YYYY-MM-DD'),
  supplier: z.string().max(200).optional(),
  note: z.string().max(255).optional(),
});
export type AddBatchInput = z.infer<typeof addBatchSchema>;

export const dispenseSchema = z.object({
  quantity: z.number().int().min(1, 'Must be at least 1'),
  note: z.string().max(255).optional(),
});
export type DispenseInput = z.infer<typeof dispenseSchema>;

export const medicineForecastSchema = z.object({
  medicine_id: z.number().int().positive(),
  forecast_date: z.string(),
  predicted_daily_usage: z.number(),
  predicted_stockout_date: z.string().nullable(),
  predicted_reorder_date: z.string().nullable(),
  current_stock: z.number().int(),
  reorder_threshold: z.number().int(),
  model_type: z.string(),
  seasonality_factor: z.number().nullable(),
});
export type MedicineForecast = z.infer<typeof medicineForecastSchema>;
