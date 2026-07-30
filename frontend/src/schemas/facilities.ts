/**
 * Zod schemas — Facilities (BMG) module.
 * Mirror the backend validation rules in `Modules\Facilities\Controllers\BmgController`.
 */
import { z } from 'zod';

export const BMG_UNIT_STATUSES = ['idle', 'processing', 'awaiting_output', 'cancelled', 'maintenance'] as const;
export type BmgUnitStatus = (typeof BMG_UNIT_STATUSES)[number];

export const bmgUnitSchema = z.object({
  id: z.number().int().positive(),
  code: z.string(),
  display_name: z.string(),
  status: z.enum(BMG_UNIT_STATUSES),
  location_code: z.string().nullable(),
  spec_capacity_kg: z.number().nullable(),
  default_category_id: z.number().int().positive().nullable().optional(),
  default_category_name: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  created_at: z.string(),
  updated_at: z.string().nullable().optional(),
  archived_at: z.string().nullable().optional(),
  active_batch_id: z.number().int().positive().nullable().optional(),
});
export type BmgUnit = z.infer<typeof bmgUnitSchema>;

// ---- Phase P5c: Drum CRUD (port of legacy bmg/drums/{create,edit,archive}) ----

/**
 * Required + optional fields for registering a new BMG unit.
 * `code` is a SLUG (lowercase, hyphen-separated — e.g. `drum-01`);
 * the create dialog auto-generates it from the name and the backend
 * normalizes + validates the same contract. `default_category_id`
 * pre-fills the waste category on a future batch.
 */
export const createUnitSchema = z.object({
  code: z
    .string()
    .min(1, 'Required')
    .max(32)
    .regex(/^[a-z0-9]+(-[a-z0-9]+)*$/, 'Lowercase letters/digits separated by hyphens (e.g. drum-01)'),
  display_name: z.string().min(1, 'Required').max(128),
  location_code: z.string().max(64).optional().or(z.literal('')),
  spec_capacity_kg: z.coerce.number().positive().optional().or(z.literal('')),
  default_category_id: z.coerce.number().int().positive().optional().or(z.literal('')),
  notes: z.string().max(512).optional().or(z.literal('')),
});
export type CreateUnitInput = z.infer<typeof createUnitSchema>;

/**
 * Mutable fields on an existing unit. `code` is intentionally NOT in
 * this schema — the legacy rule was "Drum code cannot be changed" and
 * the service enforces it server-side too. All fields are optional so
 * the form can PATCH only the changed values.
 */
export const updateUnitSchema = z.object({
  display_name: z.string().min(1, 'Required').max(128).optional(),
  location_code: z.string().max(64).optional().or(z.literal('')),
  spec_capacity_kg: z.coerce.number().positive().optional().or(z.literal('')),
  default_category_id: z.coerce.number().int().positive().optional().or(z.literal('')),
  notes: z.string().max(512).optional().or(z.literal('')),
});
export type UpdateUnitInput = z.infer<typeof updateUnitSchema>;

export const bmgBatchSchema = z.object({
  id: z.number().int().positive(),
  unit_id: z.number().int().positive(),
  reference_code: z.string(),
  status: z.enum(['idle', 'processing', 'awaiting_output', 'cancelled']),
  total_input_weight_kg: z.number(),
  output_weight_kg: z.number().nullable(),
  input_items: z.array(z.object({ sku: z.string(), qty_kg: z.number() }).passthrough()),
  output_items: z.array(z.object({ sku: z.string(), qty_kg: z.number() }).passthrough()).nullable(),
  started_at: z.string(),
  awaiting_output_at: z.string().nullable(),
  finished_at: z.string().nullable(),
  cancelled_at: z.string().nullable(),
});
export type BmgBatch = z.infer<typeof bmgBatchSchema>;

/**
 * Start a batch with its segregated waste composition (panel
 * revision): one row per waste category with the loaded weight.
 * Component weights must add up to `total_input_weight_kg` — the
 * backend re-checks with a ±0.01 kg tolerance.
 */
export const startBatchSchema = z
  .object({
    total_input_weight_kg: z.number().positive(),
    composition: z
      .array(
        z.object({
          category_id: z.number().int().positive(),
          weight_kg: z.number().positive(),
        }),
      )
      .min(1, 'Add at least one waste component'),
  })
  .refine(
    (v) => Math.abs(v.composition.reduce((s, c) => s + c.weight_kg, 0) - v.total_input_weight_kg) <= 0.01,
    { message: 'Component weights must add up to the total input weight.', path: ['composition'] },
  );
export type StartBatchInput = z.infer<typeof startBatchSchema>;

export const recordOutputSchema = z.object({
  output_weight_kg: z.number().positive(),
  output_items: z
    .array(
      z.object({
        sku: z.string().min(1).max(64),
        qty_kg: z.number().positive(),
      }),
    )
    .min(1),
});
export type RecordOutputInput = z.infer<typeof recordOutputSchema>;

export const cancelBatchSchema = z.object({
  reason_code: z.string().min(1).max(64),
});
export type CancelBatchInput = z.infer<typeof cancelBatchSchema>;

export const bmgBatchPageSchema = z.object({
  data: z.array(bmgUnitSchema),
  next: z.string().nullable().optional(),
});
export type BmgUnitsPage = z.infer<typeof bmgBatchPageSchema>;
export type BmgBatchPage = z.infer<typeof bmgBatchSchema>;

