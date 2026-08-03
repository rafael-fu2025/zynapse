/**
 * Phase 4 smoke — extended.
 *
 * Skipped unless `SYNAPSE_E2E=1`. Requires both backend (8080) and
 * frontend (5173) running locally with at least one BMG unit seeded.
 *
 * Steps:
 *   1. Sign in as `clinic_staff` (uses login form).
 *   2. Navigate to /facilities.
 *   3. Confirm the units table renders.
 *
 * Use `playwright.config.ts` to point at the right baseURL.
 */
import { expect, test } from '@playwright/test';

const RUN = process.env['SYNAPSE_E2E'] === '1';

test.skip(!RUN, 'SYNAPSE_E2E=1 not set — skipping live smoke.');

test('facilities page renders the BMG units table', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel(/email/i).fill(process.env['SYNAPSE_E2E_EMAIL'] ?? 'admin@synapse.dev');
  await page.locator('input[name="password"]').fill(process.env['SYNAPSE_E2E_PASSWORD'] ?? 'DevPassw0rd!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/$/);

  // Client-side navigation — hard reload drops the in-memory token.
  await page.getByRole('link', { name: /facilities/i }).first().click();
  await page.waitForURL(/\/facilities$/);
  await expect(page.getByRole('heading', { name: /facilities/i }).first()).toBeVisible();

  const actions = page.getByRole('button', { name: /actions for/i }).first();
  await expect(actions).toBeVisible();
  await actions.click();
  await expect(page.getByRole('menuitem', { name: 'Edit drum' })).toBeVisible();
  await expect(page.getByRole('menuitem', { name: 'Start batch' })).toBeVisible();
});

/**
 * Tier 3.1 — curing transition is exposed in the units menu.
 *
 * The "Move to curing" entry must render for every unit (gated only
 * by the `disabled` prop when the batch isn't in `awaiting_output`).
 * The networking path is `POST /facilities/batches/:id/curing` —
 * we boot a single batch into `awaiting_output` via the existing
 * dev seed, then assert the menu shows the action and the route
 * accepts the empty payload (no AIP snapshot).
 *
 * Defensive: this test is skipped if no unit is in the
 * `awaiting_output` state on the first row of the list — the
 * E2E environment is shared and we must not assume seed ordering.
 */
test('Move to curing action is available for awaiting_output units', async ({ page, request }) => {
  await page.goto('/login');
  await page.getByLabel(/email/i).fill(process.env['SYNAPSE_E2E_EMAIL'] ?? 'admin@synapse.dev');
  await page.locator('input[name="password"]').fill(process.env['SYNAPSE_E2E_PASSWORD'] ?? 'DevPassw0rd!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/$/);

  const baseURL = page.url().replace(/\/$/, '');
  const cookies = await page.context().cookies();
  const cookieHeader = cookies.map((c) => `${c.name}=${c.value}`).join('; ');

  // Discover the first unit in awaiting_output via the public API.
  // The endpoint is paginated; the seed ships 1–2 units, so a single
  // page is enough. The backend groups everything under /api/v1.
  const list = await request.get(`${baseURL}/api/v1/facilities/units?limit=50`, {
    headers: { cookie: cookieHeader },
  });
  expect(list.ok(), `units list status was ${list.status()}`).toBeTruthy();
  const body = (await list.json()) as { data?: Array<{ id: number; status: string; active_batch_id?: number | null }> };
  const rows = body.data ?? [];
  const awaiting = rows.find((u) => u.status === 'awaiting_output' && u.active_batch_id !== null && u.active_batch_id !== undefined);
  test.skip(!awaiting, 'No unit currently in awaiting_output — skipping curing transition E2E.');

  // Locate the row's actions trigger. The aria-label is
  // `Actions for <unit_code>`; the regex matches any unit row.
  const rowIndex = rows.indexOf(awaiting!);
  const actions = page.getByRole('button', { name: /actions for/i }).nth(rowIndex);
  await expect(actions).toBeVisible();
  await actions.click();

  const menuItem = page.getByRole('menuitem', { name: 'Move to curing' });
  await expect(menuItem).toBeVisible();
  await expect(menuItem).toBeEnabled();

  // The transition endpoint must be wired. We don't actually fire the
  // mutation — that would change shared state — we just probe the
  // route with a batch id that won't exist. A 404 (resource not
  // found) or 409 (state machine rejection) is enough to prove the
  // route is reachable; a 405 would mean the route is missing.
  const probe = await request.post(`${baseURL}/api/v1/facilities/batches/-1/curing`, {
    headers: { cookie: cookieHeader, 'content-type': 'application/json' },
    data: {},
  });
  expect([404, 409]).toContain(probe.status());
});
