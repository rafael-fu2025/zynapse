/**
 * Zod schemas — Reports module (Phase 18).
 * Mirrors backend `ReportService` payloads.
 */
import { z } from 'zod';

export const REPORT_MODULES = ['clinic', 'counselling', 'inventory'] as const;
export type ReportModule = (typeof REPORT_MODULES)[number];

const rangeSchema = z.object({ start: z.string(), end: z.string() });
export type ReportRange = z.infer<typeof rangeSchema>;

const countRow = z.object({ cnt: z.number().int() }).passthrough();

export const reportSummarySchema = z.object({
  range: rangeSchema,
  clinic: z.object({ encounters: z.number().int(), checkins: z.number().int() }),
  counselling: z.object({ appointments: z.number().int(), sessions: z.number().int() }),
  inventory: z.object({ active_batches: z.number().int(), dispensed_qty: z.number().int() }),
  referrals: z.object({ created: z.number().int() }),
});
export type ReportSummary = z.infer<typeof reportSummarySchema>;

export const clinicReportSchema = z.object({
  range: rangeSchema,
  total_encounters: z.number().int(),
  status_breakdown: z.array(countRow.extend({ status: z.string() })),
  daily_trend: z.array(countRow.extend({ day: z.string() })),
  top_complaints: z.array(countRow.extend({ chief_complaint: z.string() })),
  checkin_outcomes: z.array(countRow.extend({ outcome: z.string() })),
  referral_flows: z.array(
    countRow.extend({ source_module: z.string(), target_module: z.string(), status: z.string() }),
  ),
});
export type ClinicReport = z.infer<typeof clinicReportSchema>;

export const counsellingReportSchema = z.object({
  range: rangeSchema,
  total_appointments: z.number().int(),
  status_breakdown: z.array(countRow.extend({ status: z.string() })),
  type_breakdown: z.array(countRow.extend({ type: z.string() })),
  daily_trend: z.array(countRow.extend({ day: z.string() })),
  no_show_count: z.number().int(),
  no_show_rate: z.number(),
  sessions_opened: z.number().int(),
});
export type CounsellingReport = z.infer<typeof counsellingReportSchema>;

export const inventoryReportSchema = z.object({
  range: rangeSchema,
  total_medicines: z.number().int(),
  low_stock: z.array(
    z.object({
      id: z.number().int(),
      generic_name: z.string(),
      brand_name: z.string().nullable(),
      unit: z.string(),
      reorder_threshold: z.number().int(),
      total_stock: z.coerce.number().int(),
    }),
  ),
  expiring: z.array(
    z.object({
      id: z.number().int(),
      batch_number: z.string(),
      quantity_remaining: z.number().int(),
      expiration_date: z.string(),
      generic_name: z.string(),
      brand_name: z.string().nullable(),
      unit: z.string(),
    }),
  ),
  dispensing_trend: z.array(z.object({ day: z.string(), qty: z.number().int() })),
  total_dispensed: z.number().int(),
  top_dispensed: z.array(
    z.object({
      generic_name: z.string(),
      brand_name: z.string().nullable(),
      unit: z.string(),
      qty: z.number().int(),
    }),
  ),
});
export type InventoryReport = z.infer<typeof inventoryReportSchema>;

// Saved & generated reports (Phase P6).
export const reportConfigSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  module: z.string(),
  report_type: z.string(),
  parameters: z.any(),
  schedule_cron: z.string().nullable(),
  is_active: z.boolean(),
  created_at: z.string(),
});
export type ReportConfig = z.infer<typeof reportConfigSchema>;

export const generatedReportSchema = z.object({
  id: z.number().int().positive(),
  config_id: z.number().int().nullable(),
  module: z.string(),
  file_path: z.string(),
  format: z.string(),
  row_count: z.number().int().nullable(),
  parameters_used: z.any(),
  ai_summary: z.string().nullable(),
  generated_at: z.string(),
});
export type GeneratedReport = z.infer<typeof generatedReportSchema>;
