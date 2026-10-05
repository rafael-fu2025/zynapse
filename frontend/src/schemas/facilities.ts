/**
 * Zod schemas — Facilities (BMG) module.
 * Mirror the backend validation rules in `Modules\Facilities\Controllers\BmgController`.
 */
import { z } from 'zod';

export const BMG_UNIT_STATUSES = ['idle', 'processing', 'awaiting_output', 'cancelled', 'maintenance'] as const;
export type BmgUnitStatus = (typeof BMG_UNIT_STATUSES)[number];

export const BMG_BATCH_STATUSES = [
  'idle',
  'processing',
  'awaiting_output',
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
  'TURNING_DUE',
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
  // In-use indicator: expected completion + progress of the active batch.
  active_batch_expected_completion_date: z.string().nullable().optional(),
  active_batch_progress_pct: z.number().int().min(0).nullable().optional(),
  // The drum's integrated ESP32 (1:1 — backend enforces via a unique
  // index). Optional so payloads fetched before the join shipped parse.
  device_id: z.number().int().positive().nullable().optional(),
  device_code: z.string().nullable().optional(),
  device_display_name: z.string().nullable().optional(),
  device_status: z.string().nullable().optional(),
  device_last_seen_at: z.string().nullable().optional(),
  // Waste categories the drum is designated for (set at drum setup;
  // startBatch may only mix from this set). Empty = unprofiled drum.
  categories: z.array(z.object({ id: z.number().int().positive(), name: z.string() })).optional(),
  // The unit houses TWO drums; spec_capacity_kg is the SUM of these.
  drum_one_capacity_kg: z.number().nullable().optional(),
  drum_two_capacity_kg: z.number().nullable().optional(),
});
export type BmgUnit = z.infer<typeof bmgUnitSchema>;

/**
 * One immutable, timestamped entry in a batch's append-only "Updates"
 * feed — output / log, plus historical `curing` rows predating that
 * state's retirement. Mirrors `BmgService::listBatchUpdates`.
 */
export const batchUpdateSchema = z.object({
  id: z.number().int().positive(),
  update_type: z.enum(['output', 'curing', 'log']),
  output_weight_kg: z.number().nullable(),
  curing_note: z.string().nullable(),
  event_type: z.string().nullable(),
  observation_note: z.string().nullable(),
  temperature_celsius: z.number().nullable(),
  moisture_level: z.string().nullable(),
  recorded_by_user_id: z.number().int().positive().nullable(),
  created_at: z.string(),
});
export type BatchUpdate = z.infer<typeof batchUpdateSchema>;

/**
 * Unified "Add update" — one action, two internal entry types. The
 * backend appends an immutable ledger row.
 */
export const addBatchUpdateSchema = z
  .object({
    update_type: z.enum(['output', 'log']),
    output_weight_kg: z.number().positive().optional(),
    event_type: z.enum(BMG_PROCESS_EVENT_TYPES).optional(),
    observation_note: z.string().max(1000).optional().or(z.literal('')),
    temperature_celsius: z.coerce.number().min(-20).max(120).optional(),
    moisture_level: z.enum(['low', 'normal', 'high']).optional(),
  })
  .refine(
    (v) =>
      v.update_type === 'output'
        ? v.output_weight_kg !== undefined
        : v.event_type !== undefined || (v.observation_note ?? '') !== '' || v.temperature_celsius !== undefined || v.moisture_level !== undefined,
    { message: 'Fill in the relevant detail for this update.', path: ['update_type'] },
  );
export type AddBatchUpdateInput = z.infer<typeof addBatchUpdateSchema>;

// ---- Phase P5c: Drum CRUD (port of legacy bmg/drums/{create,edit,archive}) ----

