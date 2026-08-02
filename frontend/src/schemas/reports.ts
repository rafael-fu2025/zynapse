import { z } from 'zod';
import { differenceInCalendarDays, format, isValid, parseISO } from 'date-fns';

export const REPORT_MODULES = ['clinic', 'counselling', 'inventory', 'referrals', 'facilities'] as const;
export const reportModuleSchema = z.enum(REPORT_MODULES);
export type ReportModule = z.infer<typeof reportModuleSchema>;

const reportDateSchema = z.string()
  .regex(/^\d{4}-\d{2}-\d{2}$/)
  .refine((value) => {
    const parsed = parseISO(value);
    return isValid(parsed) && format(parsed, 'yyyy-MM-dd') === value;
  }, 'Date must be a real calendar date.');

function validateReportRange(start: string, end: string, context: z.RefinementCtx): void {
  const days = differenceInCalendarDays(parseISO(end), parseISO(start));
  if (days < 0) {
    context.addIssue({ code: z.ZodIssueCode.custom, path: ['start'], message: 'Start date must be on or before end date.' });
  } else if (days >= 366) {
    context.addIssue({ code: z.ZodIssueCode.custom, path: ['end'], message: 'Date range cannot exceed 366 days.' });
  }
}

const reportRangeObjectSchema = z.object({
  start: reportDateSchema,
  end: reportDateSchema,
});
export const reportRangeSchema = reportRangeObjectSchema.superRefine(
  ({ start, end }, context) => validateReportRange(start, end, context),
);
export type ReportRange = z.infer<typeof reportRangeSchema>;

const deltaSchema = z.number().nullable();
const countRow = z.object({ cnt: z.number().int() }).passthrough();

export const reportSummarySchema = z.object({
  range: reportRangeSchema,
  previous_range: reportRangeSchema,
  snapshot_at: z.string(),
  clinic: z.object({
    encounters: z.number().int(),
    previous_encounters: z.number().int(),
    encounters_delta_pct: deltaSchema,
    checkins: z.number().int(),
  }),
  counselling: z.object({
    appointments: z.number().int(),
    previous_appointments: z.number().int(),
    appointments_delta_pct: deltaSchema,
    sessions: z.number().int(),
  }),
  inventory: z.object({
    active_batches: z.number().int(),
    dispensed_qty: z.number().int(),
    previous_dispensed_qty: z.number().int(),
    dispensed_delta_pct: deltaSchema,
  }),
  referrals: z.object({
    created: z.number().int(),
    previous_created: z.number().int(),
    created_delta_pct: deltaSchema,
  }),
  facilities: z.object({
    completed_batches: z.number().int(),
    previous_completed_batches: z.number().int(),
    completed_delta_pct: deltaSchema,
  }),
});
export type ReportSummary = z.infer<typeof reportSummarySchema>;

export const clinicReportSchema = z.object({
  range: reportRangeSchema,
  total_encounters: z.number().int(),
  status_breakdown: z.array(countRow.extend({ status: z.string() })),
  daily_trend: z.array(countRow.extend({ day: z.string() })),
  complaint_categories: z.array(countRow.extend({ category: z.string() })),
  checkin_outcomes: z.array(countRow.extend({ outcome: z.string() })),
  referral_flows: z.array(
    countRow.extend({ source_module: z.string(), target_module: z.string(), status: z.string() }),
  ),
});
export type ClinicReport = z.infer<typeof clinicReportSchema>;

export const counsellingReportSchema = z.object({
  range: reportRangeSchema,
  total_appointments: z.number().int(),
  status_breakdown: z.array(countRow.extend({ status: z.string() })),
  type_breakdown: z.array(countRow.extend({ type: z.string() })),
  daily_trend: z.array(countRow.extend({ day: z.string() })),
  no_show_count: z.number().int(),
  no_show_rate: z.number(),
  sessions_opened: z.number().int(),
});
export type CounsellingReport = z.infer<typeof counsellingReportSchema>;

const batchSchema = z.object({
  id: z.number().int(),
  batch_number: z.string(),
  quantity_remaining: z.number().int(),
  expiration_date: z.string(),
  generic_name: z.string(),
  brand_name: z.string().nullable(),
  unit: z.string(),
});

