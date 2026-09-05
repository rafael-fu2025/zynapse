/**
 * Phase 8 live E2E — login → dashboard → clinic → audit, with
 * screenshot evidence. Skipped unless SYNAPSE_E2E=1 (needs backend
 * on :8090 and the DevUserSeeder account).
 */
import { expect, test } from '@playwright/test';
import { apiOrigin, apiToken, signInLive } from './helpers/auth';

const RUN = process.env['SYNAPSE_E2E'] === '1';

test.skip(!RUN, 'SYNAPSE_E2E=1 not set — skipping live full-flow.');
// Eight pages + screenshots over the single-threaded PHP dev server.
test.setTimeout(120_000);

test('login → dashboard → clinic → audit (screenshots)', async ({ page, request }) => {
  await page.goto('/login');
  await page.screenshot({ path: 'e2e/artifacts/01-login.png', fullPage: true });

  await signInLive(page);

  // Self-sufficient precondition: schedule one appointment so the
  // provider (admin) has an `appointment.assigned` in-app row after the
  // opportunistic notification drain (10s cooldown). The dev registry
  // always holds consolidated student users starting `2026`.
  const bearer = await apiToken();
  const scheduledAt = new Date(Date.now() + 3 * 24 * 3600 * 1000)
    .toISOString().slice(0, 19).replace('T', ' ');
  const created = await request.post(`${apiOrigin()}/api/v1/clinic/appointments`, {
    headers: { authorization: `Bearer ${bearer}` },
    data: {
      patient_school_id: '20261970',
      provider_user_id: 1,
      scheduled_at: scheduledAt,
      reason: 'full-flow notification seed',
    },
  });
  expect([200, 201, 409], 'appointment precondition status').toContain(created.status());

  // Dashboard after login — module grid with live counters.
  await page.waitForURL(/\/$/, { timeout: 15_000 });
  await expect(page.getByRole('region', { name: /modules/i })).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(/signed in as admin@synapse\.dev/i)).toBeVisible();
  await page.screenshot({ path: 'e2e/artifacts/02-dashboard.png', fullPage: true });

  // Notification bell — the drained `appointment.assigned` in-app row
  // (created by the precondition above; the dashboard's 60s counters
  // poll triggers the 10s-cooldown opportunistic drain).
  await page.getByRole('button', { name: /notifications/i }).click();
  await expect(page.getByText(/new appointment assigned/i).first()).toBeVisible({ timeout: 60_000 });
  await page.screenshot({ path: 'e2e/artifacts/07-notifications.png', fullPage: true });
  await page.keyboard.press('Escape');

  // Clinic — client-side navigation (a hard reload would drop the
  // in-memory access token by design; tokens never touch localStorage).
  // Self-sufficient precondition: a kiosk walk-in for the same student
  // guarantees today's queue has a row (purpose becomes the complaint).
  const checkin = await request.post(`${apiOrigin()}/api/v1/clinic/checkins`, {
    headers: { authorization: `Bearer ${bearer}` },
    data: {
      identifier: '20261970',
      method: 'manual',
      destination: 'clinic',
      purpose: 'Consultation',
      station_id: 'E2E-FullFlow',
    },
  });
  expect([200, 201], 'check-in precondition status').toContain(checkin.status());
  await page.getByRole('link', { name: /clinic/i }).first().click();
  await page.waitForURL(/\/clinic$/);
  // The queue row identifies by patient identifier + station (the
  // purpose lives on the encounter, not the queue row).
  await expect(page.getByText(/20261970/i).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: 'e2e/artifacts/03-clinic.png', fullPage: true });

  // Audit — drained auth events should be listed.
  await page.goBack();
  await page.waitForURL(/\/$/);
  await page.getByRole('link', { name: /audit/i }).first().click();
  await page.waitForURL(/\/audit$/);
  await expect(page.getByText(/auth\.login_succeeded/i).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: 'e2e/artifacts/04-audit.png', fullPage: true });

  // Inventory (Phase 9 page) — create a unique supply via the API so
  // the assertion is self-sufficient (the old fixture item PARA-500 no
  // longer exists in freshly-seeded dev schemas).
  const sku = 'E2E' + String(Math.floor(Math.random() * 900000) + 100000);
  const item = await request.post(`${apiOrigin()}/api/v1/clinic/inventory`, {
    headers: { authorization: `Bearer ${bearer}` },
    data: { sku, name: 'E2E probe supply ' + sku, unit: 'pcs', reorder_level: 2 },
  });
  expect([200, 201], 'inventory precondition status').toContain(item.status());
  await page.goBack();
  await page.waitForURL(/\/$/);
  await page.getByRole('link', { name: /inventory/i }).first().click();
  await page.waitForURL(/\/inventory$/);
  // The precondition item is a SUPPLY — switch to the supplies tab,
  // then search its debounced box.
  await page.getByRole('tab', { name: /supplies/i }).click();
  await page.getByRole('searchbox', { name: /search/i }).fill(sku);
  await expect(page.getByText(sku, { exact: false }).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: 'e2e/artifacts/05-inventory.png', fullPage: true });

  // Appointments (Phase 9 page) — the precondition appointment created
  // above, listing its patient identifier.
  await page.goBack();
  await page.waitForURL(/\/$/);
  await page.getByRole('link', { name: /appointments/i }).first().click();
  await page.waitForURL(/\/appointments$/);
  await expect(page.getByText(/20261970/i).first()).toBeVisible({ timeout: 15_000 });
  await page.screenshot({ path: 'e2e/artifacts/06-appointments.png', fullPage: true });

  // Admin users (Phase 10 page) — nurse account with its group chip.
  // (Active/Disabled varies with the force-reset spec's lifecycle runs,
  // so assert on the stable group membership instead.)
  await page.goBack();
  await page.waitForURL(/\/$/);
  await page.getByRole('link', { name: /users/i }).first().click();
  await page.waitForURL(/\/admin\/users$/);
  // Last page in a long serial chain against the single-threaded PHP dev
  // server — allow extra headroom for the users query to resolve. Target
  // the desktop table: `.first()` also matches the hidden mobile cards.
  const usersTable = page.getByRole('table');
  await expect(usersTable.getByText(/nurse@synapse\.dev/i)).toBeVisible({ timeout: 30_000 });
  // Groups render through roleName() — the friendly label, not the code.
  // Scope to the table; the mobile card list is display-hidden at lg.
  await expect(usersTable.getByText(/clinic staff/i).first()).toBeVisible();
  await page.screenshot({ path: 'e2e/artifacts/08-admin-users.png', fullPage: true });
});
