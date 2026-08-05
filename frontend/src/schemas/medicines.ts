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

/**
 * Gap 13 — last-movement mini-strip payload. Joined to `users.email`
 * server-side; `null` when the medicine has no transactions yet
 * (just-created).
 */
export const medicineLastMovementSchema = z.object({
  // `recalled` was added with the inventory write-off lifecycle fix.
  type: z.enum(['received', 'dispensed', 'expired', 'adjusted', 'returned', 'recalled']),
  quantity: z.number().int(),
  created_at: z.string(),
  user_email: z.string().nullable(),
});
export type MedicineLastMovement = z.infer<typeof medicineLastMovementSchema>;

export const medicineSchema = z.object({
  id: z.number().int().positive(),
  generic_name: z.string(),
  brand_name: z.string().nullable(),
  category: z.string().nullable(),
  dosage_form: z.string().nullable(),
  dosage_strength: z.string().nullable(),
  unit: z.string(),
  reorder_threshold: z.number().int().min(0),
  description: z.string().nullable(),
  quantity_on_hand: z.number().int().min(0),
  low_stock: z.boolean(),
  earliest_expiry: z.string().nullable(),
  archived: z.boolean(),
  created_at: z.string(),
  last_movement: medicineLastMovementSchema.nullable(),
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
  // Free-text notes (indications, storage, supplier quirks, etc.). Optional
  // so existing forms that omit it keep working — backend accepts null.
  // 2000 chars mirrors `MedicineController::create()` validation.
  description: z.string().max(2000).optional(),
});
export type CreateMedicineInput = z.infer<typeof createMedicineSchema>;

/**
 * Update a medicine's catalog row. Locked to the reorder threshold —
 * every other catalog field is read-only after creation so the batch
 * ledger keeps describing the same product.
 */
export const updateMedicineSchema = z.object({
  reorder_threshold: z.number().int().min(0),
});
export type UpdateMedicineInput = z.infer<typeof updateMedicineSchema>;

/**
 * Receive a lot. Quantity is OPTIONAL — the backend defaults it to the
 * reorder's `requested_quantity`, but the operator can lower it when
 * only a partial delivery arrived. `shortage_note` is the reason for
 * the shortfall, surfaced in the ledger so the next reorder can be
 * informed.
 */
export const addBatchSchema = z.object({
  batch_number: z.string().min(1, 'Required').max(100),
  expiration_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'YYYY-MM-DD'),
  supplier: z.string().max(200).optional(),
  note: z.string().max(255).optional(),
  quantity: z.number().int().min(1).optional(),
  shortage_note: z.string().max(255).optional(),
});
export type AddBatchInput = z.infer<typeof addBatchSchema>;

/**
 * Dispense against an OPEN encounter (panel revision): every dispense
 * is anchored to the actual clinic visit, so the ledger records who
 * the stock went to. `encounter_id` is required by the backend.
 */
export const dispenseSchema = z.object({
  quantity: z.number().int().min(1, 'Must be at least 1'),
  encounter_id: z.number().int().positive({ message: 'Select the open encounter' }),
  note: z.string().max(255).optional(),
});
export type DispenseInput = z.infer<typeof dispenseSchema>;

/**
 * One row of the medicine ledger (in/out with running balance). `qty_in`
 * / `qty_out` are mutually exclusive; `balance_after` is the on-hand
 * total right after the transaction.
 */
export const medicineTxnSchema = z.object({
  id: z.number().int().positive(),
  batch_id: z.number().int(),
  type: z.string(),
  qty_in: z.number().int().nullable(),
  qty_out: z.number().int().nullable(),
  balance_after: z.number().int().nullable(),
  reference_type: z.string().nullable(),
  reference_id: z.number().int().nullable(),
  user_email: z.string().nullable(),
  note: z.string().nullable(),
  created_at: z.string(),
});
export type MedicineTxn = z.infer<typeof medicineTxnSchema>;

/**
 * A batch written off as expired/recalled, joined with the parent
 * medicine + the ledger's written-off quantity/timestamp. Returned by
 * `GET /clinic/medicines/expired?days=N`.
 */
export const writtenOffBatchSchema = z.object({
  id: z.number().int().positive(),
  medicine_id: z.number().int().positive(),
  batch_number: z.string(),
  quantity_received: z.number().int().min(0),
  expiration_date: z.string(),
  supplier: z.string().nullable(),
  status: z.enum(['active', 'depleted', 'expired', 'recalled']),
  written_off: z.number().int().nullable(),
  written_off_at: z.string().nullable(),
  generic_name: z.string(),
  unit: z.string(),
});
export type WrittenOffBatch = z.infer<typeof writtenOffBatchSchema>;

/**
 * Catalogue-wide dispensing usage over the trailing window. Returned
 * by `GET /clinic/medicines/usage-summary?days=N`.
 */
export const medicineUsageSummarySchema = z.object({
  period_days: z.number().int(),
  units_dispensed: z.number().int(),
  medicines_with_usage: z.number().int(),
  avg_daily_units: z.number(),
});
export type MedicineUsageSummary = z.infer<typeof medicineUsageSummarySchema>;

/**
 * Expiring-batch insight shape — a batch row joined with the parent
 * medicine's `generic_name` + `unit`. Returned by
 * `GET /clinic/medicines/expiring?days=N`.
 */
export const expiringBatchSchema = z.object({
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
  // Joined from clinic_medicines:
  generic_name: z.string(),
  unit: z.string(),
});
export type ExpiringBatch = z.infer<typeof expiringBatchSchema>;

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
