/**
 * Phase 10 live E2E — forced password rotation.
 *
 * Setup via API: admin resets the nurse's password (temp + force_reset).
 * UI: nurse logs in with the temp password, is locked to
 * /change-password, rotates it, and lands on the dashboard.
 */
import { expect, test } from '@playwright/test';

const RUN = process.env['SYNAPSE_E2E'] === '1';

test.skip(!RUN, 'SYNAPSE_E2E=1 not set — skipping live force-reset flow.');

test('admin reset forces nurse into change-password, rotation unlocks', async ({ page, request }) => {
  // --- API setup: admin login + reset nurse (#2) password.
  const login = await request.post('http://localhost:8080/api/v1/auth/login', {
    data: { email: 'admin@synapse.dev', password: 'DevPassw0rd!' },
  });
  expect(login.ok()).toBeTruthy();
  const adminTok = (await login.json()).data.access_token as string;

  const reset = await request.post('http://localhost:8080/api/v1/admin/users/2/reset-password', {
    headers: { Authorization: `Bearer ${adminTok}` },
  });
  expect(reset.ok()).toBeTruthy();
  const temp = (await reset.json()).data.temporary_password as string;

  // --- UI: nurse signs in with the temp password.
  await page.goto('/login');
  await page.getByLabel(/email/i).fill('nurse@synapse.dev');
  await page.getByLabel(/password/i).fill(temp);
  await page.getByRole('button', { name: /sign in/i }).click();

  // Locked to the change-password screen.
  await page.waitForURL(/\/change-password$/, { timeout: 15_000 });
  await expect(page.getByText(/reset by an administrator/i)).toBeVisible();
  await page.screenshot({ path: 'e2e/artifacts/09-force-reset.png', fullPage: true });

  // Rotate.
  await page.getByLabel(/current password/i).fill(temp);
  await page.getByLabel(/^new password/i).fill('RotatedNursePass1!');
  await page.getByLabel(/confirm new password/i).fill('RotatedNursePass1!');
  await page.getByRole('button', { name: /change password/i }).click();

  // Unlocked — back on the dashboard.
  await page.waitForURL(/\/$/, { timeout: 15_000 });
  await expect(page.getByRole('region', { name: /modules/i })).toBeVisible({ timeout: 15_000 });
});
