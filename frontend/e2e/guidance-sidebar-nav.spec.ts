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

/** A guidance administrator: the six codes the Guidance Center group reads. */
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
    'counselling.responses.read_any',
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

  // Counselling is an accordion trigger now (button, not a link); the four
  // moved surfaces remain flat link rows beside it.
  await expect(sidebar.getByRole('button', { name: 'Counselling' })).toBeVisible();
  for (const label of ['Surveys', 'Announcements', 'Analytics', 'Services']) {
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
  const sidebar = page.getByRole('navigation', { name: /primary/i });

  // The tab param survives (it is not the default for a queue-capable user, so
  // it must not be stripped). Navigation now lives in the sidebar accordion —
  // the Queue child is the active one and the Queue board is what renders.
  await expect(page).toHaveURL(/tab=queue/);
  const counselling = sidebar.getByRole('button', { name: 'Counselling' });
  await expect(counselling).toHaveAttribute('aria-expanded', 'true');
  await expect(sidebar.getByRole('link', { name: 'Queue', exact: true })).toHaveAttribute(
    'data-active',
    'true',
  );
});

test('the four counselling sections are an accordion under the Counselling row', async ({ page }) => {
  const sidebar = page.getByRole('navigation', { name: /primary/i });
  const trigger = sidebar.getByRole('button', { name: 'Counselling' });

  // On /counselling the accordion starts open and lists the four sections.
  await page.goto('/counselling?tab=appointments');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  for (const label of ['Queue', 'Appointments', 'Follow-ups', 'Scheduling']) {
    await expect(sidebar.getByRole('link', { name: label, exact: true })).toBeVisible();
  }

  // Collapsing hides the children; expanding brings them back.
  await trigger.click();
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await expect(sidebar.getByRole('link', { name: 'Follow-ups', exact: true })).toBeHidden();
  await trigger.click();
  await expect(sidebar.getByRole('link', { name: 'Follow-ups', exact: true })).toBeVisible();

  // A child navigates: clicking Appointments lands the page on the book
  // (its URL carries ?tab=appointments, the AppointmentsTab content shows).
  await sidebar.getByRole('link', { name: 'Appointments', exact: true }).click();
  await expect(page).toHaveURL(/\/counselling\?tab=appointments$/);
  await expect(page.getByRole('heading', { name: 'Counselling', exact: true })).toBeVisible();
});

test('a collapsed accordion stays collapsed across navigation', async ({ page }) => {
  const sidebar = page.getByRole('navigation', { name: /primary/i });
  const trigger = sidebar.getByRole('button', { name: 'Counselling' });

  // The reported repro: close the accordion while on the module, then press
  // another row — the accordion must NOT force itself back open. (The first
  // cut nested its component inside AppSidebar, so every render remounted it
  // and reset the open state.)
  await page.goto('/counselling?tab=appointments');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await trigger.click();
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');

  await sidebar.getByRole('link', { name: 'Surveys', exact: true }).click();
  await expect(page).toHaveURL(/\/counselling\/surveys$/);
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await expect(sidebar.getByRole('link', { name: 'Queue', exact: true })).toBeHidden();

  // Landing on the module again opens it — auto-open only ever opens.
  await page.goto('/counselling?tab=queue');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
});
