import { expect, test } from '@playwright/test';

const socials = [
  { label: 'Guidance Center', href: 'https://www.facebook.com/profile.php?id=100063778576372' },
  { label: 'Foundation University', href: 'https://www.facebook.com/foundationu.edu' },
  { label: 'Health Services', href: 'https://www.facebook.com/profile.php?id=100057107094031' },
];

test.beforeEach(async ({ page }) => {
  await page.route('**/api/v1/auth/refresh', (route) => route.fulfill({
    status: 401,
    contentType: 'application/json',
    body: JSON.stringify({
      success: false,
      data: null,
      errors: [{ code: 'auth.refresh_missing', message: 'auth.refresh_missing' }],
      meta: null,
    }),
  }));
  await page.goto('/login');
  await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();
});

test('social dock keeps all three labelled links accessible on mobile and desktop in both themes', async ({ page }, testInfo) => {
  const nav = page.getByRole('navigation', { name: 'Socials' });
  await expect(nav.getByText('Connect with us')).toBeVisible();
  await expect(nav.getByRole('link')).toHaveCount(3);

  for (const theme of ['light', 'dark']) {
    await page.evaluate((value) => {
      document.documentElement.classList.toggle('dark', value === 'dark');
    }, theme);
    for (const width of [320, 375, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: width < 768 ? 568 : 900 });
      await nav.scrollIntoViewIfNeeded();
      for (const social of socials) {
        const link = nav.getByRole('link', { name: `${social.label} on Facebook (opens in a new tab)`, exact: true });
        await expect(link).toBeVisible();
        await expect(link).toContainText(social.label);
        await expect(link).toHaveAttribute('href', social.href);
        await expect(link).toHaveAttribute('target', '_blank');
        await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        await expect(link).not.toHaveAttribute('title');
        const bounds = await link.boundingBox();
        expect(bounds).not.toBeNull();
        expect(bounds!.width).toBeGreaterThanOrEqual(44);
        expect(bounds!.height).toBeGreaterThanOrEqual(44);
        expect(bounds!.x).toBeGreaterThanOrEqual(0);
        expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
        await expect.poll(() => link.locator('img').evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
      if (width === 375 || width === 1440) {
        await page.screenshot({ path: testInfo.outputPath(`socials-${theme}-${width}.png`), fullPage: true });
      }
    }
  }
});

test('each social icon shows a styled tooltip on hover and dismisses with Escape', async ({ page }) => {
  const nav = page.getByRole('navigation', { name: 'Socials' });
  for (const social of socials) {
    const link = nav.getByRole('link', { name: `${social.label} on Facebook (opens in a new tab)`, exact: true });
    await link.locator('img').hover();
    const tooltip = page.getByRole('tooltip');
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText(`${social.label} on Facebook`);
    await expect(tooltip).toContainText('Opens in a new tab');
    await page.keyboard.press('Escape');
    await expect(tooltip).toHaveCount(0);
    await page.mouse.move(0, 0);
  }
});

test('social links provide hover feedback and are keyboard reachable', async ({ page }) => {
  const links = page.getByRole('navigation', { name: 'Socials' }).getByRole('link');
  const first = links.first();
  const initialBackground = await first.evaluate((el) => getComputedStyle(el).backgroundColor);
  await first.hover();
  await expect.poll(() => first.evaluate((el) => getComputedStyle(el).backgroundColor)).not.toBe(initialBackground);

  // Enter the dock through the login form's last interactive control.
  await page.getByRole('button', { name: 'Sign in' }).focus();
  for (let index = 0; index < socials.length; index += 1) {
    await page.keyboard.press('Tab');
    await expect(links.nth(index)).toBeFocused();
    expect(await links.nth(index).evaluate((el) => el.matches(':focus-visible'))).toBe(true);
    expect(await links.nth(index).evaluate((el) => getComputedStyle(el).boxShadow)).not.toBe('none');
    const tooltip = page.getByRole('tooltip');
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toContainText(`${socials[index]!.label} on Facebook`);
    await page.keyboard.press('Escape');
    await expect(tooltip).toHaveCount(0);
    await expect(links.nth(index)).toBeFocused();
  }
});