export const inventoryReportSchema = z.object({
  range: reportRangeSchema,
  snapshot_at: z.string(),
  total_medicines: z.number().int(),
  low_stock: z.array(z.object({
    id: z.number().int(),
    generic_name: z.string(),
    brand_name: z.string().nullable(),
    unit: z.string(),
    reorder_threshold: z.number().int(),
    total_stock: z.coerce.number().int(),
  })),
  expired: z.array(batchSchema),
  expiring: z.array(batchSchema),
  dispensing_trend: z.array(z.object({ day: z.string(), qty: z.number().int() })),
  total_dispensed: z.number().int(),
  top_dispensed: z.array(z.object({
    generic_name: z.string(),
    brand_name: z.string().nullable(),
    unit: z.string(),
    qty: z.number().int(),
  })),
});
export type InventoryReport = z.infer<typeof inventoryReportSchema>;

export const referralReportSchema = z.object({
  range: reportRangeSchema,
  total_referrals: z.number().int(),
  closed_count: z.number().int(),
  closed_rate: z.number(),
  status_breakdown: z.array(countRow.extend({ status: z.string() })),
  flow_breakdown: z.array(countRow.extend({ source_module: z.string(), target_module: z.string() })),
  daily_trend: z.array(countRow.extend({ day: z.string() })),
});
export type ReferralReport = z.infer<typeof referralReportSchema>;

export const facilitiesReportSchema = z.object({
  range: reportRangeSchema,
  total_batches: z.number().int(),
  completed_batches: z.number().int(),
  input_kg: z.number(),
  output_kg: z.number(),
  yield_rate: z.number(),
  status_breakdown: z.array(countRow.extend({ status: z.string() })),
  daily_trend: z.array(countRow.extend({ day: z.string() })),
  category_breakdown: z.array(countRow.extend({ category: z.string() })),
});
export type FacilitiesReport = z.infer<typeof facilitiesReportSchema>;

const reportParametersObjectSchema = z.object({
  range_mode: z.literal('fixed').default('fixed'),
  start: reportDateSchema,
  end: reportDateSchema,
  summarize: z.boolean(),
});
export const reportParametersSchema = reportParametersObjectSchema.superRefine(
  ({ start, end }, context) => validateReportRange(start, end, context),
);
export type ReportParameters = z.infer<typeof reportParametersSchema>;

export const reportPaginationSchema = z.object({
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
  total: z.number().int().nonnegative(),
  total_pages: z.number().int().positive(),
});
export type ReportPagination = z.infer<typeof reportPaginationSchema>;

export const reportConfigSchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  module: reportModuleSchema,
  report_type: z.string(),
  parameters: reportParametersSchema,
  is_active: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
});
export type ReportConfig = z.infer<typeof reportConfigSchema>;

export const reportConfigPageSchema = z.object({
  items: z.array(reportConfigSchema),
  pagination: reportPaginationSchema,
});
export type ReportConfigPage = z.infer<typeof reportConfigPageSchema>;

export const generatedReportStatusSchema = z.enum(['queued', 'processing', 'completed', 'failed', 'expired']);
export const generatedParametersSchema = reportParametersObjectSchema
  .extend({ range: reportRangeSchema })
  .superRefine(({ start, end }, context) => validateReportRange(start, end, context));
export const generatedReportSchema = z.object({
  id: z.number().int().positive(),
  config_id: z.number().int().nullable(),
  module: reportModuleSchema,
  format: z.string(),
  status: generatedReportStatusSchema,
  row_count: z.number().int().nullable(),
  parameters_used: generatedParametersSchema,
  ai_summary: z.string().nullable(),
  error_message: z.string().nullable(),
  generated_at: z.string(),
  started_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  expires_at: z.string().nullable(),
});
export type GeneratedReport = z.infer<typeof generatedReportSchema>;

export const generatedReportPageSchema = z.object({
  items: z.array(generatedReportSchema),
  pagination: reportPaginationSchema,
});
export type GeneratedReportPage = z.infer<typeof generatedReportPageSchema>;

export const reportNarrativeSchema = z.object({
  narrative: z.string(),
  generation_method: z.string(),
  model_used: z.string(),
  generated_at: z.string(),
  range: reportRangeSchema,
});
export type ReportNarrative = z.infer<typeof reportNarrativeSchema>;
