/**
 * Phase 10 live E2E — forced password rotation.
 *
 * Setup via API: admin resets the nurse's password (temp + force_reset).
 * UI: nurse logs in with the temp password, is locked to
 * /change-password, rotates it, and lands on the dashboard.
 *
 * Credentials come from the environment (see helpers/auth) — nothing is
 * hardcoded. The nurse account is resolved by email search rather than
 * an assumed row id, so re-seeded dev databases don't silently retarget
 * the test at whichever user happens to be #2.
 */
import { expect, test } from '@playwright/test';
import { apiOrigin, livePassword } from './helpers/auth';

const RUN = process.env['SYNAPSE_E2E'] === '1';

test.skip(!RUN, 'SYNAPSE_E2E=1 not set — skipping live force-reset flow.');
test.setTimeout(90_000);

const ADMIN_EMAIL = 'admin@synapse.dev';
const NURSE_EMAIL = 'nurse@synapse.dev';

test('admin reset forces nurse into change-password, rotation unlocks', async ({ page, request }) => {
  const api = apiOrigin();

  // --- API setup: admin login + reset the nurse's password.
  const login = await request.post(`${api}/api/v1/auth/login`, {
    data: { email: ADMIN_EMAIL, password: livePassword() },
  });
  expect(login.ok()).toBeTruthy();
  const adminTok = (await login.json()).data.access_token as string;

  const nurseId = await resolveNurseId(request, api, adminTok);

  const reset = await request.post(`${api}/api/v1/admin/users/${nurseId}/reset-password`, {
    headers: { Authorization: `Bearer ${adminTok}` },
  });
  expect(reset.ok()).toBeTruthy();
  const temp = (await reset.json()).data.temporary_password as string;

  // --- UI: nurse signs in with the temp password.
  await expect(async () => {
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 15_000 });
    await expect(page.getByLabel(/student|employee|number|email/i)).toBeVisible({ timeout: 10_000 });
  }).toPass({ timeout: 30_000 });
  await page.getByLabel(/student|employee|number|email/i).fill(NURSE_EMAIL);
  await page.locator('input[name="password"]').fill(temp);
  const loginResponsePromise = page.waitForResponse(
    (response) => response.url().endsWith('/api/v1/auth/login') && response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: /sign in/i }).click();
  const forcedLogin = await loginResponsePromise;
  expect(forcedLogin.ok()).toBeTruthy();
  const forcedToken = (await forcedLogin.json()).data.access_token as string;

  // Locked to the change-password screen.
  await page.waitForURL(/\/change-password$/, { timeout: 15_000 });
  await expect(page.getByText(/reset by an administrator/i)).toBeVisible();
  // A direct API client is restricted too; this is not merely a SPA redirect.
  const blockedApi = await request.get(`${api}/api/v1/dashboard/counters`, {
    headers: { Authorization: `Bearer ${forcedToken}` },
  });
  expect(blockedApi.status()).toBe(403);
  expect((await blockedApi.json()).errors[0].code).toBe('auth.password_change_required');
  await page.screenshot({ path: 'e2e/artifacts/09-force-reset.png', fullPage: true });

  // Rotate.
  await page.getByLabel(/current password/i).fill(temp);
  await page.getByLabel(/^new password/i).fill('RotatedNursePass1!');
  await page.getByLabel(/confirm new password/i).fill('RotatedNursePass1!');
  await page.getByRole('button', { name: /change password/i }).click();

  // Unlocked — back on the dashboard. The nurse is a clinic-role user,
  // so the dashboard renders the analytics view, not the Modules grid.
  await page.waitForURL(/\/$/, { timeout: 15_000 });
  await expect(page.getByRole('heading', { name: /dashboard/i })).toBeVisible({ timeout: 15_000 });
});

/**
 * The admin users list is keyset-paginated with a free-text search; one
 * page is enough because the search targets a unique email. Failing
 * loudly here beats resetting the wrong account.
 */
async function resolveNurseId(
  request: import('@playwright/test').APIRequestContext,
  api: string,
  adminTok: string,
): Promise<number> {
  const list = await request.get(
    `${api}/api/v1/admin/users?search=${encodeURIComponent(NURSE_EMAIL)}`,
    { headers: { Authorization: `Bearer ${adminTok}` } },
  );
  expect(list.ok(), `admin users search status was ${list.status()}`).toBeTruthy();
  const body = (await list.json()) as { data?: Array<{ id: number; email: string }> };
  const nurse = (body.data ?? []).find((u) => u.email.toLowerCase() === NURSE_EMAIL);
  expect(nurse, `no admin user found with email ${NURSE_EMAIL} — is the seed loaded?`).toBeTruthy();
  return nurse!.id;
}
