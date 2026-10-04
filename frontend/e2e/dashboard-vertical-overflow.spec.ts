/**
 * Dashboard page-level vertical overflow regression.
 *
 * The document is the app's only vertical-scroll parent, so an element
 * that lays out past the content end (without painting anything) turns
 * into an invisible, scrollable void below the last card. Seen 2026-10:
 * the chart modules' sr-only data tables — `overflow: hidden` does not
 * apply to table boxes and `height: 1px` is a minimum on tables — grew
 * the document by their intrinsic height (the daily trend's
 * row-per-day table alone added ~4.5k px).
 *
 * Mocked (CI-safe): no live backend needed — envelope shapes per
 * helpers/auth.ts signInMocked. The report payload is deliberately
 * populated (not empty): empty charts render no sr-only table, so the
 * regression only reproduces with data.
 */
import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

async function signIn(page: Page): Promise<void> {
  await signInMocked(page, {
    id: 4,
    email: 'clinic_admin@example.test',
    username: 'clinic-admin',
    is_active: true,
    force_reset: false,
    permissions: [
      'clinic.encounters.read',
      'clinic.encounters.write',
      'clinic.queue.read',
      'clinic.queue.manage',
      'referrals.create',
      'notifications.read',
    ],
  }, { mockRefresh: true });
}

async function mockClinicReport(page: Page): Promise<void> {
  const json = (data: unknown) => ({
    contentType: 'application/json',
    body: JSON.stringify({ success: true, data, errors: [], meta: null }),
  });
  const trend = Array.from({ length: 154 }, (_, i) => ({
    day: new Date(Date.UTC(2026, 4, 3 + i)).toISOString().slice(0, 10),
    cnt: (i * 7) % 11,
  }));
  await page.route('**/api/v1/reports/clinic?**', (route) => route.fulfill(json({
    range: { start: '2026-05-03', end: '2026-10-03' },
    total_encounters: 812,
    status_breakdown: [{ status: 'closed', cnt: 500 }, { status: 'open', cnt: 200 }],
    daily_trend: trend,
    complaint_categories: [{ category: 'Fever', cnt: 120 }, { category: 'Cough', cnt: 90 }],
    referral_flows: Array.from({ length: 12 }, (_, i) => ({
      source_module: 'clinic', target_module: 'counselling', status: 'submitted' + i, cnt: i + 1,
    })),
    monthly_visits: [
      { month: '2026-05', cnt: 100 }, { month: '2026-06', cnt: 130 }, { month: '2026-07', cnt: 150 },
      { month: '2026-08', cnt: 170 }, { month: '2026-09', cnt: 160 }, { month: '2026-10', cnt: 102 },
    ],
    most_common_medications: Array.from({ length: 10 }, (_, i) => ({
      generic_name: 'Med ' + i, brand_name: null, unit: 'tab', qty: 100 - i * 5,
    })),
    patient_type_breakdown: [{ kind: 'student', cnt: 600 }, { kind: 'employee', cnt: 200 }],
    unique_patients: 420,
    avg_visits_per_patient: 1.9,
    avg_per_day: 5.3,
  })));
  await page.route('**/api/v1/notifications**', (route) => route.fulfill(json([])));
}

test('Dashboard content ends where the document ends (no scrollable void)', async ({ page }) => {
  await signIn(page);
  await mockClinicReport(page);

  await page.setViewportSize({ width: 1919, height: 945 });
  await page.goto('/', { waitUntil: 'networkidle' });
  // The clinic-role dashboard mounts the shared analytics view; its last
  // table renders only once the report query resolves.
  await expect(page.getByText('Most Used Medications')).toBeVisible({ timeout: 15_000 });

  // Nothing may extend the document past the content end — <main> carries
  // the page's own bottom padding, so scrollHeight beyond it is phantom.
  const bounds = await page.evaluate(() => ({
    scrollHeight: document.documentElement.scrollHeight,
    mainBottom: document.querySelector('main')!.getBoundingClientRect().bottom + window.scrollY,
  }));
  expect(bounds.scrollHeight).toBeLessThanOrEqual(bounds.mainBottom + 2);
});