export const MOISTURE_LEVELS = ['low', 'normal', 'high'] as const;
export type MoistureLevel = (typeof MOISTURE_LEVELS)[number];

export const processLogSchema = z.object({
  id: z.number().int().positive(),
  batch_id: z.number().int().positive(),
  log_date: z.string(),
  observation_note: z.string().nullable(),
  temperature_celsius: z.number().nullable(),
  moisture_level: z.enum(MOISTURE_LEVELS).nullable(),
  recorded_by_user_id: z.number().int().positive(),
  created_at: z.string(),
});
export type ProcessLog = z.infer<typeof processLogSchema>;

export const addProcessLogSchema = z.object({
  observation_note: z.string().max(1000).optional().or(z.literal('')),
  temperature_celsius: z.string().regex(/^-?\d+(\.\d+)?$/, 'Numeric °C.').optional().or(z.literal('')),
  moisture_level: z.enum(MOISTURE_LEVELS).optional(),
});
export type AddProcessLogInput = z.infer<typeof addProcessLogSchema>;

// ---- Phase P4: waste categories, structured I/O, analytics ----------

export const wasteCategorySchema = z.object({
  id: z.number().int().positive(),
  code: z.string(),
  name: z.string(),
  description: z.string().nullable(),
  expected_yield_pct: z.number().nullable(),
  reference_duration_days: z.number().int().nullable(),
  // Panel revision: expected days derived from validated multi-trial
  // history; `reference_duration_days` is only the manual fallback.
  historical_avg_days: z.number().nullable(),
  sample_count: z.number().int().nonnegative(),
  expected_days: z.number().int().nullable(),
  is_active: z.boolean(),
});
export type WasteCategory = z.infer<typeof wasteCategorySchema>;

export const createWasteCategorySchema = z.object({
  code: z
    .string()
    .min(1, 'Required')
    .max(50)
    .regex(/^[a-z0-9]+(-[a-z0-9]+)*$/, 'Lowercase letters/digits separated by hyphens (e.g. food-waste-meat)'),
  name: z.string().min(1, 'Required').max(100),
  expected_yield_pct: z.coerce.number().min(0).max(100).optional(),
  reference_duration_days: z.coerce.number().int().positive().optional(),
});
export type CreateWasteCategoryInput = z.infer<typeof createWasteCategorySchema>;

/**
 * Mutable fields on an existing waste category. `code` is intentionally
 * NOT in this schema — the legacy rule was that the code is the
 * stable identifier and the service enforces immutability too. All
 * fields optional so the form can PATCH only the changed values.
 */
export const updateWasteCategorySchema = z.object({
  name: z.string().min(1, 'Required').max(100).optional(),
  expected_yield_pct: z.coerce.number().min(0).max(100).optional().or(z.literal('')),
  reference_duration_days: z.coerce.number().int().positive().optional().or(z.literal('')),
  is_active: z.boolean().optional(),
});
export type UpdateWasteCategoryInput = z.infer<typeof updateWasteCategorySchema>;

export const OUTPUT_GRADES = ['excellent', 'good', 'fair'] as const;

export const batchCompositionRowSchema = z.object({
  category_id: z.number().int().positive(),
  category_name: z.string(),
  weight_kg: z.number(),
  ratio_pct: z.number().nullable(),
  expected_days: z.number().int().nullable(),
  sample_count: z.number().int().nonnegative(),
});
export type BatchCompositionRow = z.infer<typeof batchCompositionRowSchema>;

export const batchAnalyticsSchema = z.object({
  batch_id: z.number().int().positive(),
  input_kg: z.number(),
  output_kg: z.number(),
  yield_pct: z.number(),
  yield_class: z.string(),
  mass_reduction_pct: z.number(),
  expected_yield_pct: z.number().nullable(),
  category_name: z.string().nullable(),
  reference_duration_days: z.number().int().nullable(),
  // Mix-weighted expected duration (historical avg per category,
  // weighted by each component's share of the drum's load).
  expected_days: z.number().int().nullable(),
  composition: z.array(batchCompositionRowSchema),
  expected_completion_date: z.string().nullable(),
  days_until_expected: z.number().int().nullable(),
  progress_pct: z.number().int().nullable(),
});
export type BatchAnalytics = z.infer<typeof batchAnalyticsSchema>;

// ---- Phase P5b: "Processing Drums" dashboard feed -------------------

/**
 * One card in the "Processing Drums" widget. The backend joins the active
 * batch to its unit and (optionally) waste category, then enriches the row
 * with `days_active`, `expected_completion_date`, `days_until_expected`,
 * and `progress_pct` (all deterministic via `App\Services\Analytics\BmgAnalytics`).
 */
export const activeBatchSchema = z.object({
  batch_id: z.number().int().positive(),
  batch_code: z.string(),
  batch_status: z.enum(['processing', 'awaiting_output']),
  unit_id: z.number().int().positive(),
  unit_code: z.string(),
  unit_name: z.string(),
  unit_location: z.string().nullable(),
  category_name: z.string().nullable(),
  input_kg: z.number(),
  output_kg: z.number().nullable(),
  started_at: z.string(),
  days_active: z.number().int().nonnegative(),
  reference_duration_days: z.number().int().nullable(),
  // Effective duration for THIS drum's mix (weighted by composition).
  expected_days: z.number().int().nullable(),
  expected_completion_date: z.string().nullable(),
  days_until_expected: z.number().int().nullable(),
  progress_pct: z.number().int().min(0).max(100),
});
export type ActiveBatch = z.infer<typeof activeBatchSchema>;
