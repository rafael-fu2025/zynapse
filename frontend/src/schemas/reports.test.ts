import { describe, expect, it } from 'vitest';
import { reportSummarySchema } from './reports';

/**
 * The overview strip must tolerate the backend OMITTING unit-gated modules
 * (ReportService::MODULE_EXTRA_PERMISSIONS filters the summary payload per
 * caller) — a required key here would fail the whole zod parse and blank
 * the overview for clinic/BMG admins. Regression: 2026-10-03, where adding
 * the facilities gate without making the schema key optional broke the
 * overview for every clinic admin.
 */

const FULL_SUMMARY = {
  range: { start: '2026-08-01', end: '2026-10-03' },
  previous_range: { start: '2026-04-28', end: '2026-07-31' },
  snapshot_at: '2026-10-03T00:00:00Z',
  clinic: { encounters: 412, previous_encounters: 388, encounters_delta_pct: 6.2 },
  counselling: { appointments: 57, previous_appointments: 61, appointments_delta_pct: -6.6, sessions: 48 },
  inventory: {
    active_batches: 34,
    dispensed_qty: 1204,
    previous_dispensed_qty: 1101,
    dispensed_delta_pct: 9.4,
    equipment_for_replacement: 3,
  },
  referrals: { created: 29, previous_created: 31, created_delta_pct: -6.5 },
  facilities: { completed_batches: 12, previous_completed_batches: 10, completed_delta_pct: 20 },
};

describe('reportSummarySchema', () => {
  it('parses a complete summary with every module present', () => {
    expect(reportSummarySchema.parse(FULL_SUMMARY)).toMatchObject({ clinic: { encounters: 412 } });
  });

  it('accepts a summary with the unit-gated modules omitted (clinic admin view)', () => {
    const clinicSummary: Record<string, unknown> = { ...FULL_SUMMARY };
    delete clinicSummary['counselling'];
    delete clinicSummary['facilities'];
    const parsed = reportSummarySchema.parse(clinicSummary);
    expect(parsed.clinic).toBeDefined();
    expect(parsed.inventory).toBeDefined();
    expect(parsed.counselling).toBeUndefined();
    expect(parsed.facilities).toBeUndefined();
  });

  it('validates a gated module payload when the module is present', () => {
    const guidanceSummary = { ...FULL_SUMMARY };
    delete (guidanceSummary as Record<string, unknown>)['facilities'];
    expect(reportSummarySchema.parse(guidanceSummary).counselling).toMatchObject({ appointments: 57 });
  });
});
