/**
 * Updates capture — records the working-tree changes against the live app.
 *
 * Scope rule: only screens whose contract actually changed in the working
 * tree. `feat/superadmin` is fully pushed (0 ahead / 0 behind origin), so the
 * delta to record is the uncommitted 47 modified + 13 new files, not the whole
 * product. The feature themes below are derived from `git diff --stat`.
 *
 * Roles are not interchangeable here. `/me` dispatches to the student or
 * employee portal by permission, and `hasPermission(state, '*')` redirects a
 * wildcard holder away from `/me` entirely — so a superadmin capture of the
 * student portal would silently render the dashboard instead. Portals get
 * their own accounts, and the Reports RBAC delta gets a second role because a
 * permission change is only provable from both sides of it.
 *
 * Not an assertion suite. A missing component is recorded as a skipped close-up,
 * never a failure — see capture/recorder.ts.
 */
import { expect, test } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import { Recorder, type ZoomTarget } from './recorder';
import { signInLive } from '../e2e/helpers/auth';

const OUT_ROOT = join('..', 'walkthrough-updates', 'captures');

/** Close-ups shared by most screens: the shell, then the main working area. */
const COMMON: ZoomTarget[] = [
  { label: 'main content', selectors: ['main', '[role="main"]'] },
];

test.describe.configure({ mode: 'serial' });

// ---------------------------------------------------------------------------
// 1. Staff surfaces — superadmin
//    surveys year-levels · referrals QR retirement · reports rework · shell
// ---------------------------------------------------------------------------
test('capture staff surfaces as superadmin', async ({ page }) => {
  await page.context().grantPermissions([]);
  const outDir = join(OUT_ROOT, 'superadmin');
  await mkdir(outDir, { recursive: true });
  const rec = await Recorder.create(page, outDir);
  await rec.initCdp();
  await signInLive(page, 'admin@synapse.dev');

  // -- Theme 1: survey year-level targeting -------------------------------
  // New migration 2026-10-02-000010_SurveyYearLevels.php adds surveys.year_levels
  // (JSON list of registry year levels 1-6) and seeds four paper intake forms
  // as drafts. SurveysTab.tsx +94, useSurveys.ts, schemas/surveys.ts +6.
  await rec.capture({
    id: 'surveys-admin',
    route: '/counselling/surveys',
    label: 'Surveys admin — year-level targeting',
    zooms: [
      ...COMMON,
      { label: 'survey list table', selectors: ['table', '[role="table"]'] },
      { label: 'audience column', selectors: ['th:has-text("Audience")', '[role="columnheader"]'] },
    ],
  });

  // The list view has no <form> and no tablist — the year-level control lives
  // in the builder, behind Edit. Capturing the list alone would show the
  // feature's absence rather than the feature, so open a builder.
  await rec.capture({
    id: 'surveys-builder',
    route: '/counselling/surveys',
    label: 'Survey builder — year-level targeting control',
    zooms: [
      { label: 'builder dialog', selectors: ['[role="dialog"]'] },
      // The headline control of this diff: Year 1-6 checkboxes, with
      // "none selected = every year level" spelled out beside the group.
      { label: 'year level checkboxes', selectors: [], closest: { text: 'Year levels', levels: 1 } },
      { label: 'audience selector', selectors: [], closest: { text: 'Audience', levels: 1 } },
      { label: 'open closes window', selectors: [], closest: { text: 'Open at', levels: 1 } },
    ],
    before: async (p) => {
      await p.getByRole('button', { name: /^edit$/i }).first().click();
      await p.waitForTimeout(800);
    },
  });

  // -- Theme 2: referral QR retirement -------------------------------------
  // DropReferralQrColumns migration removes qr_token_hash / qr_expires_at /
  // qr_revoked_at. ReferralsPage.tsx -302, useReferrals.ts -65,
  // schemas/referrals.ts -17: the print/scan-verify QR surfaces are gone.
  await rec.capture({
    id: 'referrals',
    route: '/referrals',
    label: 'Referrals — QR hand-off removed',
    zooms: [
      ...COMMON,
      { label: 'referral table', selectors: ['table', '[role="table"]'] },
      { label: 'referral detail', selectors: ['[role="dialog"]'], },
    ],
    before: async (p) => {
      // The retired QR column used to render a code + "scan-verify" action in
      // the detail surface, so the detail view is where the removal is visible.
      const row = p.getByRole('row').nth(1);
      await row.click().catch(() => undefined);
      await p.waitForTimeout(700);
    },
  });

  // -- Theme 3: reports rework ---------------------------------------------
  // ReportsPage +197, useReports +225, ReportPdfView +526, charts.tsx,
  // SavedReportsSection. Biggest single UI change in the diff.
  await rec.capture({
    id: 'reports',
    route: '/reports',
    label: 'Reports — analytics, saved reports, PDF view',
    zooms: [
      ...COMMON,
      { label: 'date range control', selectors: ['button:has-text("Aug")', 'button:has-text("2026")'] },
      // Measured: the card is a <section class="min-w-0 rounded-xl border
      // bg-card p-4"> two levels above the <h3>. A bare `svg` selector finds
      // the sidebar branding mark instead, and these charts are dozens of
      // small primitives, so no area comparison can pick them out.
      { label: 'monthly visits chart', selectors: [], closest: { text: 'Monthly Visits', selector: 'section' } },
      { label: 'encounter status doughnut', selectors: [], closest: { text: 'Encounter Status', selector: 'section' } },
      { label: 'saved reports section', selectors: [], closest: { text: 'Saved and Generated Reports', levels: 1 } },
      { label: 'generated history', selectors: [], closest: { text: 'Generated History', selector: 'section' } },
    ],
  });

  // -- Theme 4: guidance portal + counselling tabs -------------------------
  // GuidancePortalTab.tsx -65, AnnouncementsTab, ServicesTab.
  await rec.capture({
    id: 'counselling',
    route: '/counselling',
    label: 'Counselling — guidance portal tab',
    zooms: [
      ...COMMON,
      { label: 'guidance portal tab panel', selectors: ['[role="tabpanel"]'] },
      { label: 'announcements list', selectors: ['table', 'ul'] },
    ],
  });

  // AnnouncementsTab.tsx and ServicesTab.tsx each changed.
  await rec.capture({
    id: 'counselling-announcements',
    route: '/counselling/announcements',
    label: 'Counselling — announcements tab',
    zooms: [...COMMON, { label: 'announcement form', selectors: [], closest: { text: 'Title', levels: 1 } }],
    before: async (p) => {
      await p.getByRole('button', { name: /new announcement|create|add/i }).first().click();
      await p.waitForTimeout(700);
    },
  });
  await rec.capture({
    id: 'counselling-services',
    route: '/counselling/services',
    label: 'Counselling — services tab (CMO catalogue)',
    zooms: [...COMMON, { label: 'services table', selectors: ['table', '[role="table"]'] }],
  });

  // -- Theme 5/6: shell, patients, dashboard --------------------------------
  // AppSidebar.tsx +6, PatientsPage.tsx +9, utils/notifications.ts -4, plus the
  // new dashboard-vertical-overflow spec.
  await rec.capture({
    id: 'dashboard',
    route: '/',
    label: 'Dashboard — shell and vertical overflow',
    zooms: [
      ...COMMON,
      { label: 'app sidebar', selectors: ['nav', 'aside', '[role="navigation"]'] },
      { label: 'summary counter cards', selectors: ['[role="group"]', 'section'] },
    ],
  });
  await rec.capture({
    id: 'patients',
    route: '/patients',
    label: 'Patients — registry list',
    zooms: [
      ...COMMON,
      { label: 'patient table', selectors: ['table', '[role="table"]'] },
      { label: 'filter bar', selectors: [], closest: { text: 'Patients', levels: 1 } },
    ],
  });

  await rec.finish({ role: 'superadmin', email: 'admin@synapse.dev', themes: 6 });
});

