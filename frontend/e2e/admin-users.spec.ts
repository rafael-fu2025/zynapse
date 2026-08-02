import { expect, test, type Page } from '@playwright/test';

const RUN = process.env['SYNAPSE_E2E'] === '1';
const PASSWORD = 'DevPassw0rd!';

test.skip(!RUN, 'SYNAPSE_E2E=1 not set, skipping live Admin Users checks.');
test.setTimeout(90_000);

async function signInAndOpenUsers(page: Page): Promise<void> {
  await expect(async () => {
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 15_000 });
    await expect(page.getByLabel(/email/i)).toBeVisible({ timeout: 10_000 });
  }).toPass({ timeout: 30_000 });
  await page.getByLabel(/email/i).fill('admin@synapse.dev');
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/$/, { timeout: 20_000 });
  await page.getByRole('link', { name: 'Users', exact: true }).click();
  await page.waitForURL(/\/admin\/users(?:\?|$)/, { timeout: 20_000 });
  await expect(page.getByRole('region', { name: 'User filters' })).toBeVisible();
}

test('Admin Users covers roles, validation, pagination, action names, and responsive widths', async ({ page }) => {
  await signInAndOpenUsers(page);

  await test.step('complete role catalog and creation errors', async () => {
    await page.getByRole('button', { name: /new user/i }).click();
    const dialog = page.getByRole('dialog', { name: 'New user' });

    for (const role of ['Administrator', 'Report Viewer', 'Clinical Supervisor', 'Student']) {
      await expect(dialog.getByText(role, { exact: true })).toBeVisible();
    }

    await dialog.getByRole('textbox', { name: 'Email', exact: true }).fill('invalid');
    await dialog.getByRole('textbox', { name: /username/i }).fill('bad name');
    await dialog.getByRole('button', { name: 'Create user', exact: true }).click();

    await expect(dialog.getByText(/invalid email/i)).toBeVisible();
    await expect(dialog.getByText(/letters, digits/i)).toBeVisible();
    await expect(dialog.getByText(/select at least one role/i)).toBeVisible();
    await expect(dialog.getByRole('textbox', { name: /username/i })).toHaveAttribute('aria-invalid', 'true');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  });

  await test.step('envelope pagination metadata and row-scoped action names', async () => {
    await page.route('**/api/v1/admin/users?*', async (route) => {
      const url = new URL(route.request().url());
      const secondPage = url.searchParams.has('cursor');
      const count = secondPage ? 1 : 25;
      const data = Array.from({ length: count }, (_, index) => {
        const id = secondPage ? 26 : index + 1;
        return {
          id,
          username: `user_${id}`,
          email: `user${id}@example.test`,
          active: true,
          status: 'active',
          groups: ['clinic_staff'],
          created_at: '2026-08-01 00:00:00',
          updated_at: '2026-08-01 00:00:00',
          last_active: null,
          force_reset: false,
        };
      });
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data,
          errors: null,
          meta: { pagination: { limit: 25, next_cursor: secondPage ? null : 'synthetic-cursor', prev_cursor: null } },
        }),
      });
    });

    await page.getByRole('searchbox', { name: /search users/i }).fill('synthetic');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    const actions = page.getByRole('button', { name: 'Actions for user1@example.test' });
    await expect(actions).toBeVisible();
    await actions.click();
    await expect(page.getByRole('menuitem', { name: 'Reset password' })).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(page.getByText(/page 2 · 1 account shown/i)).toBeVisible();
    await expect(page.getByRole('table').getByText('user26@example.test', { exact: true })).toBeVisible();
  });

  await test.step('no page-level overflow at supported widths', async () => {
    for (const width of [320, 375, 768, 1024]) {
      await page.setViewportSize({ width, height: 800 });
      await expect.poll(() => page.evaluate(
        () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
      )).toBe(true);
    }
  });
});
