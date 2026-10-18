import { expect, test } from '@playwright/test';
import { signInMocked, type MockSession } from './helpers/auth';

const cases = [
  { label: 'Clinic referrals', path: '/referrals', permissions: ['referrals.read', 'clinic.encounters.read'] },
  { label: 'Guidance referrals', path: '/referrals', permissions: ['referrals.read', 'counselling.records.read'] },
  { label: 'Users', path: '/admin/users', permissions: ['rbac.manage'] },
  { label: 'Audit', path: '/audit', permissions: ['audit.read'] },
];

for (const width of [1280, 390]) {
  for (const entry of cases) {
    test(`${entry.label}: exactly one header-to-toolbar gap at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      // Catch ALL API traffic before signing in. No request, including the
      // queued MIS status query on Users, can reach a live backend.
      await page.route(/\/api\/v1\//, (route) => route.fulfill({
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: route.request().url().includes('/jobs/latest') ? null : [],
          errors: [],
          meta: { pagination: { count: 0, next_cursor: null } },
        }),
      }));
      const session: MockSession = {
        id: 9010, email: 'layout@synapse.test', username: 'layout-test',
        is_active: true, force_reset: false, permissions: entry.permissions,
      };
      await signInMocked(page, session, { mockRefresh: true });
      await page.goto(entry.path);
      const header = page.locator('main [data-page-header]');
      await expect(header).toBeVisible();
      await expect.poll(() => header.evaluate((element) => {
        const toolbar = element.nextElementSibling;
        if (!toolbar) throw new Error('Header has no toolbar sibling');
        return (toolbar.getBoundingClientRect().top - element.getBoundingClientRect().bottom)
          / parseFloat(getComputedStyle(document.documentElement).fontSize);
      })).toBeCloseTo(1, 2); // space-y-4 = 1rem, not zero or a doubled gap
    });
  }
}
