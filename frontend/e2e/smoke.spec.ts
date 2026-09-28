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
  // The 2026-09-27 redesign replaced the card title with a real heading
  // and split the page into welcome pane + form pane (fuel.foundationu.com
  // format). Assert the form-side heading and both fields.
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible();
  await expect(page.getByLabel(/student|employee|number|email/i)).toBeVisible();
  // "Show password" toggle also matches /password/i — target the textbox.
  await expect(page.getByRole('textbox', { name: 'Password' })).toBeVisible();
});

test('root path is gated when unauthenticated', async ({ page }) => {
  await page.goto('/');
  await page.waitForURL(/\/login$/);
});
