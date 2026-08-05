/**
 * Zod schemas — Facilities (BMG) module.
 * Mirror the backend validation rules in `Modules\Facilities\Controllers\BmgController`.
 */
import { z } from 'zod';

export const BMG_UNIT_STATUSES = ['idle', 'processing', 'awaiting_output', 'curing', 'cancelled', 'maintenance'] as const;
export type BmgUnitStatus = (typeof BMG_UNIT_STATUSES)[number];

export const BMG_BATCH_STATUSES = [
  'idle',
  'processing',
  'awaiting_output',
  'curing',
  'cancelled',
  'released',
] as const;
export type BmgBatchStatus = (typeof BMG_BATCH_STATUSES)[number];

export const BMG_ALERT_SEVERITIES = ['info', 'warning', 'critical'] as const;
export type BmgAlertSeverity = (typeof BMG_ALERT_SEVERITIES)[number];

// ---- Audit fixes (2026-08-05): final QA gate, process events, SOPs ----

export const BMG_QUALITY_GRADES = ['excellent', 'good', 'fair'] as const;
export type BmgQualityGrade = (typeof BMG_QUALITY_GRADES)[number];

export const BMG_MATURITY_LEVELS = ['mature', 'maturing', 'immature'] as const;
export type BmgMaturityLevel = (typeof BMG_MATURITY_LEVELS)[number];

/** Process-log event types — records WHAT was done, not just a note. */
export const BMG_PROCESS_EVENT_TYPES = [
  'observation',
  'turning',
  'aeration',
  'moisture_adjustment',
  'other',
] as const;
export type BmgProcessEventType = (typeof BMG_PROCESS_EVENT_TYPES)[number];

export const BMG_LOSS_CATEGORIES = [
  'evaporation',
  'off_gas',
  'sampling',
  'spill',
  'cleaning',
  'mechanical_holdup',
  'other',
] as const;
export type BmgLossCategory = (typeof BMG_LOSS_CATEGORIES)[number];

export const BMG_ALERT_CODES = [
  'TEMP_PFRP_LOW',
  'TEMP_PFRP_HIGH',
  'MOISTURE_HIGH',
  'STALLED',
  'OXYGEN_OUT',
] as const;
export type BmgAlertCode = (typeof BMG_ALERT_CODES)[number];

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
  // Audit #8: how full the drum is vs its spec capacity.
  active_batch_weight_kg: z.number().nullable().optional(),
  utilization_pct: z.number().int().min(0).optional(),
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
  status: z.enum(BMG_BATCH_STATUSES),
  total_input_weight_kg: z.number(),
  output_weight_kg: z.number().nullable(),
  input_items: z.array(z.object({ sku: z.string(), qty_kg: z.number() }).passthrough()),
  output_items: z.array(z.object({ sku: z.string(), qty_kg: z.number() }).passthrough()).nullable(),
  started_at: z.string(),
  awaiting_output_at: z.string().nullable(),
  finished_at: z.string().nullable(),
  cancelled_at: z.string().nullable(),
  // Audit #4: final QA gate fields (set when released).
  released_at: z.string().nullable().optional(),
  released_by_user_id: z.number().int().positive().nullable().optional(),
  quality_grade: z.enum(BMG_QUALITY_GRADES).nullable().optional(),
  maturity_level: z.enum(BMG_MATURITY_LEVELS).nullable().optional(),
});
export type BmgBatch = z.infer<typeof bmgBatchSchema>;

/**
 * Release a finished/cured batch for use — the final quality/maturity
 * gate. `quality_grade` + `maturity_level` become the batch's
 * certificate fields.
 */
export const releaseBatchSchema = z.object({
  quality_grade: z.enum(BMG_QUALITY_GRADES),
  maturity_level: z.enum(BMG_MATURITY_LEVELS),
  notes: z.string().max(512).optional().or(z.literal('')),
});
export type ReleaseBatchInput = z.infer<typeof releaseBatchSchema>;

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
  // Audit #6: event_type tells WHAT was done (turning, aeration, …).
  event_type: z.enum(BMG_PROCESS_EVENT_TYPES).optional(),
  observation_note: z.string().nullable(),
  temperature_celsius: z.number().nullable(),
  moisture_level: z.enum(MOISTURE_LEVELS).nullable(),
  // Tier 2.2 observability fields — surfaced on the timeline alongside
  // temp/moisture so the operator can confirm sensor provenance at a
  // glance. All optional because older logs (pre-migration) won't have them.
  oxygen_pct: z.number().nullable().optional(),
  device_id: z.string().nullable().optional(),
  calibration_status: z.enum(['ok', 'due', 'overdue']).nullable().optional(),
  recorded_by_user_id: z.number().int().positive(),
  created_at: z.string(),
});
export type ProcessLog = z.infer<typeof processLogSchema>;

