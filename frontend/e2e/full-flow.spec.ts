/**
 * Phase 8 live E2E — login → dashboard → clinic → audit, with
 * screenshot evidence. Skipped unless SYNAPSE_E2E=1 (needs backend
 * on :8090 and the DevUserSeeder account).
 */
import { expect, test } from '@playwright/test';

const RUN = process.env['SYNAPSE_E2E'] === '1';

test.skip(!RUN, 'SYNAPSE_E2E=1 not set — skipping live full-flow.');

test('login → dashboard → clinic → audit (screenshots)', async ({ page }) => {
  await page.goto('/login');
  await page.screenshot({ path: 'e2e/artifacts/01-login.png', fullPage: true });

  await page.getByLabel(/email/i).fill('admin@synapse.dev');
  await page.getByLabel(/password/i).fill('DevPassw0rd!');
  await page.getByRole('button', { name: /sign in/i }).click();

  // Dashboard after login — module grid with live counters.
  await page.waitForURL(/\/$/, { timeout: 15_000 });
  await expect(page.getByRole('region', { name: /modules/i })).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(/signed in as admin@synapse\.dev/i)).toBeVisible();
  await page.screenshot({ path: 'e2e/artifacts/02-dashboard.png', fullPage: true });

  // Notification bell — the drained `appointment.assigned` in-app row.
  await page.getByRole('button', { name: /notifications/i }).click();
  await expect(page.getByText(/new appointment assigned/i).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: 'e2e/artifacts/07-notifications.png', fullPage: true });
  await page.keyboard.press('Escape');

  // Clinic — client-side navigation (a hard reload would drop the
  // in-memory access token by design; tokens never touch localStorage).
  await page.getByRole('link', { name: /clinic/i }).first().click();
  await page.waitForURL(/\/clinic$/);
  await expect(page.getByText(/headache/i).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: 'e2e/artifacts/03-clinic.png', fullPage: true });

  // Audit — drained auth events should be listed.
  await page.goBack();
  await page.waitForURL(/\/$/);
  await page.getByRole('link', { name: /audit/i }).first().click();
  await page.waitForURL(/\/audit$/);
  await expect(page.getByText(/auth\.login_succeeded/i).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: 'e2e/artifacts/04-audit.png', fullPage: true });

  // Inventory (Phase 9 page) — the PARA-500 item from the API E2E run.
  await page.goBack();
  await page.waitForURL(/\/$/);
  await page.getByRole('link', { name: /inventory/i }).first().click();
  await page.waitForURL(/\/inventory$/);
  await expect(page.getByText(/para-500/i).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: 'e2e/artifacts/05-inventory.png', fullPage: true });

  // Appointments (Phase 9 page) — appointment #1 with lifecycle badge.
  await page.goBack();
  await page.waitForURL(/\/$/);
  await page.getByRole('link', { name: /appointments/i }).first().click();
  await page.waitForURL(/\/appointments$/);
  await expect(page.getByText(/2020-12345/i).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: 'e2e/artifacts/06-appointments.png', fullPage: true });

  // Admin users (Phase 10 page) — nurse account with its group chip.
  // (Active/Disabled varies with the force-reset spec's lifecycle runs,
  // so assert on the stable group membership instead.)
  await page.goBack();
  await page.waitForURL(/\/$/);
  await page.getByRole('link', { name: /users/i }).first().click();
  await page.waitForURL(/\/admin\/users$/);
  // Last page in a long serial chain against the single-threaded PHP dev
  // server — allow extra headroom for the users query to resolve.
  await expect(page.getByText(/nurse@synapse\.dev/i).first()).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(/clinic_staff/i).first()).toBeVisible();
  await page.screenshot({ path: 'e2e/artifacts/08-admin-users.png', fullPage: true });
});
