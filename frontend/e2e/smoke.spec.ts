/**
 * Phase 3 smoke test — Login + Dashboard render.
 * Skipped unless env `SYNAPSE_E2E=1` is set (avoids CI flicker when the
 * backend isn't running locally).
 */
import { expect, test } from '@playwright/test';

const RUN = process.env['SYNAPSE_E2E'] === '1';

test.skip(!RUN, 'SYNAPSE_E2E=1 not set — skipping live smoke.');

test('login page renders and the form is reachable', async ({ page }) => {
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: /sign in to synapse/i })).toBeVisible();
  await expect(page.getByLabel(/email/i)).toBeVisible();
  await expect(page.getByLabel(/password/i)).toBeVisible();
});

test('root path is gated when unauthenticated', async ({ page }) => {
  await page.goto('/');
  await page.waitForURL(/\/login$/);
});
