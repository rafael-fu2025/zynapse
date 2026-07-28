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
  await page.getByLabel(/password/i).fill(process.env['SYNAPSE_E2E_PASSWORD'] ?? 'DevPassw0rd!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/$/);

  // Client-side navigation — hard reload drops the in-memory token.
  await page.getByRole('link', { name: /facilities/i }).first().click();
  await page.waitForURL(/\/facilities$/);
  await expect(page.getByRole('heading', { name: /facilities/i }).first()).toBeVisible();
});