// ---------------------------------------------------------------------------
// 2. Student portal — the surface the survey year-level feature actually
//    targets. StudentPortalPage.tsx +235 and schemas/surveys.ts +6.
//    Requires a student account: superadmin is redirected off /me by the
//    '*' wildcard branch.
// ---------------------------------------------------------------------------
test('capture student portal survey surface', async ({ page }) => {
  const outDir = join(OUT_ROOT, 'student');
  await mkdir(outDir, { recursive: true });
  const rec = await Recorder.create(page, outDir);
  await rec.initCdp();
  await signInLive(page, 'student@synapse.dev');

  await rec.capture({
    id: 'student-portal',
    route: '/me',
    label: 'Student portal — overview (year level shown on identity card)',
    zooms: [
      ...COMMON,
      { label: 'student identity card', selectors: ['section:has-text("STUDENT PROFILE")', 'article'] },
      { label: 'portal nav', selectors: ['nav', 'aside'] },
    ],
  });

  // Surveys do not live on Overview — the portal's own nav puts them under
  // Guidance. The dev student is Year 4 and the seeded paper intakes target
  // Grade 1/7/11 and 1st Year, so an empty Guidance surface here is the
  // year-level filter *working*, not a missing feature. Capture it either way.
  await rec.capture({
    id: 'student-guidance',
    route: '/me',
    label: 'Student portal — Guidance (survey targeting by year level)',
    zooms: [
      ...COMMON,
      { label: 'guidance panel', selectors: [], closest: { text: 'Guidance', levels: 2 } },
    ],
    before: async (p) => {
      await p.getByRole('link', { name: /guidance/i }).first().click();
      await p.waitForTimeout(900);
    },
  });

  await rec.finish({ role: 'student', email: 'student@synapse.dev', themes: [1] });
});

// ---------------------------------------------------------------------------
// 3. Reports RBAC delta — second role.
//    AuthGroups.php +17, Filters.php +2, PermissionsAndGroupsSeeder -1, plus
//    the new backend/tests/Feature/ReportsModuleAccessTest.php. report_viewer is
//    documented as strictly read-only (reports.read + reports.export, no write
//    codes), so the same route must render differently than it does for
//    superadmin. Captured for the delta, not for the feature.
// ---------------------------------------------------------------------------
test('capture reports as read-only report_viewer', async ({ page }) => {
  const outDir = join(OUT_ROOT, 'report-viewer');
  await mkdir(outDir, { recursive: true });
  const rec = await Recorder.create(page, outDir);
  await rec.initCdp();
  await signInLive(page, 'report_viewer@synapse.dev');

  await rec.capture({
    id: 'reports-readonly',
    route: '/reports',
    label: 'Reports — report_viewer (no configure, export only)',
    zooms: [
      ...COMMON,
      { label: 'page header actions', selectors: ['header', 'h1'] },
      { label: 'saved reports section', selectors: ['section:has(h2), section:has(h3)'] },
    ],
  });

  // Expect the configure affordance to be absent rather than merely disabled.
  // If a future grant regression makes it visible, this fails loudly instead
  // of producing a quietly wrong walkthrough frame.
  const configureVisible = await page
    .getByRole('button', { name: /save report|new report|configure/i })
    .first()
    .isVisible()
    .catch(() => false);
  expect(configureVisible, 'report_viewer must not see report-authoring controls').toBe(false);

  await rec.finish({ role: 'report_viewer', email: 'report_viewer@synapse.dev', themes: [3] });
});
