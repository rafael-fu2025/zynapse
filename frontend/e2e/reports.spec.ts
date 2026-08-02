import { expect, test, type Page } from '@playwright/test';

const RUN = process.env['SYNAPSE_E2E'] === '1';
const PASSWORD = 'DevPassw0rd!';

test.skip(!RUN, 'SYNAPSE_E2E=1 not set — skipping live reports checks.');
test.setTimeout(90_000);

async function signIn(page: Page, email: string): Promise<void> {
  await expect(async () => {
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 15_000 });
    await expect(page.getByLabel(/email/i)).toBeVisible({ timeout: 10_000 });
  }).toPass({ timeout: 30_000 });
  await page.getByLabel(/email/i).fill(email);
  await page.locator('input[name="password"]').fill(PASSWORD);
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/$/, { timeout: 20_000 });
  if ((page.viewportSize()?.width ?? 1280) < 768) {
    await page.getByRole('banner').getByRole('button', { name: /toggle sidebar/i }).click();
  }
  await page.getByRole('link', { name: 'Reports', exact: true }).click();
  await page.waitForURL(/\/reports(?:\?|$)/, { timeout: 20_000 });
  await expect(page.getByRole('heading', { name: /institution overview/i })).toBeVisible({ timeout: 20_000 });
}

test('report viewer can read and export but cannot author reports', async ({ page }) => {
  await signIn(page, 'report_viewer@synapse.dev');

  await expect(page.getByRole('button', { name: /export clinic/i })).toBeVisible();
  await expect(page.getByRole('button', { name: /generate narrative/i })).toHaveCount(0);
  await expect(page.getByText(/save this view/i)).toHaveCount(0);
  await expect(page.getByRole('heading', { name: /institution overview/i })).toBeVisible();
});

test('administrator can generate a range-bound narrative', async ({ page }) => {
  await signIn(page, 'admin@synapse.dev');

  await page.getByRole('button', { name: /generate narrative/i }).click();
  await expect(page.getByRole('heading', { name: /clinic narrative/i })).toBeVisible({ timeout: 20_000 });
  await expect(page.getByText(/deterministic template summary/i)).toBeVisible();
});

test('module failure is distinct from an empty result and can be retried', async ({ page }) => {
  await signIn(page, 'admin@synapse.dev');
  let fail = true;
  await page.route('**/api/v1/reports/facilities?*', async (route) => {
    if (fail) {
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ data: null, errors: [{ code: 'server.error', message: 'Synthetic test failure' }], meta: {} }),
      });
      return;
    }
    await route.continue();
  });

  await page.getByRole('tab', { name: /facilities/i }).click();
  await expect(page.getByText(/failed to load facilities analytics/i)).toBeVisible();

  fail = false;
  await page.getByRole('alert')
    .filter({ hasText: /failed to load facilities analytics/i })
    .getByRole('button', { name: /retry/i })
    .click();
  await expect(page.getByText(/failed to load facilities analytics/i)).toHaveCount(0, { timeout: 20_000 });
});

test('invalid report URL state is replaced with a canonical range and module', async ({ page }) => {
  await signIn(page, 'report_viewer@synapse.dev');

  await page.evaluate(() => {
    window.history.pushState({}, '', '/reports?tab=unknown&start=2026-02-30&end=2026-01-01');
    window.dispatchEvent(new PopStateEvent('popstate'));
  });

  await expect(page.getByRole('tab', { name: 'Clinic', exact: true })).toHaveAttribute('data-state', 'active');
  await expect.poll(() => new URL(page.url()).searchParams.get('tab')).toBeNull();
  await expect.poll(() => new URL(page.url()).searchParams.get('start')).not.toBe('2026-02-30');
  await expect.poll(() => new URL(page.url()).searchParams.get('end')).not.toBe('2026-01-01');
});

test('reports remain free of page-level overflow at 320, 375, 768, and 1024px', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 800 });
  await signIn(page, 'report_viewer@synapse.dev');

  for (const width of [320, 375, 768, 1024]) {
    await page.setViewportSize({ width, height: 800 });
    const fitsViewport = await page.evaluate(
      () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
    );
    expect(fitsViewport, 'viewport width ' + String(width)).toBe(true);
  }
});