/**
 * Required + optional fields for registering a new BMG unit.
 * `code` is a SLUG (lowercase, hyphen-separated — e.g. `drum-01`);
 * the create dialog auto-generates it from the name and the backend
 * normalizes + validates the same contract. `device_id` is the
 * REQUIRED ESP32 integration — a drum cannot be created while every
 * registered device is already bound to another drum. The unit houses
 * TWO drums: `drum_one_capacity_kg` + `drum_two_capacity_kg` (each ≥ 4,
 * both together) and the backend stores their SUM as the unit capacity.
 * `category_ids` designates the waste categories the drum may compost.
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
  drum_one_capacity_kg: z.coerce.number().min(4, 'Each drum holds at least 4 kg.').optional().or(z.literal('')),
  drum_two_capacity_kg: z.coerce.number().min(4, 'Each drum holds at least 4 kg.').optional().or(z.literal('')),
  default_category_id: z.coerce.number().int().positive().optional().or(z.literal('')),
  category_ids: z.array(z.number().int().positive()),
  notes: z.string().max(512).optional().or(z.literal('')),
  device_id: z.coerce.number().int().positive(),
});
export type CreateUnitInput = z.infer<typeof createUnitSchema>;

/**
 * Mutable fields on an existing unit. `code` is intentionally NOT in
 * this schema — the legacy rule was "Drum code cannot be changed" and
 * the service enforces it server-side too. All fields are optional so
 * the form can PATCH only the changed values. `device_id` reassigns
 * the drum's ESP32 (or `null` unbinds it); the backend refuses a
 * device another drum already holds. `category_ids` (always sent by
 * the edit form — an empty array clears the designation) replaces the
 * drum's waste-category set; the two drum capacities arrive together
 * and the backend recomputes the unit capacity as their sum.
 */
