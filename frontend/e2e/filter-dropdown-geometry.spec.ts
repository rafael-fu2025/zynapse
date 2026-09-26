/**
 * Filter dropdown geometry — a Select panel must never run past the
 * viewport, and a long option list must scroll inside its own container.
 *
 * Regression guard for the `max-h-[--radix-select-content-available-height]`
 * defect. Tailwind v4 does NOT wrap a bare `--var` in `var()`, so the class
 * compiled to `max-height: --radix-select-content-available-height` — invalid
 * CSS the browser discarded. With no max-height the panel grew to fit its
 * content: the Employees-tab Position filter (245 options) measured 7,850px
 * tall, ran 7,283px past an 800px viewport, and offered no way to scroll.
 *
 * Mocked, so it runs in CI without a backend or live account.
 */
import { expect, test, type Page } from '@playwright/test';
import { signInMocked } from './helpers/auth';

/** A long facet list — the shape that exposed the bug. */
const LONG_POSITIONS = Array.from(
  { length: 245 },
  (_, i) => `Position ${String(i + 1).padStart(3, '0')}`,
);

/** A short facet list — must keep its natural height. */
const SHORT_POSITIONS = ['Dean', 'University Nurse', 'Security and Safety Staff'];

/**
 * The Select ceiling declared in `components/ui/select.tsx` — `20rem`.
 * Measured from the live root font size rather than hard-coded: the root
 * is 125% since the 2026-09-25 readability change, so the same 20rem
 * panel renders 400px, and any future root-size tuning keeps this test
 * honest without edits.
 */
function maxPanelHeightPx(rootFontSize: number): number {
  return 20 * rootFontSize;
}

async function openEmployeesTab(page: Page, positions: string[]): Promise<void> {
  const session = {
    id: 7,
    email: 'clinic.admin@foundationu.edu.ph',
    username: 'synapse-clinic-admin',
    is_active: true,
    force_reset: false,
    permissions: ['clinic.patients.read', 'clinic.patients.write'],
  };
  // mockRefresh covers the cold-load silent refresh triggered by the
  // page.goto below.
  await signInMocked(page, session, { mockRefresh: true });

  const envelope = (data: unknown) =>
    JSON.stringify({ success: true, data, errors: [], meta: null });

  // Register the broad list route first, then the more specific facets
  // route, so the facets handler takes precedence.
  await page.route(/\/api\/v1\/clinic\/employees(\?.*)?$/, (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: [],
        errors: [],
        meta: { pagination: { count: 0, next_cursor: null } },
      }),
    }));

  await page.route('**/api/v1/clinic/employees/facets', (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: envelope({
        departments: ['College of Nursing', 'College of Law & Jurisprudence'],
        positions,
      }),
    }));

  await page.route(/\/api\/v1\/clinic\/students(\?.*)?$/, (route) =>
    route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: [],
        errors: [],
        meta: { pagination: { count: 0, next_cursor: null } },
      }),
    }));

  await page.goto('/patients?tab=employees');

  const trigger = page.getByRole('combobox', { name: /position/i });
  await expect(trigger).toBeEnabled({ timeout: 15_000 });
  await trigger.click();
  await expect(page.locator('[role="option"]').first()).toBeVisible();
}

/** Measure the open Select panel and whatever scrolls inside it. */
async function measurePanel(page: Page) {
  return page.evaluate(() => {
    const wrapper = document.querySelector('[data-radix-popper-content-wrapper]');
    if (wrapper === null) throw new Error('Select panel did not render.');

    const content = wrapper.firstElementChild as HTMLElement;
    const rect = content.getBoundingClientRect();

    const scroller =
      [...content.querySelectorAll<HTMLElement>('*')].find(
        (el) => el.scrollHeight > el.clientHeight + 1,
      ) ?? null;

    return {
      height: Math.round(rect.height),
      bottom: Math.round(rect.bottom),
      viewportHeight: window.innerHeight,
      // The root font size, so rem-based ceilings are measured in the
      // same units the CSS uses (the root is 125% since 2026-09-25).
      rootFontSize: parseFloat(getComputedStyle(document.documentElement).fontSize),
      maxHeight: getComputedStyle(content).maxHeight,
      optionCount: content.querySelectorAll('[role="option"]').length,
      scrollClientHeight: scroller === null ? null : scroller.clientHeight,
      scrollHeight: scroller === null ? null : scroller.scrollHeight,
    };
  });
}

test('a long option list stays inside the viewport and scrolls internally', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await openEmployeesTab(page, LONG_POSITIONS);

  const m = await measurePanel(page);

  expect(m.optionCount).toBe(LONG_POSITIONS.length + 1); // + "All positions"

  // The regression: `max-height` computed to `none`, so the panel was as
  // tall as its content.
  expect(m.maxHeight).not.toBe('none');
  expect(m.height).toBeLessThanOrEqual(maxPanelHeightPx(m.rootFontSize));
  expect(m.bottom).toBeLessThanOrEqual(m.viewportHeight);

  // ...and there must be a real internal scroll container.
  expect(m.scrollHeight).not.toBeNull();
  expect(m.scrollHeight as number).toBeGreaterThan(m.scrollClientHeight as number);
});

test('a long option list is actually scrollable to the end', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await openEmployeesTab(page, LONG_POSITIONS);

  const scrollTop = await page.evaluate(() => {
    const wrapper = document.querySelector('[data-radix-popper-content-wrapper]')!;
    const content = wrapper.firstElementChild as HTMLElement;
    const scroller = [...content.querySelectorAll<HTMLElement>('*')].find(
      (el) => el.scrollHeight > el.clientHeight + 1,
    );
    if (scroller === undefined) throw new Error('No scroll container found.');
    scroller.scrollTop = scroller.scrollHeight;
    return scroller.scrollTop;
  });

  expect(scrollTop).toBeGreaterThan(0);
});

test('a short option list keeps its natural height', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await openEmployeesTab(page, SHORT_POSITIONS);

  const m = await measurePanel(page);

  expect(m.optionCount).toBe(SHORT_POSITIONS.length + 1);
  // Sized to content, not stretched to the ceiling...
  expect(m.height).toBeLessThan(maxPanelHeightPx(m.rootFontSize));
  // ...and no needless scrollbar.
  expect(m.scrollHeight).toBeNull();
});

test('the panel shrinks to fit a short viewport', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 480 });
  await openEmployeesTab(page, LONG_POSITIONS);

  const m = await measurePanel(page);

  // The ceiling is min(available space, 20rem) — a short viewport must
  // lower it rather than overflow.
  expect(m.height).toBeLessThanOrEqual(maxPanelHeightPx(m.rootFontSize));
  expect(m.bottom).toBeLessThanOrEqual(m.viewportHeight);
  expect(m.scrollHeight as number).toBeGreaterThan(m.scrollClientHeight as number);
});