export const addProcessLogSchema = z.object({
  observation_note: z.string().max(1000).optional().or(z.literal('')),
  event_type: z.enum(BMG_PROCESS_EVENT_TYPES).optional(),
  temperature_celsius: z.string().regex(/^-?\d+(\.\d+)?$/, 'Numeric °C.').optional().or(z.literal('')),
  moisture_level: z.enum(MOISTURE_LEVELS).optional(),
  oxygen_pct: z.string().regex(/^-?\d+(\.\d+)?$/, 'Numeric %.').optional().or(z.literal('')),
  device_id: z.string().max(64).optional().or(z.literal('')),
  calibration_status: z.enum(['ok', 'due', 'overdue']).optional(),
});
export type AddProcessLogInput = z.infer<typeof addProcessLogSchema>;

/**
 * Move an AwaitingOutput batch into Curing. The operator may optionally
 * declare the WIP still inside the drum (residue); the value is
 * surfaced on the batch row for QA close.
 */
export const moveToCuringSchema = z.object({
  accumulated_in_process_kg: z
    .union([z.coerce.number().nonnegative(), z.literal('')])
    .optional(),
});
export type MoveToCuringInput = z.infer<typeof moveToCuringSchema>;

/**
 * Append a single feedstock component to an active batch. Optional
 * C:N / bulk-density / pH fields support industry-grade
 * characterization without blocking the simpler "just record weight"
 * workflow.
 */
export const addBatchInputSchema = z.object({
  weight_kg: z.coerce.number().positive(),
  cn_ratio: z.coerce.number().min(0.1).max(200).optional().or(z.literal('')),
  bulk_density_kg_per_m3: z.coerce.number().positive().optional().or(z.literal('')),
  ph: z.coerce.number().min(0).max(14).optional().or(z.literal('')),
  note: z.string().max(255).optional().or(z.literal('')),
});
export type AddBatchInputInput = z.infer<typeof addBatchInputSchema>;

/**
 * Record a categorised mass loss against an active batch. The backend
 * recomputes `total_loss_kg` in the same transaction.
 */
export const addBatchLossSchema = z.object({
  category_code: z.enum(BMG_LOSS_CATEGORIES),
  weight_kg: z.coerce.number().positive(),
  note: z.string().max(255).optional().or(z.literal('')),
});
export type AddBatchLossInput = z.infer<typeof addBatchLossSchema>;

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
  batch_status: z.enum(['processing', 'awaiting_output', 'curing']),
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

// ---- Tier 3.3: SPC alert engine -------------------------------------

export const bmgAlertSchema = z.object({
  id: z.number().int().positive(),
  batch_id: z.number().int().positive(),
  code: z.enum(BMG_ALERT_CODES),
  severity: z.enum(BMG_ALERT_SEVERITIES),
  message: z.string(),
  triggered_at: z.string(),
  acknowledged_at: z.string().nullable(),
  acknowledged_by_user_id: z.number().int().positive().nullable(),
});
export type BmgAlert = z.infer<typeof bmgAlertSchema>;

// ---- Audit fixes (2026-08-05) ---------------------------------------

/**
 * PFRP compliance summary — the batch certificate data. `pfrp_met`
 * reflects whether the process-log timeline shows a pathogen-reduction
 * window (consecutive days ≥55 °C or a peak ≥65 °C); the mass-balance
 * block reconciles input against output + losses + in-process.
 */
