import { expect, test, type Page } from '@playwright/test';

async function fixture(page: Page, theme: 'light' | 'dark') {
  await page.route('**/api/v1/auth/carousel-slides', (route) => route.fulfill({ json: {
    success: true, data: [], errors: [], meta: null,
  } }));
  await page.goto('/login');
  await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeVisible();
  await page.evaluate((dark) => {
    document.documentElement.classList.toggle('dark', dark);
    const section = document.createElement('section');
    section.className = 'fixed inset-0 z-50 bg-card p-6';
    section.innerHTML = `
      <table id="zebra-table">
        <caption>Zebra stylesheet regression fixture</caption>
        <thead><tr id="zebra-header"><th scope="col">Record</th></tr></thead>
        <tbody>
          <tr id="zebra-odd"><td>First</td></tr>
          <tr id="zebra-even" class="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted"><td>Second</td></tr>
          <tr id="zebra-third"><td>Third</td></tr>
          <tr id="zebra-urgent" class="bg-destructive/5 hover:bg-muted/50"><td>Urgent</td></tr>
        </tbody>
        <tfoot><tr id="zebra-footer"><td>Total</td></tr></tfoot>
      </table>
      <div id="zebra-selected-reference" class="bg-muted">Selected reference</div>
      <div id="zebra-urgent-reference" class="bg-destructive/5">Urgent reference</div>`;
    document.body.append(section);
  }, theme === 'dark');
}

async function background(page: Page, id: string) {
  return page.locator(`#${id}`).evaluate((element) => getComputedStyle(element).backgroundColor);
}

for (const theme of ['light', 'dark'] as const) {
  test(`${theme}: raw and shared-style rows alternate, without striping headers or overriding states`, async ({ page }) => {
    await fixture(page, theme);
    const odd = await background(page, 'zebra-odd');
    const even = await background(page, 'zebra-even');
    expect(even).not.toBe(odd);
    expect(await background(page, 'zebra-third')).toBe(odd);
    expect(await background(page, 'zebra-header')).toBe(odd);
    expect(await background(page, 'zebra-footer')).toBe(odd);
    expect(await background(page, 'zebra-urgent')).toBe(await background(page, 'zebra-urgent-reference'));
    await page.locator('#zebra-even').evaluate((row) => row.setAttribute('data-state', 'selected'));
    await expect.poll(() => background(page, 'zebra-even')).toBe(await background(page, 'zebra-selected-reference'));
  });
}

test.describe('touch row colors', () => {
  test.use({ hasTouch: true, isMobile: true });
  for (const theme of ['light', 'dark'] as const) {
    test(`${theme}: tapping preserves the stripe and selected/status colors`, async ({ page }) => {
      await fixture(page, theme);
      expect(await page.evaluate(() => matchMedia('(pointer: coarse)').matches)).toBe(true);
      const stripe = await background(page, 'zebra-even');
      await page.locator('#zebra-even').tap();
      await expect.poll(() => background(page, 'zebra-even')).toBe(stripe);
      await page.locator('#zebra-even').evaluate((row) => row.setAttribute('data-state', 'selected'));
      await expect.poll(() => background(page, 'zebra-even')).toBe(await background(page, 'zebra-selected-reference'));
      await page.locator('#zebra-urgent').tap();
      await expect.poll(() => background(page, 'zebra-urgent')).toBe(await background(page, 'zebra-urgent-reference'));
    });
  }
});