export const updateUnitSchema = z.object({
  display_name: z.string().min(1, 'Required').max(128).optional(),
  location_code: z.string().max(64).optional().or(z.literal('')),
  spec_capacity_kg: z.coerce.number().positive().optional().or(z.literal('')),
  drum_one_capacity_kg: z.coerce.number().min(4, 'Each drum holds at least 4 kg.').optional().or(z.literal('')),
  drum_two_capacity_kg: z.coerce.number().min(4, 'Each drum holds at least 4 kg.').optional().or(z.literal('')),
  default_category_id: z.coerce.number().int().positive().optional().or(z.literal('')),
  category_ids: z.array(z.number().int().positive()).optional(),
  notes: z.string().max(512).optional().or(z.literal('')),
  device_id: z.number().int().positive().nullable().optional(),
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
 * Finish a batch — the graded release. Both QA fields are required;
 * `output_weight_kg` is the final yield recorded at finish as the
 * batch's closing output-ledger entry, optional when the run produced
 * nothing measurable.
 *
 * This is the only operator-facing terminal transition. The backend's
 * `releaseBatch` variant lands on the same `released` state but does not
 * stamp `finished_at`, so it is deliberately not modelled here.
 */
export const finishBatchSchema = z.object({
  quality_grade: z.enum(BMG_QUALITY_GRADES),
  maturity_level: z.enum(BMG_MATURITY_LEVELS),
  output_weight_kg: z.coerce.number().positive().optional(),
  notes: z.string().max(512).optional().or(z.literal('')),
});
export type FinishBatchInput = z.infer<typeof finishBatchSchema>;

/**
 * Start a batch: just the loaded weight — starting the drum IS starting
 * the batch, and the waste mix was already designated on the drum (the
 * backend derives the ETA from the drum's first designated category).
 * A structured `composition` remains accepted for API clients that
 * segregate by category; when present its weights must sum to the total.
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
      .optional(),
  })
  .refine(
    (v) =>
      !v.composition || v.composition.length === 0 ||
      Math.abs(v.composition.reduce((s, c) => s + c.weight_kg, 0) - v.total_input_weight_kg) <= 0.01,
    { message: 'Component weights must add up to the total input weight.', path: ['composition'] },
  );
export type StartBatchInput = z.infer<typeof startBatchSchema>;

export const recordOutputSchema = z.object({
  output_weight_kg: z.number().positive(),
  // SKU/qty breakdown is optional — the UI no longer collects it (it was
  // too technical for the operator/dept). Backend defaults to [].
  output_items: z
    .array(
      z.object({
        sku: z.string().min(1).max(64),
        qty_kg: z.number().positive(),
      }),
    )
    .optional(),
});
export type RecordOutputInput = z.infer<typeof recordOutputSchema>;

export const cancelBatchSchema = z.object({
  reason_code: z.string().min(1).max(64),
});
export type CancelBatchInput = z.infer<typeof cancelBatchSchema>;

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
  // Device turning-session fields (mechanized tumbler ingest). Only
  // device-reported rows carry them; manual logs stay null.
  session_uid: z.string().nullable().optional(),
  turns_count: z.number().int().positive().nullable().optional(),
  duration_seconds: z.number().int().positive().nullable().optional(),
  // RTC-stamped session window (UTC) — when the session ACTUALLY ran on
  // the device. Absent on manual logs and pre-RTC firmware reports.
  session_started_at: z.string().nullable().optional(),
  session_ended_at: z.string().nullable().optional(),
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
  // Aeration cadence: newest `turning` log for the batch. null = never
  // turned. Nullable/optional so deploys before the backend enrichment
  // keep parsing.
  last_turned_at: z.string().nullable().optional(),
  days_since_last_turning: z.number().int().nonnegative().nullable().optional(),
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

// ---- BMG devices (mechanized tumbler) --------------------------------

export const BMG_DEVICE_STATUSES = ['active', 'disabled'] as const;
export type BmgDeviceStatus = (typeof BMG_DEVICE_STATUSES)[number];

export const bmgDeviceSchema = z.object({
  id: z.number().int().positive(),
  code: z.string(),
  display_name: z.string(),
  status: z.enum(BMG_DEVICE_STATUSES),
  unit_id: z.number().int().positive().nullable().optional(),
  unit_name: z.string().nullable().optional(),
  unit_code: z.string().nullable().optional(),
  // Plaintext never returns after registration — only the prefix that
  // lets an operator identify WHICH credential a request used.
  token_prefix: z.string().nullable().optional(),
  firmware: z.string().nullable().optional(),
  last_seen_at: z.string().nullable().optional(),
  // When the device-silence watchdog last flagged this device. Lets the
  // UI keep a persistent "silent" signal instead of relying on the
  // one-shot notification the watchdog fires.
  silence_notified_at: z.string().nullable().optional(),
  created_at: z.string(),
  updated_at: z.string().nullable().optional(),
  archived_at: z.string().nullable().optional(),
});
export type BmgDevice = z.infer<typeof bmgDeviceSchema>;

/**
 * Patch a device's display name and/or drum binding. `code` and the
 * token are immutable here — the token is re-keyed through the
 * regenerate action, which deliberately forces a re-flash.
 */
export const updateDeviceSchema = z.object({
  display_name: z.string().trim().min(1).max(128).optional(),
  unit_id: z.number().int().positive().nullable().optional(),
});
export type UpdateDeviceInput = z.infer<typeof updateDeviceSchema>;

/**
 * Register a tumbler: either the chip MAC (six hex byte pairs, any
 * separator — becomes the default code, e.g. `b8-1f-3f-d7-ec-18`) or an
 * explicit slug code. The backend returns the plaintext token ONCE.
 */
export const registerDeviceSchema = z
  .object({
    mac: z
      .string()
      .trim()
      .regex(/^[0-9a-fA-F]{2}[:\-\s]?([0-9a-fA-F]{2}[:\-\s]?){5}$/, 'Six hex byte pairs, e.g. b8:1f:3f:d7:ec:18')
      .optional()
      .or(z.literal('')),
    code: z
      .string()
      .trim()
      .max(32)
      .regex(/^[a-z0-9][a-z0-9._-]{2,31}$/, '3–32 chars: lowercase letters, digits, dots, dashes, underscores')
      .optional()
      .or(z.literal('')),
    display_name: z.string().trim().max(128).optional().or(z.literal('')),
    unit_id: z.coerce.number().int().positive().optional().or(z.literal('')),
  })
  .refine((v) => (v.mac ?? '') !== '' || (v.code ?? '') !== '', {
    message: 'Provide the chip MAC or a device code.',
    path: ['mac'],
  });
export type RegisterDeviceInput = z.infer<typeof registerDeviceSchema>;
export type CategoryDeviation = z.infer<typeof categoryDeviationSchema>;
