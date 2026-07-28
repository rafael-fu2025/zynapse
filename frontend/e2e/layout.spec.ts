/**
 * Layout verification — professional management shell (sidebar + topbar).
 *
 * Short, deterministic flow (login → dashboard) so it stays reliable on
 * the single-threaded PHP dev server: proves the sidebar navigation,
 * grouped links, and topbar render for an authenticated admin.
 */
import { expect, test } from '@playwright/test';

const RUN = process.env['SYNAPSE_E2E'] === '1';

test.skip(!RUN, 'SYNAPSE_E2E=1 not set — skipping live layout check.');

test('authenticated shell shows the sidebar navigation and topbar', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel(/email/i).fill('admin@synapse.dev');
  await page.getByLabel(/password/i).fill('DevPassw0rd!');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/$/, { timeout: 20_000 });

  // Primary sidebar landmark + representative grouped links.
  const sidebar = page.getByRole('navigation', { name: /primary/i });
  await expect(sidebar).toBeVisible({ timeout: 20_000 });
  await expect(sidebar.getByRole('link', { name: /dashboard/i })).toBeVisible();
  await expect(sidebar.getByRole('link', { name: /appointments/i })).toBeVisible();
  await expect(sidebar.getByRole('link', { name: /audit/i })).toBeVisible();

  // Topbar controls.
  await expect(page.getByRole('button', { name: /notifications/i })).toBeVisible();
  await expect(page.getByRole('button', { name: /sign out/i })).toBeVisible();

  // Dashboard CONTENT loads (not just the shell) — proves the cold-load
  // path works after the code-split + /auth/me dedupe.
  await expect(page.getByRole('region', { name: /modules/i })).toBeVisible({ timeout: 30_000 });

  await page.screenshot({ path: 'e2e/artifacts/10-shell-layout.png', fullPage: true });
});