export const batchComplianceSchema = z.object({
  batch_id: z.number().int().positive(),
  reference_code: z.string(),
  status: z.string(),
  started_at: z.string(),
  finished_at: z.string().nullable(),
  released_at: z.string().nullable(),
  cancelled_at: z.string().nullable(),
  thermophilic_days: z.number().int().nonnegative(),
  max_temperature_c: z.number().nullable(),
  consecutive_pfrp_days: z.number().int().nonnegative(),
  pfrp_met: z.boolean(),
  input_kg: z.number(),
  output_kg: z.number(),
  loss_kg: z.number(),
  in_process_kg: z.number(),
  unaccounted_kg: z.number(),
  yield_pct: z.number().nullable(),
  quality_grade: z.enum(BMG_QUALITY_GRADES).nullable(),
  maturity_level: z.enum(BMG_MATURITY_LEVELS).nullable(),
});
export type BatchCompliance = z.infer<typeof batchComplianceSchema>;

/** Weighted feedstock C:N blend for a batch. */
export const blendCnSchema = z.object({
  blend_cn: z.number().nullable(),
  n_inputs: z.number().int().nonnegative(),
  status: z.enum(['unknown', 'low', 'optimal', 'high']),
  note: z.string().nullable(),
});
export type BlendCn = z.infer<typeof blendCnSchema>;

/** One row in the global open-alert feed (dashboard at-risk widget). */
export const openAlertSchema = z.object({
  alert_id: z.number().int().positive(),
  code: z.string(),
  severity: z.enum(BMG_ALERT_SEVERITIES),
  message: z.string(),
  triggered_at: z.string(),
  acknowledged_at: z.string().nullable(),
  batch_id: z.number().int().positive(),
  reference_code: z.string(),
  batch_status: z.string(),
  unit_id: z.number().int().positive().nullable(),
  unit_code: z.string().nullable(),
  unit_name: z.string().nullable(),
});
export type OpenAlert = z.infer<typeof openAlertSchema>;

/** One row in the batch-history listing (terminal + historical). */
export const batchHistoryItemSchema = z.object({
  id: z.number().int().positive(),
  reference_code: z.string(),
  status: z.string(),
  unit_id: z.number().int().positive(),
  unit_code: z.string(),
  unit_name: z.string(),
  category_name: z.string().nullable(),
  total_input_weight_kg: z.number(),
  output_weight_kg: z.number().nullable(),
  total_loss_kg: z.number().nullable(),
  quality_grade: z.enum(BMG_QUALITY_GRADES).nullable(),
  maturity_level: z.enum(BMG_MATURITY_LEVELS).nullable(),
  started_at: z.string(),
  finished_at: z.string().nullable(),
  released_at: z.string().nullable(),
  cancelled_at: z.string().nullable(),
});
export type BatchHistoryItem = z.infer<typeof batchHistoryItemSchema>;

export const batchHistoryPageSchema = z.object({
  data: z.array(batchHistoryItemSchema),
  next: z.string().nullable().optional(),
});
export type BatchHistoryPage = z.infer<typeof batchHistoryPageSchema>;

/** Training / SOP register document. */
export const sopDocumentSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  document_ref: z.string(),
  category: z.string().nullable(),
  version: z.string().nullable(),
  owner_user_id: z.number().int().positive().nullable(),
  owner_name: z.string().nullable(),
  notes: z.string().nullable(),
  is_active: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
});
export type SopDocument = z.infer<typeof sopDocumentSchema>;

export const createSopDocumentSchema = z.object({
  title: z.string().min(1, 'Required').max(200),
  document_ref: z.string().min(1, 'Required').max(64),
  category: z.string().max(64).optional().or(z.literal('')),
  version: z.string().max(32).optional().or(z.literal('')),
  owner_user_id: z.coerce.number().int().positive().optional().or(z.literal('')),
  notes: z.string().max(2000).optional().or(z.literal('')),
});
export type CreateSopDocumentInput = z.infer<typeof createSopDocumentSchema>;

/** Actual vs expected yield/duration per waste category. */
export const categoryDeviationSchema = z.object({
  category_id: z.number().int().positive(),
  code: z.string(),
  name: z.string(),
  batch_count: z.number().int().nonnegative(),
  actual_yield_pct: z.number().nullable(),
  expected_yield_pct: z.number().nullable(),
  yield_delta_pp: z.number().nullable(),
  actual_days: z.number().int().nullable(),
  expected_days: z.number().int().nullable(),
  days_delta: z.number().int().nullable(),
});
export type CategoryDeviation = z.infer<typeof categoryDeviationSchema>;
