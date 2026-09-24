/**
 * Guidance Center sidebar navigation (2026-09-24).
 *
 * Surveys, Announcements, Analytics and Services used to be sections inside
 * `/counselling`. They are now their own routes with their own sidebar rows, so
 * this spec guards the two things that move can silently break:
 *
 *   1. the rows exist and each lands on its own page, and
 *   2. the URLs that did NOT move still resolve exactly as before —
 *      `?tab=queue`, which notifications and existing links point at, and the
 *      old `?tab=surveys`-style value, which now redirects.
 *
 * Mocked (like `portal-appointments.spec.ts`), so it runs in CI; the live
 * `SYNAPSE_E2E=1` specs need a real backend.
 */
import { expect, test } from '@playwright/test';
import { signInMocked, type MockSession } from './helpers/auth';

/** A guidance administrator: the five codes the Guidance Center group reads. */
const SESSION: MockSession = {
  id: 9001,
  email: 'guidance-admin@synapse.test',
  username: 'guidance-admin',
  is_active: true,
  force_reset: false,
  permissions: [
    'counselling.records.read',
    'counselling.queue.read',
    'counselling.schedule.read',
    'counselling.surveys.manage',
    'counselling.announcements.manage',
    'counselling.services.manage',
  ],
};

test.beforeEach(async ({ page }) => {
  // Stubs auth AND the dashboard counters the sidebar fetches on every
  // authenticated route (see the helper's note on why a missing counter stub
  // logs the SPA out instead of failing loudly).
  //
  // `mockRefresh` is on because every test here uses `page.goto` into a
  // protected route: a full reload re-bootstraps the session, and an unstubbed
  // silent refresh would 401 and bounce the SPA to /login.
  await signInMocked(page, SESSION, { mockRefresh: true });

  // Every guidance list endpoint, so the pages under test render their empty
  // state rather than 401-ing through the proxy and bouncing to /login.
  await page.route('**/api/v1/counselling/**', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: [], errors: [], meta: null }),
    }));
});

test('the Guidance Center group carries the four moved surfaces', async ({ page }) => {
  const sidebar = page.getByRole('navigation', { name: /primary/i });
  await expect(sidebar).toBeVisible({ timeout: 20_000 });

  for (const label of ['Counselling', 'Surveys', 'Announcements', 'Analytics', 'Services']) {
    await expect(sidebar.getByRole('link', { name: label, exact: true })).toBeVisible();
  }
});

test('each moved surface has its own route and its own heading', async ({ page }) => {
  const routes: ReadonlyArray<[string, string]> = [
    ['/counselling/surveys', 'Surveys'],
    ['/counselling/announcements', 'Announcements'],
    ['/counselling/analytics', 'Analytics'],
    ['/counselling/services', 'Services'],
  ];

  for (const [path, heading] of routes) {
    await page.goto(path);
    // `exact` matters: Analytics is a substring of the Analytics page's own
    // "Scheduling analytics" sub-heading, which would match loosely.
    await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
  }
});

test('an old ?tab= link redirects to the route that replaced it', async ({ page }) => {
  await page.goto('/counselling?tab=surveys');

  await expect(page).toHaveURL(/\/counselling\/surveys$/);
  await expect(page.getByRole('heading', { name: 'Surveys', exact: true })).toBeVisible();
});

test('the sections that did not move still resolve from the URL', async ({ page }) => {
  await page.goto('/counselling?tab=queue');

  // The tab param survives (it is not the default for a queue-capable user, so
  // it must not be stripped) and the Queue section is the selected one.
  await expect(page).toHaveURL(/tab=queue/);
  await expect(page.getByRole('tab', { name: 'Queue', selected: true })).toBeVisible();
});
